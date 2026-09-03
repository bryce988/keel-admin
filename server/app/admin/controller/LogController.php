<?php
/**
 * keel admin
 * 日志审计（只读）
 *
 * 日志没有写接口：操作日志由 OperationLogMiddleware 落库，登录日志由 AuthService 落库。
 * 这里能改能删的话，审计就失去意义了。
 *
 * 两张表都带数据权限全局 Scope，部门主管只看得到本部门的记录——
 * 不需要在这里手写归属过滤。
 *
 * 本模块通用，各方法不再重复：权限点声明在 `config/route.php`，不写即 403（fail-closed）；
 * 入参校验见 `app\admin\validation\Log\*`，失败一律 422 + 字段级 `details`；
 * 不传时间范围时 service 兜底成最近 7 天（日志表是全系统最大的表，无界查询能把库拖垮）；
 * 导出动作自身也会留一条操作日志。错误码表见 docs/api.md §2.2。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Log\LoginListRequest;
use app\admin\validation\Log\OperationListRequest;
use app\common\service\ExportService;
use app\admin\service\LogService;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Response;
use Webman\Http\Request;

class LogController
{
    // ------------------------------------------------------------ 操作日志

    /**
     * 操作日志列表（分页）
     * @url GET /admin/logs/operation
     * @perm sys:log:operation:list
     * @description 默认按 id 倒序——日志天然是最新的在最上面。
     * 越权被拒的尝试同样在列（status=0），「谁试图做什么但被拒了」和「谁做成了什么」
     * 在审计上一样重要。
     */
    public function operation(OperationListRequest $request): Response
    {
        return Paginator::response(
            LogService::operationQuery($request->validated()),
            $request->request(),   // 分页与排序参数不在 OperationListRequest 白名单里，走原始 Request
            sortable: LogService::OPERATION_SORTABLE,
            // 日志天然按时间倒序看，最新的在最上面
            defaultField: 'id',
            defaultOrder: 'desc',
            map: LogService::operationRowMapper(),
        );
    }

    /**
     * 操作日志详情
     * @url GET /admin/logs/operation/{id}
     * @perm sys:log:operation:list
     * @description 比列表多出请求参数与字段级变更明细（`changes`）。参数里的手机号等敏感字段
     * 在落库时就已经按字段级权限脱敏，不是查询时才处理——
     * 否则日志表本身会成为绕过字段权限的后门。
     */
    public function operationDetail(Request $request, int $id): Response
    {
        return Result::ok(LogService::operationDetail($id));
    }

    /**
     * 发起导出操作日志
     * @url GET /admin/logs/operation/export
     * @perm sys:log:operation:export
     * @description **不直接返回文件**，建任务投队列后返回 202 +`{task_id}`，
     * 到「数据管理 / 数据导出」下载（原因见 `ExportService` 顶部）。
     * 筛选条件与列表接口完全一致（共用 {@see OperationListRequest}），
     * 导出的就是你在界面上看到的那批数据，不是全表。
     */
    public function exportOperation(OperationListRequest $request): Response
    {
        $task = ExportService::enqueue('log_operation', $request->validated());

        return Result::accepted([
            'task_id' => $task->id,
            'message' => '已加入导出队列，完成后可在「数据管理 / 数据导出」下载',
        ]);
    }

    // ------------------------------------------------------------ 登录日志

    /**
     * 登录日志列表（分页）
     * @url GET /admin/logs/login
     * @perm sys:log:login:list
     * @description 登录失败同样记录（status=0），连续失败锁定的判定就依赖这张表。
     */
    public function login(LoginListRequest $request): Response
    {
        return Paginator::response(
            LogService::loginQuery($request->validated()),
            $request->request(),   // 分页与排序参数不在 LoginListRequest 白名单里，走原始 Request
            sortable: LogService::LOGIN_SORTABLE,
            defaultField: 'id',
            defaultOrder: 'desc',
            map: LogService::loginRowMapper(),
        );
    }

    /**
     * 发起导出登录日志
     * @url GET /admin/logs/login/export
     * @perm sys:log:login:export
     * @description 与操作日志导出同一套：建任务、投队列、返回 202。
     */
    public function exportLogin(LoginListRequest $request): Response
    {
        $task = ExportService::enqueue('log_login', $request->validated());

        return Result::accepted([
            'task_id' => $task->id,
            'message' => '已加入导出队列，完成后可在「数据管理 / 数据导出」下载',
        ]);
    }
}
