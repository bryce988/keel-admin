<?php

declare(strict_types=1);

namespace app\common\model;

/**
 * 字段级权限
 *
 * 表中无记录 = 按代码里声明的默认策略（敏感字段默认不可见）。
 * 这样新增敏感字段时不会因为忘记配置而泄露。
 */
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
