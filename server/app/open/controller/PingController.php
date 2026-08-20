<?php

declare(strict_types=1);

namespace app\open\controller;

use app\common\support\ClientIp;
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
    /**
     * 开放平台存活探测
     *
     * `GET /open/ping` · **不验签**
     *
     * 供第三方确认两件事：服务可达，以及自己的出口 IP 是不是白名单里那个
     * （返回的 `your_ip` 就是服务端看到的来源 IP）。
     *
     * 返回 `timestamp` 是给第三方校准签名用的——签名带时间戳且有有效窗口，
     * 对方机器时间偏了会一直验签失败，而错误信息里看不出是时间的问题。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`{pong, app, your_ip, timestamp}`
     */
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong'      => true,
            'app'       => 'open',
            'your_ip'   => ClientIp::of($request),
            'timestamp' => time(),   // 第三方据此校准签名用的时间戳
        ]);
    }
}
