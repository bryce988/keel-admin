<?php
/**
 * keel admin
 * 员工移动端登录
 *
 * 身份与后台**完全同一套**：同一张 sys_users、同一个验证码、同一套失败计数与锁定、
 * 签发的也是同一种令牌（type=admin）。所以这里一行认证逻辑都没有，
 * 全部走 {@see AuthService}——「一个业务规则只有一份实现」（PROJECT.md §8.2）。
 *
 * 这个控制器只做一件后台那边不做的事：**把登录与身份合并成一次响应**。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\staff\controller\v1;

use app\common\service\AuthService;
use app\common\service\CaptchaService;
use app\common\service\JwtService;
use app\common\support\Ctx;
use app\common\support\Result;
use app\staff\support\StaffPresenter;
use app\staff\validation\Auth\LoginRequest;
use app\common\exception\ValidationException;
use app\common\support\ClientIp;
use support\Response;
use Webman\Http\Request;

class AuthController
{
    /**
     * 图形验证码
     * @url GET /staff/v1/auth/captcha
     * @perm -
     * @description 免登录。与后台同一套：答案存 Redis 带 TTL，**验过即焚**——
     * 所以客户端每次登录失败都要重新取一张，否则用户拿着已作废的码重试，
     * 密码明明改对了却一直看到「验证码错误」。
     */
    public function captcha(Request $request): Response
    {
        return Result::ok(CaptchaService::generate());
    }

    /**
     * 登录
     * @url POST /staff/v1/auth/login
     * @perm -
     * @description 账号 + 密码 + 图形验证码。**一次返回令牌与身份**（后台那边是两个接口）：
     * App 启动在弱网下每多一次往返就多一次转圈，而令牌与身份对客户端来说是同一件事的两半——
     * 没有身份的令牌它也用不了。
     * @error 401 `20001` 账号或密码错误 · `20002` 账号已停用 · `20003` 已锁定
     * @error 422 `10422` 参数校验失败（含验证码错误）
     */
    public function login(LoginRequest $request): Response
    {
        $data = $request->validated();

        if (!CaptchaService::verify((string) $data['captcha_key'], (string) $data['captcha_code'])) {
            throw new ValidationException(['captcha_code' => ['验证码错误或已过期']]);
        }

        $raw = $request->request();
        $tokens = AuthService::login(
            (string) $data['username'],
            (string) $data['password'],
            ClientIp::of($raw),
            (string) $raw->header('user-agent', '')
        );

        // 登录成功即可读取身份：此时还没过鉴权中间件，用 loadUser 自己取一次
        $user = AuthService::loadUser((int) JwtService::decode($tokens['access_token'])['uid']);

        return Result::ok($tokens + StaffPresenter::identity($user));
    }

    /**
     * 退出登录
     * @url POST /staff/v1/auth/logout
     * @perm 登录即可
     * @description 吊销当前令牌及与它配对的 refresh。只吊销 access 的话，
     * 泄露的 refresh 在剩余寿命内还能换出可用的新令牌。
     */
    public function logout(Request $request): Response
    {
        JwtService::revokePair(
            (string) Ctx::get('jti', ''),
            JwtService::remaining((array) Ctx::get('jwt_payload', []))
        );

        return Result::ok(['message' => '已退出登录']);
    }
}
