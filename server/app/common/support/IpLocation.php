<?php

declare(strict_types=1);

namespace app\common\support;

use Ip2Region;
use support\Log;
use Throwable;

/**
 * IP 归属地
 *
 * 用 ip2region 的离线库查，**不调任何第三方接口**：
 * 登录是同步路径，一次外部 HTTP 请求就能让整个登录卡在那里，
 * 对方限流或超时的时候更糟——为了日志上一行字，赌上登录可用性不划算。
 *
 * ⚠️ cachePolicy 必须是 'file'，实测结论（v3.0.15）：
 * - `vectorIndex`：**是坏的**。公网 IP 一律返回空串，还刷一堆
 *   「Uninitialized string offset」警告——它把 8KB 的向量索引当整个库在读
 * - `content`：正确但把 10MB 的 xdb 全塞进内存，webman 是多进程常驻，
 *   每个 worker 各占一份，八个进程就是 80MB，只为省 0.3ms
 * - `file`：正确，首次 1.6ms、之后 0.3ms 左右，进程内存增量 0.2MB
 */
final class IpLocation
{
    /**
     * 进程级只读基础设施，可以用 static 持有
     *
     * 与 Db、Cache 同理：它不含任何请求态，只是一个打开的数据库读取器
     * （CLAUDE.md：static 只能存进程级基础设施）。
     */
    private static ?Ip2Region $searcher = null;

    /** 查不到时统一给这个，不留空白 */
    public const UNKNOWN = '未知';

    /**
     * 查归属地，失败一律返回兜底文案
     *
     * 绝不抛异常：这是登录日志的一个附加字段，
     * 为它中断登录或者让一次失败登录连记录都留不下来，是本末倒置。
     * 已知会抛的两种情况都在这里被吃掉：
     * - IPv6：内置库只有 IPv4，v6 要另外下载 xdb，没有就抛
     * - 非法 IP：库里直接抛「无效的IP地址」
     */
    public static function of(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return self::UNKNOWN;
        }

        try {
            $location = self::searcher()->simple($ip);
        } catch (Throwable $e) {
            // 走 default 通道（config/log.php 里业务日志就叫 default，没有 app 通道）。
            // 用 debug 而不是 warning：IPv6 客户端每次登录都会走到这里，
            // 记成警告会把日志刷满，而这本来就是「查不到就算了」的字段
            Log::debug('[IP 归属地] 查询失败 ' . $ip . '：' . $e->getMessage());

            return self::UNKNOWN;
        }

        $location = trim((string) $location);

        // 库里查得到但返回空串的情况（数据缺失）也归到未知
        return $location === '' ? self::UNKNOWN : mb_substr($location, 0, 64);
    }

    private static function searcher(): Ip2Region
    {
        return self::$searcher ??= new Ip2Region('file');
    }
}
