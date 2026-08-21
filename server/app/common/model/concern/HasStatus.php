<?php

declare(strict_types=1);

namespace app\common\model\concern;

/**
 * 开关型 status（0 停用 / 1 启用）
 *
 * 部门、角色、菜单权限、岗位、字典类型、字典项六张表用的是同一套语义。
 *
 * 没放进 BaseModel：日志表的 status 是「1 成功 0 失败」，继承到一组名字对含义错的常量，
 * 写成 SysLoginLogModel::STATUS_ENABLED 能编译也能查，只是查出来的不对。
 * 用户表也不用它，sys_users.status 有三档，常量在 SysUserModel 自己身上。
 *
 * 取值以 database/schema.sql 的列注释为准。
 */
trait HasStatus
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED  = 1;

    /** 只取启用的行：SysRoleModel::query()->enabled() */
    public function scopeEnabled($query)
    {
        return $query->where($this->qualifyColumn('status'), self::STATUS_ENABLED);
    }
}
