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
];
