<?php
/**
 * keel admin
 * 定时任务执行日志 —— sys_task_logs
 *
 * 一次执行一行。投递时插入（`STATUS_PENDING`），消费完成后回填结果、耗时与完成时间。
 *
 * **不挂 `HasDataScope`**：定时任务是全局基础设施，不属于任何部门，
 * 表里也就没有 `dept_id`。这两件事必须一致——`DataScope` 找不到部门列时会
 * **直接放行不加条件**，挂了 trait 却没有列，等于给所有人开了全量可见
 * （CLAUDE.md「日志表漏 dept_id」那条坑）。谁能看由权限点 `sys:queue:list` 决定。
 *
 * 与两张业务日志表一样：只写不改（这里的「改」只有一次结果回填），不做软删，
 * 保留天数到期由 `LogCleanupService` 硬删。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int         $id          主键
 * @property string      $task_name   任务标识，见 TaskProcess::TASKS 的 name
 * @property string      $task_desc   任务说明，投递那一刻的措辞，冗余存储
 * @property string      $queue       投递到的队列名
 * @property string      $trigger     触发方式，目前只有 cron
 * @property int         $status      0 排队中 · 1 成功 · 2 失败（见 STATUS_*）
 * @property string      $message     结果摘要或错误信息
 * @property int         $duration_ms 消费耗时（毫秒），不含排队时间
 * @property string|null $finished_at 完成时间，未完成为 null
 * @property Carbon      $created_at  投递时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

class SysTaskLogModel extends BaseModel
{
    /**
     * 执行状态
     *
     * 「排队中」是有意义的终态之一：消费者被删或队列名拼错时不会抛任何异常，
     * 表现就是这一行永远停在排队中——那正是要被看见的故障。
     */
    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAIL    = 2;

    protected $table = 'sys_task_logs';

    protected $casts = [
        'status'      => 'int',
        'duration_ms' => 'int',
    ];

    /**
     * 没有审计列
     *
     * 定时任务由进程按点触发，没有「操作人」这回事——表里也就没建
     * `creator_id` / `updater_id`。不覆写的话 `HasAudit` 会在 creating 时
     * 塞这两个字段，insert 直接报 `Unknown column 'creator_id'`
     * （而 TaskLogService 会把异常吞掉记日志，表现是「执行记录一条都不写」，
     * 界面上没有任何报错）。
     */
    public function auditColumns(): array
    {
        return [];
    }
}
