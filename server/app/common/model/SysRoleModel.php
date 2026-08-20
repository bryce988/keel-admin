<?php

declare(strict_types=1);

namespace app\common\model;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 角色
 *
 * 角色是全局定义，不接数据权限 Scope——否则部门主管会看不到自己要授予的角色。
 * data_scope 决定的是「持有该角色的人能看到哪些数据」，不是「谁能看到这个角色」。
 */
class SysRoleModel extends BaseModel
{
    use SoftDeletes;

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
