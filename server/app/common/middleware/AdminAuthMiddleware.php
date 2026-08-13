<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\exception\UnauthorizedException;
use app\common\service\AuthService;
use app\common\service\JwtService;
use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 管理后台鉴权
 *
 * 解析 JWT → 校验 token 类型与黑名单 → 加载用户 → 写入请求上下文。
 * token 里只放 uid / type / 权限版本号，权限本身从缓存或数据库取，
 * 这样角色授权变更后无需等待 token 过期即可生效。
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $token = $this->extractToken($request);
        if ($token === '') {
            throw new UnauthorizedException('未登录，请先登录');
        }

        $payload = JwtService::decode($token);

        // 端隔离：员工 token 不能调 C 端接口，反之亦然
        if (($payload['type'] ?? '') !== 'admin') {
            throw new UnauthorizedException('登录凭证类型不匹配', 10102);
        }

        if (JwtService::isRevoked($payload['jti'] ?? '')) {
            throw new UnauthorizedException('登录已失效，请重新登录');
        }

        $user = AuthService::loadUser((int) ($payload['uid'] ?? 0));

        // 权限版本号对不上说明期间被改过角色或被停用，强制重新登录
        if ((int) ($payload['pv'] ?? -1) !== (int) $user['perm_version']) {
            throw new UnauthorizedException('权限已变更，请重新登录');
        }

        Ctx::set('user', $user);
        Ctx::set('jti', $payload['jti'] ?? '');

        return $handler($request);
    }

    private function extractToken(Request $request): string
    {
        $header = (string) $request->header('authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return '';
    }
}
