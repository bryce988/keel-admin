<?php

declare(strict_types=1);

namespace app\common\support;

/**
 * 数组与字符串小工具
 *
 * 这里没有驼峰/下划线转换函数：接口契约与数据库字段名统一用 snake_case，
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
    /**
     * 邮箱脱敏
     *
     * 只打用户名部分，域名留着：`z****@example.com`。域名不是敏感信息，
     * 打掉反而让人认不出这是公司邮箱还是私人邮箱。
     *
     * ⚠️ `mask($name, 1, 0)` 的 tail 必须是 0 而不是留默认的 4——
     * `mb_substr($s, -0)` 返回整串，会得到 `m******manager@example.com` 这种
     * 半脱敏结果（Arr::mask 内部已处理，这里保留说明免得有人来「优化」掉）。
     */
    public static function maskEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$name, $domain] = explode('@', $email, 2);

        return self::mask($name, 1, 0) . '@' . $domain;
    }

    public static function mask(string $value, int $head = 3, int $tail = 4): string
    {
        $len = mb_strlen($value);

        // 空值原样返回：没填手机号的人显示成 `*`，界面上看着像「有号码但被打码了」，
        // 用户会追着问「我的手机号谁改的」。没有的东西不该脱敏出一个存在感
        if ($len === 0) {
            return '';
        }

        if ($len <= $head + $tail) {
            return $len > 1 ? mb_substr($value, 0, 1) . str_repeat('*', $len - 1) : '*';
        }

        // mb_substr($v, -0) 返回的是整串而不是空串，tail=0 必须单独处理
        $suffix = $tail > 0 ? mb_substr($value, -$tail) : '';

        return mb_substr($value, 0, $head) . str_repeat('*', $len - $head - $tail) . $suffix;
    }
}
