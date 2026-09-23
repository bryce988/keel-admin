<?php

declare(strict_types=1);

use app\common\support\Env;

/**
 * 队列消费进程
 *
 * 覆盖插件默认值（默认是 8 个进程 + `app/queue/redis` 目录）：
 * - 目录改成 `app/queue`，与 `app/process`、`app/common` 平级，不多套一层
 * - 进程数默认 2 而不是 8：脚手架自带的任务量很小，8 个空转的进程
 *   在 2 核的机器上纯属浪费。真接了重任务再按 CPU 调，
 *   调之前先确认瓶颈是 CPU 还是下游（多半是下游）
 */
return [
    'consumer' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => Env::int('QUEUE_WORKERS', 2),
        'constructor' => [
            'consumer_dir' => app_path() . '/queue',
        ],
    ],
    /*
     * AI 助手的消费进程组（docs/ai-tech.md §7.4）
     *
     * 独立成组是因为一次问答 5~90 秒，混在上面那组里会把导出堵住。
     * count 就是**全站同时进行的问答上限**，超出的在队列里排队（界面上显示「排队中」）。
     * 每个进程常驻约 30MB + 一条 MySQL 连接，调大前算一下 config/process.php 注释里那笔账。
     *
     * ⚠️ 目录是 `app/queue_ai` 而不是 `app/queue/ai`：插件递归扫描 consumer_dir，
     * 放在子目录里会被上面那组也加载，两组进程抢同一个队列。
     */
    'ai-consumer' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => Env::int('AI_WORKERS', 4),
        'constructor' => [
            'consumer_dir' => app_path() . '/queue_ai',
        ],
    ],
];
