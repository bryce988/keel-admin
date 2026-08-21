<?php
/**
 * keel admin
 * 操作日志 —— sys_operation_logs
 *
 * 只写不改。日志本身也受数据权限约束——部门主管只能看到本部门的操作记录。
 * 越权尝试同样入库（status=0），这正是审计要看的东西。
 *
 * 写入由 OperationLogMiddleware 负责，路由上声明了 log 才记；
 * service 里用 OpLog::target() / OpLog::diff() 补操作对象与字段级变更。
 *
 * 只有 created_at 没有 updated_at，也没有审计字段。
 * 保留天数到期后由 LogCleanupService 硬删回收空间，所以这张表不做软删。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id         主键
 * @property string $trace_id   链路追踪 ID，与响应体里的一致，排查时按它串起来
 * @property int    $user_id    操作人 ID
 * @property string $username   操作人账号，冗余存储，用户改名后日志仍可读
 * @property int    $dept_id    操作人部门，日志本身也受数据权限约束
 * @property string $module     模块名，如「系统管理/用户」
 * @property int    $action     操作类型：1 新增 · 2 修改 · 3 删除 · 4 导出 · 5 授权 · 6 其他（见 ACTION_*）
 * @property string $title      操作描述
 * @property string $target     操作对象标识，如「用户 zhangsan(12)」
 * @property string $api_method 请求方法
 * @property string $api_path   请求路径
 * @property string $ip         来源 IP
 * @property string $user_agent 客户端标识
 * @property array  $params     请求参数，密码等字段已脱敏
 * @property array  $changes    字段级变更 [{field,old,new}]，只记变化的字段
 * @property int    $status     结果：1 成功 · 0 失败（见 STATUS_*，注意不是启用/停用）
 * @property string $error_msg  失败原因
 * @property int    $duration   耗时，毫秒
 * @property Carbon $created_at 创建时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Support\Carbon;

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
