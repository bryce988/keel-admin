<?php
/**
 * keel admin
 * 角色 —— sys_roles
 *
 * 角色是全局定义，不接数据权限 Scope——否则部门主管会看不到自己要授予的角色。
 * data_scope 决定的是「持有该角色的人能看到哪些数据」，不是「谁能看到这个角色」。
 *
 * data_scope 的五个取值用 DataScope 的 ALL / DEPT_TREE / DEPT / SELF / CUSTOM，
 * 本类不重复定义——判定逻辑在那边，抄第二份迟早对不上。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int     $id         主键
 * @property string  $name       名称
 * @property string  $code       角色编码，ROLE- 加四位补零主键，由程序生成（RoleService::makeCode）
 * @property int     $parent_id  继承自哪个角色，0 = 无（RBAC1，权限取并集）
 * @property int     $data_scope 数据范围：1 全部 · 2 本部门及下属 · 3 本部门 · 4 仅本人 · 5 自定义
 * @property bool    $is_builtin 内置角色，不可修改也不可删除
 * @property int     $sort       排序，值越小越靠前
 * @property int     $status     状态：0 停用 · 1 启用（见 HasStatus）
 * @property string  $remark     备注
 * @property int     $creator_id 创建人，由 HasAudit 自动填
 * @property int     $updater_id 最后修改人，由 HasAudit 自动填
 * @property Carbon  $created_at 创建时间
 * @property Carbon  $updated_at 更新时间
 * @property ?Carbon $deleted_at 删除时间，null = 未删除
 *
 * @property-read Collection<int, SysPermissionModel> $permissions 已授权的权限点
 * @property-read Collection<int, SysUserModel>       $users       持有该角色的用户
 * @property-read Collection<int, SysDeptModel>       $depts       data_scope=5 时的自定义部门集合
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class SysRoleModel extends BaseModel
{
    use SoftDeletes;
    use HasStatus;

    protected $table = 'sys_roles';

    protected $casts = [
        'parent_id'  => 'integer',
        'data_scope' => 'integer',
        'is_builtin' => 'boolean',
        'sort'       => 'integer',
        'status'     => 'integer',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(SysPermissionModel::class, 'sys_role_permissions', 'role_id', 'permission_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(SysUserModel::class, 'sys_user_roles', 'role_id', 'user_id');
    }

    /** data_scope = 5 时生效的自定义部门集合 */
    public function depts(): BelongsToMany
    {
        return $this->belongsToMany(SysDeptModel::class, 'sys_role_depts', 'role_id', 'dept_id');
    }
}
