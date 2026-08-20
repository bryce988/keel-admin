<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\exception\RateLimitException;
use app\common\support\Cache;
use app\common\support\ClientIp;
use app\common\support\Ctx;
use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * C 端限流
 *
 * 固定窗口计数，两层：细粒度按「IP + 设备号 + 路径」，另有一层按 IP 的总量兜底。
 * 为什么不能只按设备号，见 process() 里的注释。
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

        $ip = ClientIp::of($request);

        /*
         * 两个计数器，缺一不可
         *
         * 原来只有一个，键是 `device_id ?: IP`。而 device_id 来自 `X-Device-Id`——
         * 客户端头，且优先级在 IP 之前。也就是说每次请求换个设备号就能无限刷，
         * 连 IP 都不用伪造，限流等于默认不设防。
         *
         * 现在细粒度桶按「IP + 设备号」算：移动网络下大量用户共用出口 IP，
         * 设备号仍然起到细分作用，不会误伤；但设备号**不再能替代 IP**。
         * 再加一个粗粒度的按 IP 上限兜底，把「换设备号刷」的总量封死。
         */
        $device   = (string) (Ctx::get('device_id') ?: '-');
        $fineKey  = sprintf('rl:c:%s:%s:%s', md5($ip), md5($device), md5($request->path()));
        $ipKey    = sprintf('rl:ip:%s', md5($ip));

        // 每 IP 的总量上限：默认放到单桶的 10 倍，正常用户碰不到，
        // 而靠轮换设备号绕过细粒度限制的行为会在这里被拦住
        $ipLimit = Env::int('CLIENT_IP_RATE_LIMIT', $limit * 10);

        $count = Cache::incr($fineKey, $window);
        if ($count > $limit) {
            throw new RateLimitException('操作过于频繁，请稍后再试', max(Cache::ttl($fineKey), 1));
        }

        if ($ipLimit > 0) {
            $ipCount = Cache::incr($ipKey, $window);
            if ($ipCount > $ipLimit) {
                throw new RateLimitException('操作过于频繁，请稍后再试', max(Cache::ttl($ipKey), 1));
            }
        }

        return $handler($request);
    }
}
