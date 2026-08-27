<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use support\Log;
use support\Request;
use app\common\support\Env;
use app\process\Http;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        /*
         * 进程数：max(8, 核数 × 2)，可用 WORKER_COUNT 覆盖
         *
         * 业务是「查库 → 拼 JSON」的阻塞 I/O 型：一个 worker 同时只能服务一个请求，
         * 等 MySQL 的那段时间 CPU 是闲着的，所以进程要多于核数。问题只是多几倍。
         *
         * ## 实测（10 核 / 16G Mac，Docker，/admin/users 列表，并发 100，三次取中位）
         *
         *     倍数   worker   RPS    内存
         *     ×1     10       3576   279MB
         *     ×2     20       4119   553MB     ← 拐点
         *     ×4     40       4081   1086MB    比 ×2 多一倍内存，吞吐持平
         *     ×8     80       3786   2141MB    开始倒退
         *
         * 拐点在 ×2：再加进程只是让请求在更多队列里排队，总吞吐不变而上下文切换变多。
         * 每 worker 稳态 27MB，与 M4 压测记的 26MB 一致。
         *
         * ⚠️ 压测必须从**容器网络内部**打（`scripts/bench-workers.sh` 就是这么做的）。
         * 在 macOS 上经宿主的 `localhost:8787` 打，Docker Desktop 的端口转发会先饱和
         * ——实测 1874 vs 3406 RPS，差 1.8 倍，四档 worker 数会全部压出一样的数字，
         * 看着像「加进程没用」，其实量的是 Docker。
         *
         * ## 为什么保底 8 而不是直接 ×2
         *
         * 上面测的是 5~25ms 的快接口。导出、导入这类接口会把 worker 占住几秒，
         * 而阻塞模型下 N 个 worker 最多同时服务 N 个请求——第 N+1 个只能等。
         * 核数少的机器上 ×2 太薄：2 核 → 4 个 worker，四个人同时点导出就把后台冻住了。
         * 保底 8 个（8 × 27MB ≈ 216MB，对 2 核 3.6G 的生产机毫无压力）留出这层余量。
         *
         * 这条保底是**推理不是实测**：演示库只有 4 个用户，导出 36ms，压不出真实阻塞。
         * 接了真实业务、有了慢接口的耗时分布之后应当复测。
         *
         * 于是倍数随机器变大而收缩，两头都不亏：
         *     2 核  → max(8, 4)  = 8   （216MB，与此前的 ×4 相同，生产机行为不变）
         *     4 核  → max(8, 8)  = 8
         *     10 核 → max(8, 20) = 20  （553MB，此前 ×4 要 1086MB 且吞吐并不更高）
         *     16 核 → max(8, 32) = 32
         *
         * ## 两条硬约束，调大前先算
         *
         * 1. 内存：常驻 ≈ count × 27MB + task 16MB + consumer 16MB × QUEUE_WORKERS
         * 2. MySQL 连接：每个 worker 常驻一条连接（首次查库时才建，所以刚重启时看不到）。
         *    默认 max_connections=151，也就是 worker 总数的天花板在 ~145
         *    （还要给 task、队列、运维留出余量）。实测 80 worker → 81 条连接
         */
        'count' => Env::int('WORKER_COUNT', 0) ?: max(8, cpu_count() * 2),
        'user' => '',
        'group' => '',
        /*
         * 每个 worker 各自持有监听套接字（SO_REUSEPORT）
         *
         * M4 实测：false 时 reload 瞬间会有极低概率的连接重置（~1.2e-4），
         * 改 true 后同样的测试降到 2.1e-5 直至 0。样本量不足以断言「已消除」，
         * 但未观察到任何副作用。需要 Linux 3.9+，生产是 Debian 12。
         */
        'reusePort' => true,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                /*
                 * 文件监听：**生产必须关**
                 *
                 * 上游默认只按 `-d`（守护进程）判断，而容器里不能用 -d——
                 * 主进程一 daemon 化，容器就退出了。于是生产实际跑的是前台模式，
                 * 上游的判断放行，文件监听就一直开着：每 2 秒扫一遍 app/ config/ support/，
                 * 并且**任何文件改动都会自动 reload 线上进程**。
                 * 代码是 bind mount 进去的，`git pull` 到一半就可能触发一次半新半旧的 reload。
                 *
                 * 所以这里额外用 APP_ENV 兜一道。内存监听保留：
                 * 它负责把超过 memory_limit 的 worker 重启掉，生产比开发更需要。
                 */
                'enable_file_monitor' => !in_array('-d', $argv)
                    && DIRECTORY_SEPARATOR === '/'
                    && !Env::isProd(),
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],

    /**
     * 定时任务（PROJECT.md §14.7）
     *
     * `count => 1` 是硬性的，不是调优余地：多进程会让同一个任务在同一时刻
     * 被触发 N 次。任务本身只负责投递，耗时的活在队列消费进程里干。
     *
     * 队列消费进程不在这里注册——它由 `webman/redis-queue` 插件提供，
     * 配置在 `config/plugin/webman/redis-queue/process.php`。
     */
    'task' => [
        'handler'    => app\process\TaskProcess::class,
        'count'      => 1,
        'reloadable' => true,
    ],
];
