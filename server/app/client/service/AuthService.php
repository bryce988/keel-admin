<?php
/**
 * keel admin
 * C 端登录
 *
 * 与后台的 {@see \app\common\service\AuthService} 是两套东西，别互相抄：
 * 那边有验证码、权限树、密码有效期、登录日志表；这边只有「手机号 + 密码 → 令牌」。
 * C 端没有 RBAC，令牌里的 pv（权限版本号）恒为 0。
 *
 * ## 为什么不写 sys_login_logs
 *
 * 那张表是**员工**的登录审计，字段（部门、浏览器、操作系统）与后台的查询界面
 * 都是按员工设计的。把 C 端用户混进去，「登录日志」这个页面会同时列出两种
 * 身份的人，而它们的 id 分属两张表——一个 user_id=3 在两套体系里指向不同的人。
 * C 端要做登录审计应该单起一张表，那属于业务，不进脚手架。
 *
 * ## 防爆破
 *
 * 「凭证 + IP」计失败次数，超限锁一段时间，与后台同一个思路但独立的键空间。
 * 锁的是「这个手机号从这个 IP 登」而不是「这个手机号」——否则知道你手机号的人
 * 随便输几次错密码就能把你锁在门外。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\client\service;

use app\common\constant\BizCode;
use app\common\exception\UnauthorizedException;
use app\common\model\AppUserModel;
use app\common\service\JwtService;
use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Env;

class AuthService
{
    /** 同一「手机号 + IP」允许的连续失败次数 */
    private const FAIL_LIMIT = 5;

    /** 触发锁定后的锁定时长（秒） */
    private const LOCK_TTL = 900;

    /**
     * 登录
     *
     * 账号不存在与密码错误返回**同一句话、同一个码**：分开说等于提供了一个
     * 「这个手机号有没有注册过」的查询接口，能被用来批量筛选真实用户。
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int, user:array}
     */
    public static function login(string $phone, string $password, string $ip): array
    {
        $scope   = md5($phone . '|' . $ip);
        $lockKey = "client:login:lock:{$scope}";
        $failKey = "client:login:fail:{$scope}";

        if (Cache::exists($lockKey)) {
            $minutes = (int) ceil(Cache::ttl($lockKey) / 60);

            throw new UnauthorizedException("登录失败次数过多，请 {$minutes} 分钟后重试", BizCode::APP_LOGIN_LOCKED);
        }

        $user = AppUserModel::query()->where('phone', $phone)->first();

        if ($user === null || !password_verify($password, (string) $user->password)) {
            $fails = Cache::incr($failKey, self::LOCK_TTL);
            if ($fails >= self::FAIL_LIMIT) {
                Cache::set($lockKey, '1', self::LOCK_TTL);
                Cache::del($failKey);
            }

            throw new UnauthorizedException('手机号或密码错误', BizCode::APP_ACCOUNT_OR_PASSWORD_ERROR);
        }

        if ((int) $user->status === AppUserModel::STATUS_DISABLED) {
            throw new UnauthorizedException('账号已被封禁', BizCode::APP_ACCOUNT_DISABLED);
        }

        // 登录成功清计数：只清「这个凭证 + 这个 IP」的，不动别的
        Cache::del($failKey);

        $token = JwtService::issue((int) $user->id, 0, (int) $user->token_version, 'client');

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $ip;
        $user->save();

        return $token + ['user' => self::publicUser($user)];
    }

    /**
     * 退出登录
     *
     * 连同配对的 refresh 一起吊销：只吊销 access 的话，泄露的 refresh
     * 在剩余寿命内还能换出可用的新令牌。
     */
    public static function logout(): void
    {
        JwtService::revokePair(
            (string) Ctx::get('jti', ''),
            JwtService::remaining((array) Ctx::get('jwt_payload', []))
        );
    }

    /**
     * C 端能看到的用户字段
     *
     * 白名单而不是黑名单：将来给 app_users 加内部字段（风控分、渠道来源、
     * 运营备注）时，忘了往黑名单里补一笔就直接下发出去了。
     * 响应裁剪的原则见 PROJECT.md §8.5。
     */
    public static function publicUser(AppUserModel $user): array
    {
        return [
            'id'       => (int) $user->id,
            'phone'    => self::maskPhone((string) $user->phone),
            'nickname' => (string) $user->nickname,
            'avatar'   => self::absoluteUrl((string) $user->avatar),
        ];
    }

    /** 中间四位打码。用户自己也不需要在「我的」页面看到完整号码 */
    private static function maskPhone(string $phone): string
    {
        return strlen($phone) < 7 ? $phone : substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    /**
     * 头像补成绝对地址
     *
     * 库里存的是 `/uploads/avatar/...`，浏览器能靠当前域名补全，App 不能——
     * 它的「当前域名」是本地文件系统。基址取 `APP_URL`，没配就原样返回
     * （本地开发时前端自己拼）。
     */
    public static function absoluteUrl(string $path): string
    {
        if ($path === '' || str_starts_with($path, 'http')) {
            return $path;
        }

        $base = rtrim(Env::get('APP_URL', ''), '/');

        return $base === '' ? $path : $base . $path;
    }
}
