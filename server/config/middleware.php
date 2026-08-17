<?php

declare(strict_types=1);

use app\common\middleware\ChannelMiddleware;
use app\common\middleware\InternalTokenMiddleware;
use app\common\middleware\IpWhitelistMiddleware;
use app\common\middleware\RateLimitMiddleware;
use app\common\middleware\SignatureMiddleware;
use app\common\middleware\TraceMiddleware;

/**
 * 中间件管道
 *
 * '' 为全局中间件，先于应用中间件执行；应用键按 $request->app 匹配，
 * 而 app 是从**控制器命名空间**推出来的——闭包路由拿不到应用中间件，
 * 所以各端的接口必须落在自己的 controller 里。
 *
 * 这里只挂「该端所有请求都要过」的中间件。
 * 鉴权类的挂在 config/route.php 的分组上，因为每个端都有公开接口：
 * 后台的登录与验证码、C 端的短信登录、开放平台的 ping，
 * 一刀切放应用层会把登录接口自己也挡住。
 */
return [
    '' => [
        TraceMiddleware::class,       // 最外层：traceId 生成与上下文清理
    ],

    // 后台的鉴权/审计挂在路由分组上（见 route.php），此处留空
    'admin' => [],

    'client' => [
        ChannelMiddleware::class,     // 渠道、版本、设备号，缺一不可
        RateLimitMiddleware::class,   // 兜底限流，敏感接口另加更严的
    ],

    'open' => [
        IpWhitelistMiddleware::class, // 先看来路，再算签名，省掉无谓的 HMAC 计算
        SignatureMiddleware::class,
    ],

    'internal' => [
        InternalTokenMiddleware::class,
    ],
];
