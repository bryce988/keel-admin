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

        // ⚠️ 这里**不**校验 token 里的 pv。
        // perm_version 的用途是让 Redis 里的权限缓存 key 失效，
        // 授权变更后下一个请求就按新权限判定——用户无需重新登录（PROJECT.md §15 验收项）。
        // 若在此比对 pv 并抛 401，等于管理员每改一次角色就把在线用户全部踢下线。
        // 真正需要强制下线的场景（改密码、管理员踢人）走 jti 黑名单。

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
