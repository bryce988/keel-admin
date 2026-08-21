<?php
/**
 * keel admin
 * 岗位 —— sys_posts
 *
 * 岗位 ≠ 角色。它只在新建用户时把 default_role_id 带出来当默认值，
 * 之后两者不再联动——改岗位不会改人已有的角色，否则调岗会静默改权限。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int     $id              主键
 * @property string  $name            名称
 * @property string  $code            编码，全表唯一
 * @property int     $dept_id         所属部门，0 = 全公司通用
 * @property int     $default_role_id 入职时带出的默认角色
 * @property int     $sort            排序，值越小越靠前
 * @property int     $status          状态：0 停用 · 1 启用（见 HasStatus）
 * @property string  $remark          备注
 * @property Carbon  $created_at      创建时间
 * @property Carbon  $updated_at      更新时间
 * @property ?Carbon $deleted_at      删除时间，null = 未删除
 *
 * @property-read ?SysDeptModel $dept 所属部门
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use app\common\model\concern\HasStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class SysPostModel extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;
    use HasStatus;

    protected $table = 'sys_posts';

    protected $casts = [
        'dept_id'         => 'integer',
        'default_role_id' => 'integer',
        'sort'            => 'integer',
        'status'          => 'integer',
    ];

    public function ownerColumn(): ?string
    {
        return null;
    }

    public function auditColumns(): array
    {
        return [];   // 建表时未设审计列
    }

    public function dept(): BelongsTo
    {
        return $this->belongsTo(SysDeptModel::class, 'dept_id');
    }
}
