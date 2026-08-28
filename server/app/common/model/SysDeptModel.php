<?php
/**
 * keel admin
 * 部门 —— sys_depts
 *
 * 数据权限的载体。ancestors 存祖级路径（如 '0,1,3'），「本部门及下属」范围
 * 靠它一条前缀匹配就能取整棵子树，不必递归。
 *
 * ⚠️ 移动部门时必须同步刷新所有子孙的 ancestors，漏刷的后果不是显示错乱，
 * 而是权限失效——用户会看到本不该看的数据。
 *
 * 这张表的归属列是自己的 id（见 deptColumn），不是 dept_id。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int     $id         主键
 * @property int     $parent_id  上级部门，0 = 顶级
 * @property string  $ancestors  祖级路径，如 '0,1,3'
 * @property string  $name       名称
 * @property string  $code       部门编码，DEPT- 加四位补零主键，由程序生成（DeptService::makeCode）
 * @property int     $leader_id  部门负责人（sys_users.id）
 * @property int     $sort       排序，值越小越靠前
 * @property int     $status     状态：0 停用 · 1 启用（见 HasStatus）
 * @property int     $creator_id 创建人，由 HasAudit 自动填
 * @property int     $updater_id 最后修改人，由 HasAudit 自动填
 * @property Carbon  $created_at 创建时间
 * @property Carbon  $updated_at 更新时间
 * @property ?Carbon $deleted_at 删除时间，null = 未删除
 *
 * @property-read Collection<int, SysDeptModel> $children 下级部门
 * @property-read Collection<int, SysUserModel> $users    本部门的用户
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use app\common\model\concern\HasStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class SysDeptModel extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;
    use HasStatus;

    protected $table = 'sys_depts';

    protected $casts = [
        'parent_id' => 'integer',
        'leader_id' => 'integer',
        'sort'      => 'integer',
        'status'    => 'integer',
    ];

    /** 部门自身的归属就是它自己 */
    public function deptColumn(): ?string
    {
        return 'id';
    }

    public function ownerColumn(): ?string
    {
        return null;
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(SysUserModel::class, 'dept_id');
    }

    /** 本节点作为父级时，子孙的 ancestors 前缀 */
    public function descendantPrefix(): string
    {
        return ($this->ancestors === '' ? '' : $this->ancestors . ',') . $this->id;
    }
}
