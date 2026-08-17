<?php

declare(strict_types=1);

use app\common\exception\AdminHandler;
use app\common\exception\ClientHandler;
use app\common\exception\InternalHandler;
use app\common\exception\OpenHandler;

/**
 * 分端异常处理器（PROJECT.md §8.3）
 *
 * 四个端的错误体结构刻意不同——这不是风格问题，是受众不同：
 *   admin    { code, message, trace_id, details? }  同事在用，要字段级明细与 traceId
 *   client   { code, message }                      终端用户在用，只给一句人话
 *   open     { error_code, error_message, request_id } 第三方在用，字符串码更稳定
 *   internal { code, message, trace_id, details? }  自己的服务在用，信息给足
 *
 * '' 是兜底：闭包路由与未匹配到应用的请求走这里。
 */
return [
    ''         => AdminHandler::class,
    'admin'    => AdminHandler::class,
    'client'   => ClientHandler::class,
    'open'     => OpenHandler::class,
    'internal' => InternalHandler::class,
];
