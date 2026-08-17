<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 部门
 *
 * ancestors 存祖级路径（如 '0,1,3'），数据权限「本部门及下属」靠它一条 SQL 取整棵子树。
 * 移动部门时必须同步刷新所有子孙的 ancestors。
 */
class SysDept extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;

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
        return $this->hasMany(SysUser::class, 'dept_id');
    }

    /** 本节点作为父级时，子孙的 ancestors 前缀 */
    public function descendantPrefix(): string
    {
        return ($this->ancestors === '' ? '' : $this->ancestors . ',') . $this->id;
    }
}
