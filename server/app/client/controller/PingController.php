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
    /**
     * C 端存活探测
     *
     * `GET /client/ping` · 免鉴权，但**必须带渠道头**
     *
     * 缺 `X-Channel` 会被 ChannelMiddleware 挡在 400，且错误体不带 `trace_id`——
     * 这两点正是「分端链路确实分开了」的可观测证据。
     *
     * @param Request $request 请求头：`X-Channel` 渠道标识（h5/ios/android 等）、
     *                         `X-App-Version` 客户端版本
     *
     * @return Response 200，`{pong, app, channel, app_version}`
     */
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
