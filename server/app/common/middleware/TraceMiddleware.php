<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\support\Ctx;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 链路追踪 + 上下文清理
 *
 * 管道最外层：进来生成 traceId，出去写响应头，
 * 无论成功失败都在 finally 里清理上下文，防止跨请求残留。
 */
class TraceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        Ctx::clear();

        // 允许上游网关透传 traceId，便于全链路串联
        $traceId = $request->header('x-trace-id') ?: 'TRC-' . bin2hex(random_bytes(6));
        Ctx::set('traceId', $traceId);

        $start = microtime(true);

        try {
            $response = $handler($request);

            return $response
                ->withHeader('X-Trace-Id', $traceId)
                ->withHeader('X-Response-Time', round((microtime(true) - $start) * 1000, 1) . 'ms');
        } finally {
            Ctx::clear();
        }
    }
}
