<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\exception\UnauthorizedException;
use app\common\exception\ValidationException;
use app\common\service\AuthService;
use app\common\service\CaptchaService;
use app\common\service\JwtService;
use app\common\support\ClientIp;
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
    /**
     * 获取图形验证码
     *
     * `GET /admin/auth/captcha` · **免登录**（在鉴权中间件的白名单里）
     *
     * 返回一个一次性的 `captcha_key` 与 base64 图片。答案存在 Redis 里带 TTL，
     * 校验成功即删——同一个 key 不能复用，否则打码平台可以拿一次结果刷登录。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`{captcha_key, captcha_image}`（后者是 `data:image/...` 的 base64）
     */
    public function captcha(Request $request): Response
    {
        return Result::ok(CaptchaService::generate());
    }

    /**
     * 登录
     *
     * `POST /admin/auth/login` · **免登录**
     *
     * 顺序是先校验验证码再校验密码——反过来的话，验证码就拦不住撞库，
     * 攻击者可以用错误的验证码试出「账号密码对不对」。
     *
     * 账号不存在与密码错误**返回同一个错误**（`20001`），不给枚举账号的机会。
     * 连续失败按「账号 + IP」计数并锁定，另有一道按 IP 的失败总闸（跨账号）。
     * 只按账号锁会变成 DoS——谁都能拿 5 次错密码把指定账号锁死，详见 AuthService::login。
     *
     * ⚠️ 阈值与时长读的是**环境变量** `LOGIN_FAIL_LIMIT` / `LOGIN_IP_FAIL_LIMIT` /
     * `LOGIN_LOCK_MINUTES`。seed 里那两个同名系统参数（`sys.login.*`）**目前是死配置**，
     * 界面上改了不生效——这是既有缺陷，别照着参数页去调。
     *
     * @param Request $request 请求体：`username` 账号（必填）、`password` 密码（必填）、
     *                         `captcha_key` 验证码标识、`captcha_code` 验证码（必填）
     *
     * @return Response 200，`{access_token, refresh_token, expires_in, must_change_password}`
     *
     * @throws \app\common\exception\ValidationException  字段为空，或验证码错误/过期（422 + `10422`）
     * @throws \app\common\exception\UnauthorizedException 账号或密码错误（401 + `20001`）、
     *                                                        账号已停用（`20002`）、已锁定（`20003`）、
     *                                                        密码已过期（`20007`）
     */
    public function login(Request $request): Response
    {
        $username    = trim((string) $request->post('username', ''));
        $password    = (string) $request->post('password', '');
        $captchaKey  = (string) $request->post('captcha_key', '');
        $captchaCode = (string) $request->post('captcha_code', '');

        $errors = [];
        if ($username === '')    { $errors['username'] = ['请输入账号']; }
        if ($password === '')    { $errors['password'] = ['请输入密码']; }
        if ($captchaCode === '') { $errors['captcha_code'] = ['请输入验证码']; }
        if ($errors) {
            throw new ValidationException($errors);
        }

        if (!CaptchaService::verify($captchaKey, $captchaCode)) {
            throw new ValidationException(['captcha_code' => ['验证码错误或已过期']]);
        }

        $tokens = AuthService::login(
            $username,
            $password,
            ClientIp::of($request),
            (string) $request->header('user-agent', '')
        );

        return Result::ok($tokens);
    }

    /**
     * 当前用户的身份、权限与菜单
     *
     * `GET /admin/auth/profile` · **登录即可**
     *
     * 登录后的第一个请求，前端据此渲染侧边栏、注册动态路由、决定按钮的显隐。
     * `menus` 只含 `type IN (1,2)` 且当前用户有权的节点，按钮权限在 `permissions` 数组里；
     * 超级管理员的 `permissions` 直接返回 `["*"]`。
     *
     * ⚠️ 前端拿它做的一切都只是**界面收敛**，不是安全边界——
     * 真正的拦截在每条路由的 `perm` 声明上。
     *
     * @param Request $request 无参数
     *
     * @return Response 200，`{user, roles, permissions, data_scope, menus}`
     */
    public function profile(Request $request): Response
    {
        return Result::ok(AuthService::profile(Ctx::user() ?? []));
    }

    /**
     * 登出
     *
     * `POST /admin/auth/logout` · **登录即可**
     *
     * 把当前令牌的 `jti` 加入吊销名单——JWT 本身是无状态的，不吊销的话
     * 「登出」只是前端删了个字符串，令牌在有效期内仍然能用。
     * 同时补一条 type=2 的登出记录，登录日志才是完整的会话轨迹。
     *
     * @param Request $request 无请求体，身份取自令牌
     *
     * @return Response 204，无响应体
     */
    public function logout(Request $request): Response
    {
        $user = Ctx::user() ?? [];

        // 连同签发时与它配对的 refresh 一起作废。只吊销 access 的话，
        // 泄露的 refresh 在剩余寿命内还能不断换出可用的新令牌
        JwtService::revokePair(
            (string) Ctx::get('jti', ''),
            JwtService::remaining((array) Ctx::get('jwt_payload', []))
        );

        if ($user) {
            AuthService::writeLoginLog(
                (int) $user['id'],
                $user['username'],
                ClientIp::of($request),
                (string) $request->header('user-agent', ''),
                true,
                '',
                2
            );
        }

        return Result::noContent();
    }

    /**
     * 用刷新凭证换新的访问令牌
     *
     * `POST /admin/auth/refresh` · **免登录**（access_token 过期时才会调它）
     *
     * 会检查载荷里的 `scope` 必须是 `refresh`：不检查的话，
     * 一个普通的 access_token 也能拿来换新令牌，等于访问令牌永不过期。
     *
     * 新令牌带的是**当前**的 `perm_version`，所以权限变更后刷新一次即刻生效。
     *
     * @param Request $request 请求体：`refresh_token` 登录时下发的刷新凭证
     *
     * @return Response 200，`{access_token, refresh_token, expires_in}`
     *
     * @throws \app\common\exception\ValidationException   缺少 `refresh_token`（422 + `10422`）
     * @throws \app\common\exception\UnauthorizedException 凭证类型错误、已过期或已吊销（401 + `10101`）
     */
    public function refresh(Request $request): Response
    {
        $refreshToken = (string) $request->post('refresh_token', '');
        if ($refreshToken === '') {
            throw new ValidationException(['refresh_token' => ['缺少刷新凭证']]);
        }

        $payload = JwtService::decode($refreshToken);
        if (($payload['scope'] ?? '') !== 'refresh') {
            throw new UnauthorizedException('凭证类型错误', 10101);
        }

        // 登出时这个 jti 已经被拉黑（见 logout 里的 revokePair）。
        // 少了这一步，登出之后拿旧 refresh 照样能换出可用的 access token
        if (JwtService::isRevoked((string) ($payload['jti'] ?? ''))) {
            throw new UnauthorizedException('登录已失效，请重新登录');
        }

        $user = AuthService::loadUser((int) ($payload['uid'] ?? 0));

        // 改密或管理员重置密码后 token_version 会递增，此处比对使旧 refresh 立即作废
        if ((int) ($payload['tv'] ?? 0) !== (int) ($user['token_version'] ?? 0)) {
            throw new UnauthorizedException('密码已变更，请重新登录', 10103);
        }

        // 轮换：旧的 refresh 用过即废，按它自己的剩余寿命拉黑。
        // 除了限制泄露窗口，还顺带得到重放检测——同一个 refresh 用第二次直接 401
        JwtService::revokePayload($payload);

        return Result::ok(JwtService::issue(
            (int) $user['id'],
            (int) $user['perm_version'],
            (int) $user['token_version']
        ));
    }

    /**
     * 修改密码
     *
     * `PUT /admin/profile/password` · **登录即可** · 自动落操作日志
     *
     * 放在认证控制器而不是个人中心：它改完要**吊销当前令牌**，
     * 属于会话生命周期的一部分，和改姓名头像不是一类事。
     * 调用方拿到 204 之后必须重新登录。
     *
     * 新密码的强度由系统参数控制（长度、复杂度），不满足直接拒。
     *
     * @param Request $request 请求体：`old_password` 原密码、`new_password` 新密码
     *
     * @return Response 204，无响应体；**当前令牌随即失效**
     *
     * @throws \app\common\exception\BusinessException   原密码错误（400 + `20005`）
     * @throws \app\common\exception\ValidationException 新密码不符合安全策略（422 + `20006`）
     */
    public function changePassword(Request $request): Response
    {
        $old = (string) $request->post('old_password', '');
        $new = (string) $request->post('new_password', '');

        AuthService::changePassword(Ctx::user() ?? [], $old, $new);

        // 全端失效由 service 里递增的 token_version 负责；这里再把当前这一对拉黑，
        // 是为了不依赖「下一个请求会去比对 tv」这一件事——两条路径都堵上
        JwtService::revokePair(
            (string) Ctx::get('jti', ''),
            JwtService::remaining((array) Ctx::get('jwt_payload', []))
        );

        return Result::noContent();
    }
}
