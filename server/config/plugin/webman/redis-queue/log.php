<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * 队列插件的日志通道
 *
 * ⚠️ 这个文件也是必需的：消费进程启动时会
 * `Log::channel('plugin.webman.redis-queue.default')`，配置里没有这个通道
 * 就抛 `Undefined array key`，进程随即以 status 64000 退出。
 * 坑在于启动列表里那一行仍然显示 `[OK]`——那是拉起瞬间的状态，
 * 表面上进程数对得上，实际它在崩溃-重启的循环里，消息就是没人消费。
 *
 * 与主应用的 `config/log.php` 保持同一套格式与轮转策略，
 * 但单独一个文件：队列的重试与失败信息量大且格式固定，
 * 混进 app.log 会把业务日志冲得看不清。留 7 天，够定位一次故障即可。
 */
return [
    'default' => [
        'handlers' => [
            [
                'class'       => RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/queue.log', 7, Level::Debug],
                'formatter'   => [
                    'class'       => LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true, true],
                ],
            ],
        ],
    ],
];
