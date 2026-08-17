<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;

/**
 * 登录日志
 *
 * 登录失败也要写（含失败原因），连续失败锁定的审计依据就在这里。
 */
class SysLoginLogModel extends BaseModel
{
    use HasDataScope;

    public const TYPE_LOGIN  = 1;
    public const TYPE_LOGOUT = 2;

    protected $table = 'sys_login_logs';

    public const UPDATED_AT = null;

    protected $casts = [
        'user_id' => 'integer',
        'type'    => 'integer',
        'status'  => 'integer',
    ];

    /** 建表时没有 dept_id，只能按人隔离 */
    public function deptColumn(): ?string
    {
        return null;
    }

    public function ownerColumn(): ?string
    {
        return 'user_id';
    }

    public function auditColumns(): array
    {
        return [];
    }
}
