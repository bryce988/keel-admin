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
        'dept_id' => 'integer',
        'type'    => 'integer',
        'status'  => 'integer',
    ];

    /**
     * 有 dept_id，与操作日志一致按部门隔离
     *
     * ⚠️ 这里返回 null 是危险的：DataScope 在非「仅本人」的范围下找不到部门列
     * 就直接放行不加任何条件，等于登录日志对部门主管完全敞开。
     * 早期建表漏了这一列，正是这么漏的。
     */
    public function deptColumn(): ?string
    {
        return 'dept_id';
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
