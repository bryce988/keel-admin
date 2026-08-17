<?php

declare(strict_types=1);

namespace app\open\controller;

use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 开放平台存活探测
 *
 * 不验签，供第三方确认服务可达与自己的出口 IP 是否在白名单内。
 * 需要验签的示例在 EchoController。
 */
class PingController
{
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong'      => true,
            'app'       => 'open',
            'your_ip'   => $request->getRealIp(),
            'timestamp' => time(),   // 第三方据此校准签名用的时间戳
        ]);
    }
}
