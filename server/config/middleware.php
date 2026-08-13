<?php

declare(strict_types=1);

use app\common\middleware\TraceMiddleware;

/**
 * 中间件管道
 *
 * '' 为全局中间件，先于应用中间件执行。
 * 按端隔离的中间件（鉴权、限流、签名）写在对应的应用键下，
 * 或直接挂在 config/route.php 的路由分组上——本项目采用后者，
 * 因为同一个端里也分公开接口与需登录接口。
 */
return [
    '' => [
        TraceMiddleware::class,     // 最外层：traceId 生成与上下文清理
    ],

    // 'client' => [ ChannelMiddleware::class, ClientAuthMiddleware::class ],
    // 'open'   => [ SignatureMiddleware::class, IpWhitelistMiddleware::class ],
];
