<?php
/**
 * keel admin
 * 队列监控
 *
 * 回答三个问题：**跑着几个队列、积压多少、失败的那些怎么办**。
 * 数据全部来自 Redis 的实时状态，没有对应的表，所以也没有数据权限——
 * 队列是全局基础设施，不属于任何部门，看得到它的人由权限点决定
 * （`sys:queue:list`，默认只授给超管与运维角色）。
 *
 * 「重跑」不做：手动触发定时任务等于绕过 `count => 1` 的独占保证，
 * 前一轮还在跑的时候点一下就是两个清理任务同时删同一批数据。
 * 要立刻跑一次，改 cron 规则比开一个后门安全。
 *
 * 本模块通用：权限点声明在 `config/route/admin.php`，不写即 403（fail-closed）。
 * 错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\QueueService;
use app\admin\validation\Queue\FailedListRequest;
use app\admin\validation\Queue\TaskLogListRequest;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class QueueController
{
    /**
     * 队列总览
     * @url GET /admin/queues
     * @perm sys:queue:list
     * @description 队列列表（积压/延迟/失败）、Redis 状态、进程编制、定时任务。
     * Redis 连不上时 `redis.connected = false`，其余几块照常返回——
     * 这时候要看的正是「Redis 挂了」，整页 500 反而把结论藏起来了。
     */
    public function index(Request $request): Response
    {
        return Result::ok(QueueService::overview());
    }

    /**
     * 定时任务执行记录（分页）
     * @url GET /admin/queues/tasks/logs
     * @perm sys:queue:list
     * @description 每次定时任务执行一行：投递时落「排队中」，消费完成后回填结果、
     * 耗时与完成时间。一直停在「排队中」说明投出去了没人消费——那正是要被看见的故障。
     * 支持按 `task_name`、`status` 筛选，默认按投递时间倒序。
     */
    public function taskLogs(TaskLogListRequest $request): Response
    {
        return Paginator::response(
            QueueService::taskLogQuery($request->validated()),
            $request->request(),
            sortable: QueueService::TASK_LOG_SORTABLE,
            defaultField: 'created_at',
            defaultOrder: 'desc',
        );
    }

    /**
     * 失败任务列表（分页）
     * @url GET /admin/queues/failed
     * @perm sys:queue:list
     * @description 重试 `max_attempts` 次仍失败的消息。所有队列共用一条 Redis list，
     * 只取最近 2000 条做筛选与分页（`redis.sampled` 为真说明已超出这个窗口）。
     * 不支持排序：list 里本来就是投递顺序，最新的在最前。
     */
    public function failed(FailedListRequest $request): Response
    {
        $req      = $request->request();
        $pageNum  = max(1, (int) $req->get('page_num', 1));
        $pageSize = min(Paginator::MAX_SIZE, max(1, (int) $req->get('page_size', Paginator::DEFAULT_SIZE)));

        $page = QueueService::failed($request->validated(), $pageNum, $pageSize);

        return Result::page($page['list'], $page['total'], $pageNum, $pageSize);
    }

    /**
     * 重新入队
     * @url POST /admin/queues/failed/{id}/retry
     * @perm sys:queue:retry
     * @description `attempts` 归零后投回原队列，消费者会像新消息一样再走一遍。
     * 消息本身不可编辑——要改参数就重新发起业务操作，改队列里的原始消息
     * 等于伪造一条「用户从没发起过」的任务。
     * @error 404 消息不存在，或已超出可操作的窗口
     * @error 422 消息刚被别人处理掉了 / 消息里没有队列名
     */
    public function retry(Request $request, string $id): Response
    {
        return Result::ok(QueueService::retry($id));
    }

    /**
     * 丢弃失败消息
     * @url DELETE /admin/queues/failed/{id}
     * @perm sys:queue:delete
     * @description **不可恢复**：Redis 之外没有第二份。确定这条任务不需要再跑了再删。
     * @error 404 消息不存在，或已超出可操作的窗口
     */
    public function destroy(Request $request, string $id): Response
    {
        QueueService::discard($id);

        return Result::noContent();
    }
}
