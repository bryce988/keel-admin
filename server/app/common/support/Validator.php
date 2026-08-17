<?php

declare(strict_types=1);

namespace app\common\support;

use app\common\exception\ValidationException;

/**
 * 轻量参数校验
 *
 * 只做「格式合法性」，业务规则（账号是否重复、部门是否有下级）留在 service 层——
 * 前者的失败是 422 字段级回填，后者是 409/400 的业务提示，交互完全不同。
 *
 *   $data = Validator::make($request->all(), [
 *       'username' => ['required|string|min:2|max:64', '账号'],
 *       'status'   => ['required|int|in:0,1',          '状态'],
 *       'roleIds'  => ['array',                        '角色'],
 *   ])->validated();
 *
 * 校验通过后返回**已转型**的值：int 规则给 int，bool 规则给 bool，
 * 免得 service 里到处 (int) 强转。未声明的键一律丢弃。
 */
final class Validator
{
    private array $errors = [];
    private array $clean  = [];

    private function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {
    }

    /**
     * @param  array  $rules  字段 => 'rule|rule' 或 [规则, 中文名]
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function validated(): array
    {
        foreach ($this->rules as $field => $spec) {
            [$ruleText, $label] = is_array($spec) ? [$spec[0], $spec[1] ?? $field] : [$spec, $field];
            $this->check($field, (string) $ruleText, (string) $label);
        }

        if ($this->errors) {
            throw new ValidationException($this->errors);
        }

        return $this->clean;
    }

    private function check(string $field, string $ruleText, string $label): void
    {
        $rules   = explode('|', $ruleText);
        $present = array_key_exists($field, $this->data);
        $value   = $this->data[$field] ?? null;

        // 空串视同未填，前端清空输入框时传的就是空串
        $empty = !$present || $value === null || $value === '';

        if ($empty) {
            if (in_array('required', $rules, true)) {
                $this->fail($field, "{$label}不能为空");
            } elseif ($present) {
                $this->clean[$field] = $value;
            }

            return;
        }

        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

            $ok = match ($name) {
                'required', 'nullable' => true,
                'string'   => is_string($value) || is_numeric($value),
                'int'      => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
                'num'      => is_numeric($value),
                'bool'     => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
                'array'    => is_array($value),
                'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'phone'    => preg_match('/^1[3-9]\d{9}$/', (string) $value) === 1,
                'date'     => strtotime((string) $value) !== false,
                'code'     => preg_match('/^[A-Za-z0-9_:.\-]+$/', (string) $value) === 1,
                'min'      => $this->size($value) >= (float) $arg,
                'max'      => $this->size($value) <= (float) $arg,
                'len'      => $this->size($value) === (float) $arg,
                'in'       => in_array((string) $value, explode(',', (string) $arg), true),
                'same'     => ($this->data[$arg] ?? null) === $value,
                default    => true,
            };

            if ($ok) {
                continue;
            }

            $this->fail($field, match ($name) {
                'string'  => "{$label}必须是文本",
                'int'     => "{$label}必须是整数",
                'num'     => "{$label}必须是数字",
                'bool'    => "{$label}必须是布尔值",
                'array'   => "{$label}必须是数组",
                'email'   => "{$label}格式不正确",
                'phone'   => "请输入正确的手机号",
                'date'    => "{$label}不是合法的日期",
                'code'    => "{$label}只能包含字母、数字与 _ : . -",
                'min'     => is_numeric($value) && !is_string($value)
                                ? "{$label}不能小于 {$arg}"
                                : "{$label}长度不能少于 {$arg} 个字符",
                'max'     => is_numeric($value) && !is_string($value)
                                ? "{$label}不能大于 {$arg}"
                                : "{$label}长度不能超过 {$arg} 个字符",
                'len'     => "{$label}长度必须为 {$arg}",
                'in'      => "{$label}取值不在允许范围内",
                'same'    => "{$label}与确认项不一致",
                default   => "{$label}不合法",
            });

            return;   // 同一字段只报第一条，避免前端一次弹三行
        }

        $this->clean[$field] = $this->cast($value, $rules);
    }

    /** 字符串按字符数、数组按元素数、数字按值本身 */
    private function size(mixed $value): float
    {
        if (is_array($value)) {
            return (float) count($value);
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return (float) mb_strlen((string) $value);
    }

    private function cast(mixed $value, array $rules): mixed
    {
        if (in_array('int', $rules, true)) {
            return (int) $value;
        }
        if (in_array('num', $rules, true)) {
            return $value + 0;
        }
        if (in_array('bool', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }
        if (in_array('string', $rules, true)) {
            return trim((string) $value);
        }

        return $value;
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
