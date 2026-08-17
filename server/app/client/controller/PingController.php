<?php

declare(strict_types=1);

namespace app\client\controller;

use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * C 端存活探测
 *
 * 一期 app/client 只有空壳（PROJECT.md §8.8），这个接口的作用是
 * **验证分端链路真的分开了**：它会先过 ChannelMiddleware 与 RateLimitMiddleware，
 * 出错时走 ClientHandler，返回的错误结构与后台不同。
 */
class PingController
{
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong'        => true,
            'app'         => 'client',
            'channel'     => Ctx::get('channel'),
            'app_version' => Ctx::get('app_version'),
        ]);
    }
}
