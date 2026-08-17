<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * 日志通道
 *
 * 拆通道的理由很实际：出事时想看的是异常，而异常淹没在业务日志里根本翻不到。
 * 分开之后 `tail -f runtime/logs/error-*.log` 就是全部的线上报警来源。
 *
 *   default    业务日志，Log::info() 默认写这里，留 14 天
 *   error      未捕获异常，只收 ERROR 以上，留 30 天（出事要往回查得久一点）
 *   sql        慢查询，留 7 天（只用于当下排查，不需要长期留存）
 *
 * 用法：Log::channel('sql')->warning(...)，不传 channel 即 default。
 *
 * ⚠️ 容器化部署时这些文件在容器内，`docker compose down` 就没了。
 * 上量之后应改为输出到 stdout 由日志采集器收走，或把 runtime/logs 挂出来。
 */

$formatter = [
    'class'       => LineFormatter::class,
    'constructor' => [null, 'Y-m-d H:i:s', true, true],
];

return [
    'default' => [
        'handlers' => [
            [
                'class'       => RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/app.log', 14, Level::Debug],
                'formatter'   => $formatter,
            ],
        ],
    ],

    'error' => [
        'handlers' => [
            [
                'class'       => RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/error.log', 30, Level::Error],
                'formatter'   => $formatter,
            ],
        ],
    ],

    'sql' => [
        'handlers' => [
            [
                'class'       => RotatingFileHandler::class,
                'constructor' => [runtime_path() . '/logs/sql.log', 7, Level::Debug],
                'formatter'   => $formatter,
            ],
        ],
    ],
];
