<?php

declare(strict_types=1);

namespace app\common\middleware;

use app\common\constant\BizCode;
use app\common\exception\ForbiddenException;
use app\common\support\ClientIp;
use app\common\support\Env;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 开放平台 IP 白名单
 *
 * 支持单 IP 与 CIDR（`203.0.113.5`、`10.0.0.0/8`），逗号分隔。
 * **留空表示不限制**——开源项目的默认行为不能是「谁也调不通」，
 * 生产环境务必在 .env 里配上。
 *
 * 来源 IP 统一走 {@see ClientIp::of()}——**不要在这里自己读转发头**。
 * 白名单是硬边界，取错一次就等于整道门形同虚设，而且配了的人不会知道。
 */
class IpWhitelistMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $raw = trim((string) Env::get('OPEN_IP_WHITELIST', ''));
        if ($raw === '') {
            return $handler($request);
        }

        $ip = ClientIp::of($request);

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $rule) {
            if (self::matches($ip, $rule)) {
                return $handler($request);
            }
        }

        throw new ForbiddenException('来源 IP 不在白名单内', BizCode::IP_NOT_ALLOWED);
    }

    private static function matches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int) $bits;

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;   // IPv6 或格式错误的规则，直接不匹配
        }

        $mask = $bits === 0 ? 0 : -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
