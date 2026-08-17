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
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong' => true,
            'app'  => 'internal',
        ]);
    }
}
