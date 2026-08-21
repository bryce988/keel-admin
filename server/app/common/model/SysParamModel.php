<?php
/**
 * keel admin
 * 系统参数 —— sys_params
 *
 * ⚠️ 参数只能改数据库、走缓存读取，不允许运行期改 webman 配置——
 * 常驻内存下改配置只会影响当前 worker，进程间状态立刻不一致（PROJECT.md §14）。
 *
 * is_secret 的参数只写不读：接口一律回掩码 ******，提交时原样送回即表示不修改。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id          主键
 * @property string $group       分组：basic / security / integration / advanced
 * @property string $name        名称
 * @property string $param_key   参数键，如 sys.upload.maxSize，全表唯一
 * @property string $param_value 参数值，按 value_type 还原（见 typedValue）
 * @property string $value_type  值类型：string / int / bool / json
 * @property bool   $is_builtin  内置参数，不可删除，只可改值
 * @property bool   $is_secret   密钥类，只写不读，界面显示掩码
 * @property string $remark      备注
 * @property int    $updater_id  最后修改人，由 HasAudit 自动填
 * @property Carbon $created_at  创建时间
 * @property Carbon $updated_at  更新时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

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
