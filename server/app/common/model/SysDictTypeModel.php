<?php
/**
 * keel admin
 * 字典类型 —— sys_dict_types
 *
 * 全站枚举的定义处。业务代码里禁止写死枚举，一律按 code 取字典项
 * （CLAUDE.md：不写死颜色、不写死枚举）。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id         主键
 * @property string $name       字典名称，如「通用状态」
 * @property string $code       字典编码，如 common_status，全表唯一
 * @property string $remark     备注
 * @property int    $status     状态：0 停用 · 1 启用（见 HasStatus）
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 *
 * @property-read Collection<int, SysDictItemModel> $items 该字典下的字典项
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class SysDictTypeModel extends BaseModel
{
    use HasStatus;

    protected $table = 'sys_dict_types';

    protected $casts = ['status' => 'integer'];

    public function auditColumns(): array
    {
        return [];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SysDictItemModel::class, 'type_code', 'code');
    }
}
