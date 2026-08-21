<?php
/**
 * keel admin
 * 字段级权限 —— sys_role_fields
 *
 * RBAC 三个维度里最细的一层：控制某个角色能不能看到某张表的某个字段的明文。
 *
 * 表中无记录 = 按代码里声明的默认策略（敏感字段默认不可见）。
 * 这样新增敏感字段时不会因为忘记配置而泄露——默认拒绝，配了才放行。
 *
 * 没有时间戳（$timestamps = false）：这是配置不是业务数据，改动由操作日志留痕。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id       主键
 * @property int    $role_id  角色 ID
 * @property string $object   对象标识，通常为表名，如 sys_users
 * @property string $field    字段名，如 phone
 * @property bool   $visible  是否可见，0 = 接口返回脱敏值或不返回
 * @property bool   $editable 是否可编辑，0 = 只读
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

class SysRoleFieldModel extends BaseModel
{
    protected $table = 'sys_role_fields';

    public $timestamps = false;

    protected $casts = [
        'role_id'  => 'integer',
        'visible'  => 'boolean',
        'editable' => 'boolean',
    ];

    public function auditColumns(): array
    {
        return [];
    }
}
