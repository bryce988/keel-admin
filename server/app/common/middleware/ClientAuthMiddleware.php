<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\UnauthorizedException;
use app\common\service\JwtService;
use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * C 端鉴权
 *
 * 与后台鉴权的关键区别是**身份体系不同**（PROJECT.md §8.4）：
 * 这里认的是 app_users 而不是 sys_users，token 的 type 必须是 client。
 * 员工 token 调 C 端接口一律 401，反之亦然——两套体系永不混用。
 *
 * ⚠️ 二期落地 app_users 表后，这里要补上「加载用户 + 校验状态」，
 * 现在只做 token 层面的校验，把 uid 放进上下文。
 */
class ClientAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $header = (string) $request->header('authorization', '');
        if (stripos($header, 'Bearer ') !== 0) {
            throw new UnauthorizedException('未登录，请先登录');
        }

        $payload = JwtService::decode(trim(substr($header, 7)));

        if (($payload['type'] ?? '') !== 'client') {
            throw new UnauthorizedException('登录凭证类型不匹配', BizCode::TOKEN_TYPE_MISMATCH);
        }

        if (JwtService::isRevoked($payload['jti'] ?? '')) {
            throw new UnauthorizedException('登录已失效，请重新登录');
        }

        // 二期：改为从 app_users 加载并校验状态（封禁、注销）
        Ctx::set('client_user', ['id' => (int) ($payload['uid'] ?? 0)]);
        Ctx::set('jti', $payload['jti'] ?? '');

        return $handler($request);
    }
}
