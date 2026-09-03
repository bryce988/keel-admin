<?php
/**
 * keel admin
 * 跨域（CORS）
 *
 * 谁需要它：**浏览器里跑的 C 端**——H5 版本、以及用 HBuilderX「运行到浏览器」
 * 预览 App 的时候。原生 App 与小程序都没有同源策略，不经过这一层。
 *
 * 管理后台不需要：`web/` 的开发服务器配了 vite proxy，生产是同域 nginx，
 * 两种情况下请求都是同源的。
 *
 * ## 为什么必须放在全局最外层
 *
 * 两个原因，少一个都会失败：
 *
 * 1. **预检请求不带业务头**。浏览器发的 `OPTIONS` 里没有 `X-Channel`，
 *    如果排在 `ChannelMiddleware` 后面，预检会被它以「缺少渠道头」400 掉，
 *    浏览器看到的就是「跨域失败」而不是那句人话
 * 2. **预检打的是同一个 URL 但方法是 OPTIONS**，而路由只登记了 POST/GET/PUT。
 *    走到路由匹配就已经是 404 了，所以这里在**进路由之前**直接把它答掉
 *
 * ## 白名单，不是 `*`
 *
 * 允许的来源从 `CORS_ALLOW_ORIGINS` 读，逗号分隔，支持 `*` 通配
 * （`http://localhost:*` 这种——H5 开发服务器的端口每次都可能不一样）。
 * 留空则整个功能关闭：脚手架默认不对外开跨域，要开是部署方的显式决定。
 *
 * 回声式返回具体的 Origin 而不是 `*`：一来 `*` 与 `Allow-Credentials` 不能共存，
 * 将来要带 cookie 就得推倒重来；二来把「谁能访问」写进配置，
 * review 时看一眼 `.env` 就知道，不用去翻代码。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class CorsMiddleware implements MiddlewareInterface
{
    /**
     * 允许客户端带的头
     *
     * 少一个，浏览器就在预检阶段把整个请求拦下来。C 端的三个渠道头都要在这里列出——
     * 它们是自定义头，不在 CORS 的「简单头」白名单里。
     */
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Channel, X-App-Version, X-Device-Id, X-Trace-Id';

    /** 允许 JS 读到的响应头。不列出来的话，前端拿不到 traceId，出问题没法对日志 */
    private const EXPOSE_HEADERS = 'X-Trace-Id, X-Response-Time';

    public function process(Request $request, callable $handler): Response
    {
        $origin = (string) $request->header('origin', '');
        $allowed = $origin !== '' && self::allows($origin);

        // 预检：直接答掉，不进路由、不进任何业务中间件
        if (strtoupper($request->method()) === 'OPTIONS') {
            return $allowed
                ? new Response(204, self::headers($origin))
                // 不在白名单里也回 204，但不带 CORS 头——浏览器照样会拦，
                // 而这样不会把「哪些来源被允许」通过状态码差异透出去
                : new Response(204);
        }

        $response = $handler($request);

        if ($allowed) {
            foreach (self::headers($origin) as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /** @return array<string, string> */
    private static function headers(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin'   => $origin,
            'Access-Control-Allow-Methods'  => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers'  => self::ALLOW_HEADERS,
            'Access-Control-Expose-Headers' => self::EXPOSE_HEADERS,
            // 预检结果缓存 10 分钟：不缓存的话每个写请求都要多一次往返
            'Access-Control-Max-Age'        => '600',
            // 来源不同响应就不同，不加这行会被中间缓存串味
            'Vary'                          => 'Origin',
        ];
    }

    /**
     * 来源是否在白名单里
     *
     * 用 fnmatch 而不是相等比较，是为了支持 `http://localhost:*`：
     * H5 开发服务器的端口每次启动都可能变（5173 被占就用 5174），
     * 写死端口的话每次都要改配置。
     */
    private static function allows(string $origin): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOW_ORIGINS', ''))));

        foreach ($list as $pattern) {
            if ($pattern === '*' || $pattern === $origin || fnmatch($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
