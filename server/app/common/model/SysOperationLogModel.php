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

    /** 操作类型，由 config/route.php 的 log.action 声明，不写落到 ACTION_OTHER */
    public const ACTION_CREATE = 1;   // 新增
    public const ACTION_UPDATE = 2;   // 修改，含启用/停用这类状态变更
    public const ACTION_DELETE = 3;   // 删除
    public const ACTION_EXPORT = 4;   // 导出，数据离开系统，单独一类才追得到
    public const ACTION_GRANT  = 5;   // 授权，改角色权限、改数据范围、给用户配角色
    public const ACTION_OTHER  = 6;   // 其他，也是没声明 action 时的兜底值

    /** 操作结果，1 是「成功」不是「启用」 */
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAIL    = 0;

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
