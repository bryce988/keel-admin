<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\LogService;
use app\common\support\OpLog;
use app\common\support\Paginator;
use app\common\support\Result;
use app\common\support\Validator;
use support\Response;
use Webman\Http\Request;

/**
 * 日志审计（只读）
 *
 * 日志没有写接口：操作日志由 OperationLogMiddleware 落库，
 * 登录日志由 AuthService 落库。这里能改能删的话，审计就失去意义了。
 *
 * 两张表都带数据权限全局 Scope，部门主管只看得到本部门的记录——
 * 不需要在这里手写归属过滤（CLAUDE.md：禁止业务代码手写归属过滤）。
 */
class LogController
{
    // ------------------------------------------------------------ 操作日志

    /**
     * 操作日志列表（分页）
     *
     * `GET /admin/logs/operation` · 权限点 `sys:log:operation:list`
     *
     * 默认按 id 倒序——日志天然是最新的在最上面。
     * **越权被拒的尝试同样在列**（status=0），「谁试图做什么但被拒了」
     * 和「谁做成了什么」在审计上一样重要。
     *
     * @param Request $request 查询参数见 {@see self::operationFilters()}
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function operation(Request $request): Response
    {
        return Paginator::response(
            LogService::operationQuery(self::operationFilters($request)),
            $request,
            sortable: LogService::OPERATION_SORTABLE,
            // 日志天然按时间倒序看，最新的在最上面
            defaultField: 'id',
            defaultOrder: 'desc',
            map: LogService::operationRowMapper(),
        );
    }

    /**
     * 操作日志详情
     *
     * `GET /admin/logs/operation/{id}` · 权限点 `sys:log:operation:list`
     *
     * 比列表多出请求参数与字段级变更明细（`changes`）。参数里的手机号等敏感字段
     * 在**落库时**就已经按字段级权限脱敏，不是查询时才处理——
     * 否则日志表本身会成为绕过字段权限的后门。
     *
     * @param Request $request 无查询参数
     * @param int     $id      日志 ID
     *
     * @return Response 200，日志对象（含 `params`、`changes`、`trace_id`）
     *
     * @throws \app\common\exception\NotFoundException 日志不存在，或不在你的数据范围内（404 + `10404`）
     */
    public function operationDetail(Request $request, int $id): Response
    {
        return Result::ok(LogService::operationDetail($id));
    }

    /**
     * 导出操作日志
     *
     * `GET /admin/logs/operation/export` · 权限点 `sys:log:operation:export` · 自动落操作日志
     *
     * 筛选条件与列表接口完全一致（共用 {@see self::operationFilters()}），
     * 导出的就是你在界面上看到的那批数据，**不是全表**。
     * 导出动作自身也会留一条操作日志，对象是生成的文件名。
     *
     * @param Request $request 查询参数见 {@see self::operationFilters()}
     *
     * @return Response 200，xlsx 文件流，文件名 `操作日志_YYYYmmdd_HHiiss.xlsx`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function exportOperation(Request $request): Response
    {
        $path = LogService::exportOperation(self::operationFilters($request));

        OpLog::target('导出操作日志 ' . basename($path));

        return Result::download($path, '操作日志_' . date('Ymd_His') . '.xlsx');
    }

    // ------------------------------------------------------------ 登录日志

    /**
     * 登录日志列表（分页）
     *
     * `GET /admin/logs/login` · 权限点 `sys:log:login:list`
     *
     * 登录失败同样记录（status=0），连续失败锁定的判定就依赖这张表。
     *
     * @param Request $request 查询参数见 {@see self::loginFilters()}
     *
     * @return Response 200，`{list, total, page, page_size}`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function login(Request $request): Response
    {
        return Paginator::response(
            LogService::loginQuery(self::loginFilters($request)),
            $request,
            sortable: LogService::LOGIN_SORTABLE,
            defaultField: 'id',
            defaultOrder: 'desc',
            map: LogService::loginRowMapper(),
        );
    }

    /**
     * 导出登录日志
     *
     * `GET /admin/logs/login/export` · 权限点 `sys:log:login:export` · 自动落操作日志
     *
     * @param Request $request 查询参数见 {@see self::loginFilters()}
     *
     * @return Response 200，xlsx 文件流，文件名 `登录日志_YYYYmmdd_HHiiss.xlsx`
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    public function exportLogin(Request $request): Response
    {
        $path = LogService::exportLogin(self::loginFilters($request));

        OpLog::target('导出登录日志 ' . basename($path));

        return Result::download($path, '登录日志_' . date('Ymd_His') . '.xlsx');
    }

    // ------------------------------------------------------------ 入参

    /**
     * 操作日志的查询条件（列表与导出共用）
     *
     * 共用一份是必须的：分开写迟早出现「界面上筛出 20 条，导出来 2000 条」。
     *
     * **不传时间范围会兜底成最近 7 天**（在 LogService 里做）。前端总是会带范围，
     * 但接口不能指望调用方——日志表是全系统最大的表，无界查询能把库拖垮。
     *
     * @param Request $request 查询参数：`keyword` 操作人/描述/对象模糊匹配、`module` 模块、
     *                         `action` 1 新增 2 修改 3 删除 4 导出 5 授权 6 其他、
     *                         `status` 0 失败 1 成功、`trace_id` 链路 ID（排障时最有用）、
     *                         `start_time` / `end_time` 时间范围（`Y-m-d H:i:s`）
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function operationFilters(Request $request): array
    {
        return Validator::make($request->all(), [
            'keyword'    => ['string|max:64',  '关键词'],
            'module'     => ['string|max:64',  '模块'],
            'action'     => ['in:1,2,3,4,5,6', '操作类型'],
            'status'     => ['in:0,1',         '执行结果'],
            'trace_id'   => ['string|max:64',  'TraceID'],
            'start_time' => ['string|max:19',  '开始时间'],
            'end_time'   => ['string|max:19',  '结束时间'],
        ])->validated();
    }

    /**
     * 登录日志的查询条件（列表与导出共用）
     *
     * 同样不传时间范围会兜底成最近 7 天。
     *
     * @param Request $request 查询参数：`keyword` 账号/IP/归属地模糊匹配、
     *                         `type` 1 登录 2 登出、`status` 0 失败 1 成功、
     *                         `start_time` / `end_time` 时间范围（`Y-m-d H:i:s`）
     *
     * @return array 只含白名单内字段的数组
     *
     * @throws \app\common\exception\ValidationException 参数不合法（422 + `10422`）
     */
    private static function loginFilters(Request $request): array
    {
        return Validator::make($request->all(), [
            'keyword'    => ['string|max:64', '关键词'],
            'type'       => ['in:1,2',        '类型'],
            'status'     => ['in:0,1',        '执行结果'],
            'start_time' => ['string|max:19', '开始时间'],
            'end_time'   => ['string|max:19', '结束时间'],
        ])->validated();
    }
}
