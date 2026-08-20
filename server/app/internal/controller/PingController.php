<?php

declare(strict_types=1);

namespace app\internal\controller;

use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * 内部服务存活探测
 *
 * 需要 X-Internal-Token。这组接口**不对外暴露**，
 * nginx 层应直接拒绝来自公网的 /internal/*（PROJECT.md §8.1）。
 */
class PingController
{
    /**
     * 内部服务存活探测
     *
     * `GET /internal/ping` · 需要 `X-Internal-Token`
     *
     * 供同机房的其他服务与健康检查调用。**返回内容刻意贫瘠**——
     * 内部接口一旦被错误地暴露到公网，泄露的信息越少越好，
     * 版本号、依赖状态这些都不放这里。
     *
     * @param Request $request 请求头：`X-Internal-Token` 内部服务令牌
     *
     * @return Response 200，`{pong, app}`
     */
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong' => true,
            'app'  => 'internal',
        ]);
    }
}
