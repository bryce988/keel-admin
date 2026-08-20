<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\UnauthorizedException;
use app\common\support\Cache;
use app\common\support\Env;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT 签发与校验
 *
 * 设计要点（见 PROJECT.md §7.5）：
 * - token 内只放 uid / type / 权限版本号 / 会话版本号，**不放权限列表**
 *   否则授权变更后旧 token 里的权限会一直有效到过期
 * - 登出把 jti 写进 Redis 黑名单，有效期取该 token 的**剩余寿命**
 *
 * ## 两个版本号的分工（别混用）
 *
 * - `pv`（perm_version）——授权变更时递增，只用来让权限缓存失效。
 *   鉴权中间件**故意不比对它**：比了的话管理员每改一次角色就把在线用户全踢下线。
 * - `tv`（token_version）——改密、管理员重置密码时递增，鉴权与刷新**都要比对**。
 *   这才是「强制下线」的开关。
 *
 * ## 为什么需要 tv：光靠 jti 黑名单堵不住
 *
 * 登出时手上只有 access token，refresh 的 jti 是另一个值、且从不落库，
 * 所以「把这个人所有会话作废」用黑名单表达不了。曾经的后果是：
 * 改密/登出之后，泄露的 refresh token 在 7 天内仍能不断换出可用的 access token，
 * 「改密踢下线」形同虚设（实测 PoC：登出后 access 401，但 refresh 换新照样 200）。
 */
class JwtService
{
    private const ALG = 'HS256';

    /**
     * 签发一对令牌
     *
     * 同时在 Redis 里记下 `access_jti → refresh_jti` 的配对：登出时手上只有
     * access token，没有这条记录就找不到该作废哪个 refresh token
     * （只吊销 access 的话，refresh 在剩余寿命内还能换出新的来）。
     *
     * @param int    $uid          用户 ID
     * @param int    $permVersion  权限版本号，写进载荷供缓存失效用
     * @param int    $tokenVersion 会话版本号，鉴权与刷新时都会比对
     * @param string $type         令牌类型，`admin` 与 `client` 互不通用
     *
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     */
    public static function issue(int $uid, int $permVersion, int $tokenVersion = 0, string $type = 'admin'): array
    {
        $now        = time();
        $accessTtl  = Env::int('JWT_ACCESS_TTL', 7200);
        $refreshTtl = Env::int('JWT_REFRESH_TTL', 604800);

        $accessJti  = bin2hex(random_bytes(8));
        $refreshJti = bin2hex(random_bytes(8));

        $access = self::encode([
            'uid'  => $uid,
            'type' => $type,
            'pv'   => $permVersion,
            'tv'   => $tokenVersion,
            'jti'  => $accessJti,
            'iat'  => $now,
            'exp'  => $now + $accessTtl,
        ]);

        $refresh = self::encode([
            'uid'  => $uid,
            'type' => $type,
            'pv'   => $permVersion,
            'tv'   => $tokenVersion,
            'jti'  => $refreshJti,
            'scope'=> 'refresh',
            'iat'  => $now,
            'exp'  => $now + $refreshTtl,
        ]);

        // 配对活得和 refresh 一样久：access 早就过期了，人还可能拿 refresh 来换
        Cache::set(self::pairKey($accessJti), $refreshJti, $refreshTtl);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'expires_in'    => $accessTtl,
        ];
    }

    public static function encode(array $payload): string
    {
        return JWT::encode($payload, self::secret(), self::ALG);
    }

    public static function decode(string $token): array
    {
        try {
            return (array) JWT::decode($token, new Key(self::secret(), self::ALG));
        } catch (ExpiredException) {
            throw new UnauthorizedException('登录已过期，请重新登录');
        } catch (\Throwable) {
            throw new UnauthorizedException('登录凭证无效', 10101);
        }
    }

    /**
     * 把某个 jti 加入黑名单
     *
     * ⚠️ `$ttl` **必须传该令牌的剩余寿命**。以前这里默认 7200（access 的寿命），
     * 拿去拉黑 refresh token 就是错的——refresh 活 7 天，黑名单 2 小时后自己过期，
     * 那个本该作废的 token 又活过来了。所以默认值去掉了，强制调用方算清楚。
     *
     * @param string $jti 令牌唯一标识
     * @param int    $ttl 黑名单保留秒数，取该令牌的剩余寿命；下限 60 秒
     */
    public static function revoke(string $jti, int $ttl): void
    {
        if ($jti !== '') {
            Cache::set("jwt:revoked:{$jti}", 1, max($ttl, 60));
        }
    }

    /**
     * 吊销一个已解析的令牌，TTL 按它自己的 `exp` 算
     *
     * @param array $payload 已解码的载荷
     */
    public static function revokePayload(array $payload): void
    {
        self::revoke((string) ($payload['jti'] ?? ''), self::remaining($payload));
    }

    /**
     * 吊销「当前这一对」：access 与签发时与它配对的 refresh
     *
     * 只作废当前设备的这一对，**不影响该用户在其他端的会话**——
     * 在手机上退出登录不该把办公室电脑一起踢了。
     * 要全端下线走 token_version（改密、管理员重置密码）。
     *
     * @param string $accessJti 当前 access 令牌的 jti
     * @param int    $accessTtl access 的剩余寿命
     */
    public static function revokePair(string $accessJti, int $accessTtl): void
    {
        if ($accessJti === '') {
            return;
        }

        self::revoke($accessJti, $accessTtl);

        $refreshJti = (string) (Cache::get(self::pairKey($accessJti)) ?? '');
        if ($refreshJti !== '') {
            // 拿不到 refresh 的 exp，按配置的完整寿命拉黑：宁可多留一会儿，
            // 也不能像以前那样按 access 的 7200 秒算，那等于没拉黑
            self::revoke($refreshJti, Env::int('JWT_REFRESH_TTL', 604800));
            Cache::del(self::pairKey($accessJti));
        }
    }

    public static function isRevoked(string $jti): bool
    {
        return $jti !== '' && Cache::exists("jwt:revoked:{$jti}");
    }

    /**
     * 载荷里剩余的有效秒数
     *
     * @param array $payload 已解码的载荷
     *
     * @return int 剩余秒数，已过期则为 0
     */
    public static function remaining(array $payload): int
    {
        return max(0, (int) ($payload['exp'] ?? 0) - time());
    }

    private static function pairKey(string $accessJti): string
    {
        return "jwt:pair:{$accessJti}";
    }

    private static function secret(): string
    {
        $secret = (string) Env::get('JWT_SECRET', '');
        if (strlen($secret) < 16) {
            throw new \RuntimeException('JWT_SECRET 未配置或过短，请在 .env 中设置至少 16 位随机字符串');
        }

        return $secret;
    }
}
