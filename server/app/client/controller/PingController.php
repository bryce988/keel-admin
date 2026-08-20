<?php
/**
 * keel admin
 * C 端存活探测
 *
 * 一期 app/client 只有空壳（PROJECT.md §8.8），这个接口的作用是验证分端链路真的分开了：
 * 它会先过 ChannelMiddleware 与 RateLimitMiddleware，出错时走 ClientHandler，
 * 返回的错误结构与后台不同（不带 trace_id）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\client\controller;

use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class PingController
{
    /**
     * C 端存活探测
     * @url GET /client/ping
     * @perm -
     * @description 免鉴权，但必须带渠道头。返回 `{pong, app, channel, app_version}`。
     * 缺 `X-Channel` 会被 ChannelMiddleware 挡在 400，且错误体不带 `trace_id`——
     * 这两点正是「分端链路确实分开了」的可观测证据。
     * @error 400 `30001` 缺少 X-Channel 请求头 · 400 `30002` 不支持的渠道标识
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
