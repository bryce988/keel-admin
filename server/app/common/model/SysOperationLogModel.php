<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;

/**
 * 操作日志
 *
 * 只写不改不删。日志本身也受数据权限约束——部门主管只能看到本部门的操作记录。
 */
class SysOperationLogModel extends BaseModel
{
    use HasDataScope;

    public const ACTION_CREATE = 1;
    public const ACTION_UPDATE = 2;
    public const ACTION_DELETE = 3;
    public const ACTION_EXPORT = 4;
    public const ACTION_GRANT  = 5;
    public const ACTION_OTHER  = 6;

    protected $table = 'sys_operation_logs';

    /** 只有 created_at，没有 updated_at */
    public const UPDATED_AT = null;

    protected $casts = [
        'user_id'  => 'integer',
        'dept_id'  => 'integer',
        'action'   => 'integer',
        'status'   => 'integer',
        'duration' => 'integer',
        'params'   => 'array',
        'changes'  => 'array',
    ];

    public function ownerColumn(): ?string
    {
        return 'user_id';
    }

    public function auditColumns(): array
    {
        return [];
    }
}
