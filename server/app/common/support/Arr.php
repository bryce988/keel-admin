<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 键名转换
 *
 * 数据库用 snake_case，接口契约用 camelCase（docs/api.md §1.4）。
 * 转换只发生在「模型输出」与「入参映射」两个边界上，
 * 中间层一律用数据库的字段名，避免同一个字段出现两种写法。
 */
final class Arr
{
    /** 递归把数组键转成 camelCase，用于接口输出 */
    public static function camelKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $newKey = is_string($key) ? self::camel($key) : $key;
            $out[$newKey] = is_array($value) ? self::camelKeys($value) : $value;
        }

        return $out;
    }

    /** 递归把数组键转成 snake_case，用于把前端入参映射到字段名 */
    public static function snakeKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $newKey = is_string($key) ? self::snake($key) : $key;
            $out[$newKey] = is_array($value) ? self::snakeKeys($value) : $value;
        }

        return $out;
    }

    public static function camel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    public static function snake(string $value): string
    {
        if (!preg_match('/[A-Z]/', $value)) {
            return $value;
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /** 只取白名单内的键，用于「前端传什么就存什么」之外的显式字段映射 */
    public static function only(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }

    /** 手机号 138****8000 / 邮箱 a***@b.com / 身份证 保留首尾 */
    public static function mask(string $value, int $head = 3, int $tail = 4): string
    {
        $len = mb_strlen($value);
        if ($len <= $head + $tail) {
            return $len > 1 ? mb_substr($value, 0, 1) . str_repeat('*', $len - 1) : '*';
        }

        // mb_substr($v, -0) 返回的是整串而不是空串，tail=0 必须单独处理
        $suffix = $tail > 0 ? mb_substr($value, -$tail) : '';

        return mb_substr($value, 0, $head) . str_repeat('*', $len - $head - $tail) . $suffix;
    }
}
