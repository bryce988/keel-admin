<?php
/**
 * keel admin
 * 登录日志 —— sys_login_logs
 *
 * 登录失败也要写（含失败原因）：连续失败锁定的判定依据就是这张表，
 * 失败行不是可有可无的调试信息。
 *
 * 只有 created_at 没有 updated_at，也没有审计字段——日志只写不改。
 * 保留天数到期后由 LogCleanupService 硬删回收空间，所以这张表不做软删。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id         主键
 * @property int    $user_id    用户 ID，账号不存在时为 0
 * @property string $username   登录账号，冗余存储，用户改名后日志仍可读
 * @property int    $dept_id    登录人部门，日志本身也受数据权限约束（见 deptColumn）
 * @property string $ip         来源 IP
 * @property string $location   IP 归属地
 * @property string $browser    浏览器
 * @property string $os         操作系统
 * @property int    $type       记录类型：1 登录 · 2 登出（见 TYPE_*）
 * @property int    $status     结果：1 成功 · 0 失败（见 STATUS_*，注意不是启用/停用）
 * @property string $msg        失败原因
 * @property Carbon $created_at 创建时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Support\Carbon;

class SysLoginLogModel extends BaseModel
{
    use HasDataScope;

    public const TYPE_LOGIN  = 1;   // 登录，成功失败都写
    public const TYPE_LOGOUT = 2;   // 登出

    /**
     * 登录结果，1 是「成功」不是「启用」，跟开关型的 status 不是一回事
     *
     * 失败行是账号锁定的判定依据（按「账号 + IP」数连续失败次数），必须落库。
     */
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAIL    = 0;

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
