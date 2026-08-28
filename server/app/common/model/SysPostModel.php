<?php
/**
 * keel admin
 * 岗位 —— sys_posts
 *
 * 岗位 ≠ 角色。default_role_id 只是「新人入职时的角色初始值」，不是这个岗位的权限：
 * 新建用户选中岗位时前端据此预填角色框，**编辑用户改岗位一律不动角色**——
 * 否则调岗就成了静默改权限。生效范围见 docs/database.md §3.3 的三条表格。
 *
 * 服务端不消费这个字段：授权只认请求里的 role_ids。这样走接口或导入建号时
 * 行为是确定的，不会有一层看不见的默认值在背后生效。
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
