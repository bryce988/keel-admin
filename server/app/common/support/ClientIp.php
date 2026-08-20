<?php

declare(strict_types=1);

namespace app\common\support;

use Webman\Http\Request;

/**
 * 客户端真实 IP —— 全站唯一来源
 *
 * ⚠️ **不要再用 `$request->getRealIp()`，也不要自己读 `X-Forwarded-For`。**
 *
 * 曾经有三份各写各的取 IP 逻辑（IP 白名单、操作日志、框架自带），而且三份都能被伪造：
 *
 * 1. nginx 用的是追加式 `$proxy_add_x_forwarded_for`，客户端自带的 XFF
 *    会被原样保留在**最左端**；
 * 2. 应用层一律取 `explode(',', $xff)[0]`，取的正是那个客户端可控的值；
 * 3. webman 的 `getRealIp()` 也救不了——它的头优先级是
 *    `x-forwarded-for` → `x-real-ip` → …，XFF 排在最前面，而且在 safeMode 下
 *    只要 TCP 对端是内网地址（容器里永远成立）就会落到读头的分支。
 *
 * 实测（修复前的生产环境）：
 *
 *     curl -H 'X-Forwarded-For: 1.2.3.4' .../open/ping  →  your_ip: 1.2.3.4
 *
 * 后果是 IP 白名单可绕过、限流可绕过、登录日志与操作日志的 IP 可伪造。
 *
 * ## 这里的规则
 *
 * **只有当 TCP 对端本身是可信代理时，才采信它转发过来的 `X-Real-IP`；
 * 否则一律用对端地址，并且任何情况下都不读 `X-Forwarded-For`。**
 *
 * 采信 `X-Real-IP` 而不是 XFF，是因为 nginx 对它是**覆盖式**赋值
 * （`proxy_set_header X-Real-IP $remote_addr`），客户端传什么都会被盖掉；
 * 而 XFF 是链式的，天然含有不可信段。
 *
 * ## 可信代理怎么配
 *
 * 环境变量 `TRUSTED_PROXIES`，逗号分隔，支持单 IP 与 CIDR。
 * 默认值是三段私有网段 + 回环——docker compose 里 nginx 与 server 同在一个
 * bridge 网络上，对端永远是 `172.x`，不给这个默认值的话所有日志都会记成容器 IP。
 *
 * **生产环境应当收窄成你自己的网关地址**：默认值意味着「同一内网里的任何主机
 * 都能声明客户端 IP」。如果前面还有 CDN / SLB，要把它们的回源网段也加进来，
 * 并确认那一层同样是覆盖式设置 `X-Real-IP`。
 */
class ClientIp
{
    /** 默认可信网段：回环 + 三段 RFC1918 私有地址 */
    private const DEFAULT_TRUSTED = '127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    /**
     * 解析客户端真实 IP
     *
     * @param Request $request 当前请求
     *
     * @return string 点分十进制的 IPv4 或 IPv6 字面量；取不到时返回空串
     */
    public static function of(Request $request): string
    {
        $peer = (string) $request->getRemoteIp();

        // 直连：TCP 对端地址是唯一可信的东西，任何头都不看
        if (!self::isTrustedProxy($peer)) {
            return $peer;
        }

        $real = trim((string) $request->header('x-real-ip', ''));

        // 代理没设或设了个非法值时退回对端地址，而不是相信一个畸形串
        return filter_var($real, FILTER_VALIDATE_IP) ? $real : $peer;
    }

    /**
     * 判断某个地址是不是配置里的可信代理
     *
     * @param string $ip 待判断的地址
     *
     * @return bool 命中任一可信网段返回 true
     */
    private static function isTrustedProxy(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $raw = (string) Env::get('TRUSTED_PROXIES', self::DEFAULT_TRUSTED);

        foreach (array_filter(array_map('trim', explode(',', $raw))) as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 单 IP 或 CIDR 匹配
     *
     * 只处理 IPv4 的 CIDR；IPv6 只支持精确匹配。写全 IPv6 的掩码运算要引入
     * 大整数处理，而目前所有部署形态里代理都是 IPv4，不值得为它加一层复杂度。
     *
     * @param string $ip   待判断的地址
     * @param string $rule 规则，如 `10.0.0.0/8` 或 `203.0.113.5`
     *
     * @return bool 命中返回 true
     */
    private static function matches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits       = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        // /0 要单独处理：PHP 里 -1 << 32 的行为依平台而定，算出来不是 0
        $mask = $bits === 0 ? 0 : -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
