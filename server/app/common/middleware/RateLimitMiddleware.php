<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\exception\RateLimitException;
use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * C 端限流
 *
 * 固定窗口计数，维度是「设备 or IP + 路径」。
 * 计数必须放 Redis 而不是进程内变量：webman 是多进程模型，
 * 同一个客户端的连续请求会落到不同 worker 上，进程内计数各数各的，
 * 实际限流阈值会被放大到 worker 数量倍（PROJECT.md §14）。
 *
 * 登录、短信这类敏感接口的更严限流在各自路由上单独加，这里是兜底。
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $limit  = Env::int('CLIENT_RATE_LIMIT', 120);
        $window = Env::int('CLIENT_RATE_WINDOW', 60);

        if ($limit <= 0) {
            return $handler($request);   // 置 0 关闭限流
        }

        // 设备号比 IP 稳定：移动网络下大量用户共用出口 IP，按 IP 限会误伤
        $identity = Ctx::get('device_id') ?: $request->getRealIp();
        $key      = sprintf('rl:client:%s:%s', md5((string) $identity), md5($request->path()));

        $count = Cache::incr($key, $window);

        if ($count > $limit) {
            throw new RateLimitException('操作过于频繁，请稍后再试', max(Cache::ttl($key), 1));
        }

        return $handler($request);
    }
}
