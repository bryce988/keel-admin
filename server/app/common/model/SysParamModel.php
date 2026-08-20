<?php

declare(strict_types=1);

namespace app\common\model;

/**
 * 系统参数
 *
 * ⚠️ 参数只能改数据库、走缓存读取，不允许运行期改 webman 配置——
 * 常驻内存下改配置只会影响当前 worker，进程间状态立刻不一致（PROJECT.md §14）。
 */
class SysParamModel extends BaseModel
{
    protected $table = 'sys_params';

    protected $casts = [
        'is_builtin' => 'boolean',
        'is_secret'  => 'boolean',
    ];

    public function auditColumns(): array
    {
        return ['updater_id'];
    }

    /** 按 value_type 还原成 PHP 类型 */
    public function typedValue(): mixed
    {
        return match ($this->value_type) {
            'int'  => (int) $this->param_value,
            'bool' => in_array(strtolower((string) $this->param_value), ['1', 'true', 'yes'], true),
            'json' => json_decode((string) $this->param_value, true),
            default => (string) $this->param_value,
        };
    }
}
