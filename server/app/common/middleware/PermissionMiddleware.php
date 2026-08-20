<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\ForbiddenException;
use app\common\exception\UnauthorizedException;
use app\common\service\PermissionService;
use app\common\support\Ctx;
use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 功能权限校验
 *
 * 权限点写在路由声明上，中间件按 `perm` 参数拦截：
 *
 *   Route::post('/users', [C::class, 'store'])
 *       ->setParams(['perm' => 'sys:user:create']);
 *
 * **fail-closed**：没声明 perm 的接口一律拒绝，而不是放行。
 * 放行意味着「忘了写」等于「不需要权限」，这类漏洞不会有人主动发现；
 * 拒绝则会在第一次自测时就暴露出来。确实无需授权的接口显式写 `'perm' => ''`。
 */
class PermissionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $user = Ctx::user();
        if ($user === null) {
            throw new UnauthorizedException('未登录，请先登录');
        }

        $perm = $request->route?->param('perm');

        if ($perm === null) {
            // 配置遗漏：开发期直接把原因说清楚，生产期只给通用文案避免暴露内部约定
            throw new ForbiddenException(
                Env::isProd()
                    ? '无权限访问'
                    : sprintf('接口未声明权限点：%s %s，请在 config/route.php 的 setParams 中补充 perm', $request->method(), $request->path()),
                BizCode::FORBIDDEN,
            );
        }

        // '' 表示登录即可访问（如个人中心）
        if ($perm === '') {
            return $handler($request);
        }

        $ok = is_array($perm)
            ? PermissionService::hasAny($user, $perm)
            : PermissionService::has($user, (string) $perm);

        if (!$ok) {
            throw new ForbiddenException('无权限访问', BizCode::FORBIDDEN);
        }

        return $handler($request);
    }
}
