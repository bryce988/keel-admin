<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 请求级上下文
 *
 * ⚠️ 这是常驻内存模型下**唯一允许**存放请求态数据的地方。
 * 严禁用普通 static 属性存当前用户、traceId 等信息——
 * 进程会连续处理成千上万个请求，残留状态会串号（见 PROJECT.md §14.1）。
 *
 * 优先使用框架提供的 Webman\Context（请求结束自动回收）；
 * 框架版本较老时退化为「中间件在请求开始 set、结束 clear」的显式管理。
 */
class Ctx
{
    private static array $fallback = [];

    public static function set(string $key, mixed $value): void
    {
        if (class_exists(\Webman\Context::class)) {
            \Webman\Context::set($key, $value);

            return;
        }
        self::$fallback[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (class_exists(\Webman\Context::class)) {
            return \Webman\Context::get($key) ?? $default;
        }

        return self::$fallback[$key] ?? $default;
    }

    /** 请求结束时清理，防止跨请求残留 */
    public static function clear(): void
    {
        if (class_exists(\Webman\Context::class)) {
            \Webman\Context::destroy();

            return;
        }
        self::$fallback = [];
    }

    public static function traceId(): string
    {
        $id = self::get('traceId');
        if (!$id) {
            $id = 'TRC-' . bin2hex(random_bytes(6));
            self::set('traceId', $id);
        }

        return $id;
    }

    /** 当前登录用户，未登录返回 null */
    public static function user(): ?array
    {
        return self::get('user');
    }

    public static function userId(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }
}
