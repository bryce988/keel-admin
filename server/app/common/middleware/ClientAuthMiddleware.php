<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\UnauthorizedException;
use app\common\model\AppUserModel;
use app\common\service\JwtService;
use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * C 端鉴权
 *
 * 与后台鉴权的关键区别是身份体系不同（PROJECT.md §8.4）：
 * 这里认的是 app_users 而不是 sys_users，token 的 type 必须是 client。
 * 员工 token 调 C 端接口一律 401，反之亦然——两套体系永不混用。
 *
 * 每个请求都查一次 app_users：token 是无状态的，而封禁、改密要**立刻**生效。
 * 缓存这一步之前先想清楚失效路径——「封了号但他还能用两小时」比多一次主键查询贵得多。
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

        /** @var AppUserModel|null $user */
        $user = AppUserModel::query()->find((int) ($payload['uid'] ?? 0));

        if ($user === null) {
            throw new UnauthorizedException('账号不存在，请重新登录');
        }
        if ((int) $user->status === AppUserModel::STATUS_DISABLED) {
            throw new UnauthorizedException('账号已被封禁', BizCode::APP_ACCOUNT_DISABLED);
        }

        // 改密时 token_version 递增，旧令牌当场失效——「改了密码，别处还登着」是最常见的投诉
        if ((int) $user->token_version !== (int) ($payload['tv'] ?? 0)) {
            throw new UnauthorizedException('登录已失效，请重新登录', BizCode::PASSWORD_CHANGED);
        }

        Ctx::set('client_user', ['id' => (int) $user->id]);
        Ctx::set('jti', $payload['jti'] ?? '');
        // logout 要按剩余寿命吊销配对的 refresh，得把整份载荷留下
        Ctx::set('jwt_payload', $payload);

        return $handler($request);
    }
}
