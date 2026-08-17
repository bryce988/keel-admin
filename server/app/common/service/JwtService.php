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
 * - token 内只放 uid / type / 权限版本号，**不放权限列表**
 *   否则授权变更后旧 token 里的权限会一直有效到过期
 * - 登出把 jti 写进 Redis 黑名单，有效期与 token 剩余时间一致
 */
class JwtService
{
    private const ALG = 'HS256';

    public static function issue(int $uid, int $permVersion, string $type = 'admin'): array
    {
        $now       = time();
        $accessTtl = Env::int('JWT_ACCESS_TTL', 7200);
        $refreshTtl = Env::int('JWT_REFRESH_TTL', 604800);

        $access = self::encode([
            'uid'  => $uid,
            'type' => $type,
            'pv'   => $permVersion,
            'jti'  => bin2hex(random_bytes(8)),
            'iat'  => $now,
            'exp'  => $now + $accessTtl,
        ]);

        $refresh = self::encode([
            'uid'  => $uid,
            'type' => $type,
            'pv'   => $permVersion,
            'jti'  => bin2hex(random_bytes(8)),
            'scope'=> 'refresh',
            'iat'  => $now,
            'exp'  => $now + $refreshTtl,
        ]);

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

    /** 登出：把 jti 加入黑名单直到原过期时间 */
    public static function revoke(string $jti, int $ttl = 7200): void
    {
        if ($jti !== '') {
            Cache::set("jwt:revoked:{$jti}", 1, max($ttl, 60));
        }
    }

    public static function isRevoked(string $jti): bool
    {
        return $jti !== '' && Cache::exists("jwt:revoked:{$jti}");
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
