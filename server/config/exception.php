<?php

declare(strict_types=1);

use app\common\exception\Handler;

/**
 * 异常处理器
 *
 * 每个应用可以配置不同的处理器：
 * 管理后台与 C 端返回统一的业务错误结构，
 * 开放平台（open）后续接入时换成按 REST 规范返回的处理器。
 */
return [
    ''       => Handler::class,
    'admin'  => Handler::class,
    'client' => Handler::class,
];
