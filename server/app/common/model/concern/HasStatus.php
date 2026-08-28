<?php

declare(strict_types=1);

namespace app\common\model\concern;

/**
 * 开关型 status（0 停用 / 1 启用）
 *
 * 部门、角色、菜单权限、岗位、字典类型、字典项、用户七张表用的是同一套语义。
 * 用户表原先是三档（在职 / 试用期 / 停用），试用期是人事状态不是权限状态，
 * 登录与鉴权对它和「在职」一视同仁，唯一的作用是让判断到处得写 `!== 0` 而不是 `=== 1`。
 * 脚手架不带业务语义，已归并为两档。
 *
 * 没放进 BaseModel：日志表的 status 是「1 成功 0 失败」，继承到一组名字对含义错的常量，
 * 写成 SysLoginLogModel::STATUS_ENABLED 能编译也能查，只是查出来的不对。
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
