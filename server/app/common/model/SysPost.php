<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 岗位
 *
 * 岗位 ≠ 角色：只在新建用户时带出 default_role_id 作为默认值，之后两者不再联动。
 */
class SysPost extends BaseModel
{
    use SoftDeletes;
    use HasDataScope;

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
}
