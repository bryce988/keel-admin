<?php
/**
 * keel admin
 * 数据导出任务 —— sys_export_tasks
 *
 * ⚠️ 这张表**必须**有 `dept_id`：数据权限在非「仅本人」范围下找不到部门列会
 * 直接放行、不加任何条件（`DataScope::apply()`），表现是部门主管能看到全公司的
 * 导出记录——连带着能下载别人的文件。日志表早期就漏过这一列（CLAUDE.md 已踩过的坑），
 * 这里的代价比日志更大，因为导出任务后面挂着一个可下载的文件。
 *
 * 归属人列是 `creator_id` 而不是默认的 `owner_id`：导出任务的归属就是发起人，
 * 「仅本人」范围的账号只看得到自己发起的那些。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int     $id           主键
 * @property string  $biz          业务标识
 * @property string  $biz_name     业务名称（冗余）
 * @property string  $params       导出时的筛选条件（JSON 字符串）
 * @property int     $status       0 排队 · 1 处理中 · 2 已完成 · 3 失败
 * @property int     $row_count    导出行数
 * @property string  $file_name    下载文件名
 * @property string  $file_path    服务器绝对路径，不下发
 * @property int     $file_size    字节数
 * @property string  $error_msg    失败原因
 * @property int     $creator_id   发起人
 * @property string  $creator_name 发起人姓名（冗余）
 * @property int     $dept_id      发起人部门
 * @property ?Carbon $expired_at   文件过期时间
 * @property ?Carbon $started_at   开始处理时间
 * @property ?Carbon $finished_at  完成/失败时间
 * @property Carbon  $created_at   创建时间
 * @property Carbon  $updated_at   更新时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasDataScope;
use Illuminate\Support\Carbon;

class SysExportTaskModel extends BaseModel
{
    use HasDataScope;

    /** 排队中与处理中都算「还没好」，界面据此决定要不要继续轮询 */
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_DONE    = 2;
    public const STATUS_FAILED  = 3;

    protected $table = 'sys_export_tasks';

    protected $casts = [
        'status'      => 'integer',
        'row_count'   => 'integer',
        'file_size'   => 'integer',
        'creator_id'  => 'integer',
        'dept_id'     => 'integer',
        'expired_at'  => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function ownerColumn(): ?string
    {
        return 'creator_id';
    }

    /**
     * 审计列只有 creator_id
     *
     * 没有 updater_id：导出任务不存在「被别人改」这回事，状态流转全由消费进程写。
     * 不覆写的话 HasAudit 会往一个不存在的列写值，插入直接报错。
     */
    public function auditColumns(): array
    {
        return ['creator_id'];
    }
}
