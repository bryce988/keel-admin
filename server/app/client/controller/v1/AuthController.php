<?php
/**
 * keel admin
 * C 端登录
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\client\controller\v1;

use app\client\service\AuthService;
use app\client\validation\Auth\LoginRequest;
use app\common\support\ClientIp;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class AuthController
{
    /**
     * 登录
     * @url POST /client/v1/auth/login
     * @perm -
     * @description 手机号 + 密码换令牌。免登录，但**渠道头仍然必填**（应用级中间件）。
     * 返回的令牌 `type=client`，调后台接口一律 401。
     * @error 401 `30101` 手机号或密码错误（账号不存在与密码错误不区分）
     * @error 401 `30102` 账号已被封禁
     * @error 401 `30103` 失败次数过多，已临时锁定
     */
    public function login(LoginRequest $request): Response
    {
        $data = $request->validated();

        return Result::ok(AuthService::login(
            (string) $data['phone'],
            (string) $data['password'],
            ClientIp::of($request->request())
        ));
    }

    /**
     * 退出登录
     * @url POST /client/v1/auth/logout
     * @perm 登录即可
     * @description 吊销当前令牌及与它配对的 refresh。重复调用不报错。
     */
    public function logout(Request $request): Response
    {
        AuthService::logout();

        return Result::ok(['message' => '已退出登录']);
    }
}
