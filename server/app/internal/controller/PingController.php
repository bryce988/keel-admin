<?php
/**
 * keel admin
 * 内部服务存活探测
 *
 * 需要 `X-Internal-Token`。这组接口不对外暴露，
 * nginx 层应直接拒绝来自公网的 /internal/*（PROJECT.md §8.1）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\internal\controller;

use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class PingController
{
    /**
     * 内部服务存活探测
     * @url GET /internal/ping
     * @perm -
     * @description 需要 `X-Internal-Token`，供同机房的其他服务与健康检查调用。
     * 返回内容刻意贫瘠（只有 `{pong, app}`）——内部接口一旦被错误地暴露到公网，
     * 泄露的信息越少越好，版本号、依赖状态这些都不放这里。
     * @error 401 `10101` 令牌无效或未配置
     */
    public function index(Request $request): Response
    {
        return Result::ok([
            'pong' => true,
            'app'  => 'internal',
        ]);
    }
}
