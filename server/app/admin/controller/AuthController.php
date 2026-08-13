<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\UnauthorizedException;
use app\common\exception\ValidationException;
use app\common\service\AuthService;
use app\common\service\CaptchaService;
use app\common\service\JwtService;
use app\common\support\Ctx;
use app\common\support\Result;
use support\Request;
use support\Response;

/**
 * 管理后台认证
 *
 * 控制器只做参数编排与响应，业务规则在 service 层。
 */
class AuthController
{
    /** GET /admin/auth/captcha */
    public function captcha(Request $request): Response
    {
        return Result::ok(CaptchaService::generate());
    }

    /** POST /admin/auth/login */
    public function login(Request $request): Response
    {
        $username    = trim((string) $request->post('username', ''));
        $password    = (string) $request->post('password', '');
        $captchaKey  = (string) $request->post('captchaKey', '');
        $captchaCode = (string) $request->post('captchaCode', '');

        $errors = [];
        if ($username === '')    { $errors['username'] = ['请输入账号']; }
        if ($password === '')    { $errors['password'] = ['请输入密码']; }
        if ($captchaCode === '') { $errors['captchaCode'] = ['请输入验证码']; }
        if ($errors) {
            throw new ValidationException($errors);
        }

        if (!CaptchaService::verify($captchaKey, $captchaCode)) {
            throw new ValidationException(['captchaCode' => ['验证码错误或已过期']]);
        }

        $tokens = AuthService::login(
            $username,
            $password,
            $request->getRealIp(),
            (string) $request->header('user-agent', '')
        );

        return Result::ok($tokens);
    }

    /** GET /admin/auth/profile —— 登录后第一个请求，前端据此渲染菜单与按钮权限 */
    public function profile(Request $request): Response
    {
        return Result::ok(AuthService::profile(Ctx::user() ?? []));
    }

    /** POST /admin/auth/logout */
    public function logout(Request $request): Response
    {
        $user = Ctx::user() ?? [];
        JwtService::revoke((string) Ctx::get('jti', ''));

        if ($user) {
            AuthService::writeLoginLog(
                (int) $user['id'],
                $user['username'],
                $request->getRealIp(),
                (string) $request->header('user-agent', ''),
                true,
                '',
                2
            );
        }

        return Result::noContent();
    }

    /** POST /admin/auth/refresh */
    public function refresh(Request $request): Response
    {
        $refreshToken = (string) $request->post('refreshToken', '');
        if ($refreshToken === '') {
            throw new ValidationException(['refreshToken' => ['缺少刷新凭证']]);
        }

        $payload = JwtService::decode($refreshToken);
        if (($payload['scope'] ?? '') !== 'refresh') {
            throw new UnauthorizedException('凭证类型错误', 10101);
        }

        $user = AuthService::loadUser((int) ($payload['uid'] ?? 0));

        return Result::ok(JwtService::issue((int) $user['id'], (int) $user['perm_version']));
    }

    /** PUT /admin/profile/password */
    public function changePassword(Request $request): Response
    {
        $old = (string) $request->post('oldPassword', '');
        $new = (string) $request->post('newPassword', '');

        AuthService::changePassword(Ctx::user() ?? [], $old, $new);
        JwtService::revoke((string) Ctx::get('jti', ''));   // 改密后当前 token 失效

        return Result::noContent();
    }
}
