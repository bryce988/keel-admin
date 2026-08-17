<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 数组与字符串小工具
 *
 * 这里**没有**驼峰/下划线转换函数：接口契约与数据库字段名统一用 snake_case，
 * 全链路不做键名转换（docs/api.md §1.4）。要是哪天又冒出转换需求，
 * 先确认是不是契约被改歪了，而不是在这里加个 helper 绕过去。
 */
final class Arr
{
    /** 只取白名单内的键，用于把入参映射到字段名 */
    public static function only(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }

    /** 手机号 138****8000 / 身份证 保留首尾 */
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
