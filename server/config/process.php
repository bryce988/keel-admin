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
use app\process\Http;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        /*
         * 进程数：核数 × 4
         *
         * 业务是「查库 → 拼 JSON」的阻塞 I/O 型，进程多于核数才能在等 MySQL 时
         * 让出 CPU；4 倍是 webman 对这类负载的常用起点。
         *
         * **内存是硬约束**：M4 压测实测每个 worker 稳态约 26MB（冷启动 17.6MB，
         * 30 秒预热到 26MB 后进入平台期，30 分钟不再上涨）。所以
         *     常驻内存 ≈ count × 26MB + task 16MB + consumer 16MB × QUEUE_WORKERS
         * 2 核的机器 → 8 个 worker ≈ 208MB，很宽裕；调大 count 前先按这个式子算一遍，
         * 别在 1G 内存的机器上照抄 16 核的配置。
         *
         * 没有把它「调优」成某个具体数字：压测只跑了 4 并发，远没打满 40 个 worker，
         * 那种数据推不出最优值。真要定值得先有目标 QPS 与 P99 延迟，
         * 再从 count=核数 起逐档压到延迟拐点——那是接了真实业务之后的事。
         */
        'count' => cpu_count() * 4,
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
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
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
     * 队列消费进程**不在这里**注册——它由 `webman/redis-queue` 插件提供，
     * 配置在 `config/plugin/webman/redis-queue/process.php`。
     */
    'task' => [
        'handler'    => app\process\TaskProcess::class,
        'count'      => 1,
        'reloadable' => true,
    ],
];
