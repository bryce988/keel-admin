<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\UnauthorizedException;
use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 内部服务令牌
 *
 * /internal/* 是服务间调用，不走 JWT——没有「用户」这个概念，
 * 一个固定令牌加内网限制就够，多一层身份体系只是负担。
 *
 * ⚠️ 令牌未配置时**拒绝所有请求**而不是放行。
 * 内部接口通常权限最大（跳过数据权限、可批量操作），
 * 默认放行等于把最危险的一组接口裸奔在网络上。
 */
class InternalTokenMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $expected = (string) Env::get('INTERNAL_TOKEN', '');
        if ($expected === '') {
            throw new UnauthorizedException('内部服务令牌未配置，该组接口已禁用', BizCode::UNAUTHORIZED);
        }

        $token = (string) $request->header('x-internal-token', '');

        if ($token === '' || !hash_equals($expected, $token)) {
            throw new UnauthorizedException('内部服务令牌无效', BizCode::UNAUTHORIZED);
        }

        return $handler($request);
    }
}
