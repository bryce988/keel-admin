<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\ProfileService;
use app\common\support\Ctx;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Request;
use support\Response;

/**
 * 个人中心
 *
 * 每个方法的用户 id 都取自 `Ctx::user()`（来源是令牌），
 * **不从请求里读 id**。这是本控制器唯一需要守住的东西：
 * 只要 id 不来自请求，就没有「改别人」的路径可走。
 *
 * 修改密码不在这里而在 AuthController：它改完要吊销当前令牌，
 * 属于会话生命周期的一部分，和资料编辑不是一类事。
 */
class ProfileController
{
    /** GET /admin/profile */
    public function index(Request $request): Response
    {
        return Result::ok(ProfileService::detail(self::uid()));
    }

    /** PUT /admin/profile */
    public function update(Request $request): Response
    {
        return Result::ok(ProfileService::update(self::uid(), [
            'real_name' => $request->post('real_name', ''),
            'email'     => $request->post('email', ''),
            'avatar'    => $request->post('avatar'),
        ]));
    }

    /** PUT /admin/profile/phone */
    public function changePhone(Request $request): Response
    {
        ProfileService::changePhone(
            self::uid(),
            trim((string) $request->post('phone', '')),
            (string) $request->post('password', '')
        );

        return Result::noContent();
    }

    /** GET /admin/profile/logins */
    public function logins(Request $request): Response
    {
        return Paginator::response(
            ProfileService::loginQuery(self::uid()),
            $request,
            ProfileService::LOGIN_SORTABLE,
            'id',
            'desc',
            ProfileService::loginRowMapper()
        );
    }

    private static function uid(): int
    {
        return (int) (Ctx::user()['id'] ?? 0);
    }
}
