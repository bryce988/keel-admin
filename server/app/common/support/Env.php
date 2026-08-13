<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 环境变量读取
 *
 * 容器通过 docker compose 注入环境变量，这里只做读取与类型转换。
 * 不使用 .env 文件解析，减少一层依赖。
 */
class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $value,
        };
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : (bool) $value;
    }

    /** 是否生产环境：生产环境不返回异常堆栈 */
    public static function isProd(): bool
    {
        return self::get('APP_ENV', 'dev') === 'prod';
    }
}
