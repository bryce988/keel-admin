<?php

declare(strict_types=1);

namespace app\client\controller\v1;

use app\common\support\Ctx;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

/**
 * C 端个人信息（空壳）
 *
 * 存在的意义是验收「员工 token 调 C 端接口返回 401」这一条（PROJECT.md §15）——
 * 需要一个挂了 ClientAuthMiddleware 的真实接口才能验。
 *
 * ⚠️ 二期接 app_users 表后在这里返回真实资料。
 * C 端响应**不得**返回 dept_id、成本、内部备注这类字段（§8.5 响应裁剪）。
 */
class ProfileController
{
    public function index(Request $request): Response
    {
        $user = Ctx::get('client_user') ?? [];

        return Result::ok([
            'user_id' => $user['id'] ?? 0,
            'channel' => Ctx::get('channel'),
            'note'    => 'C 端业务接口在二期实现，当前仅验证鉴权链路',
        ]);
    }
}
