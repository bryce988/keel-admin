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

    public function operationDetail(Request $request, int $id): Response
    {
        return Result::ok(LogService::operationDetail($id));
    }

    public function exportOperation(Request $request): Response
    {
        $path = LogService::exportOperation(self::operationFilters($request));

        OpLog::target('导出操作日志 ' . basename($path));

        return Result::download($path, '操作日志_' . date('Ymd_His') . '.xlsx');
    }

    // ------------------------------------------------------------ 登录日志

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

    public function exportLogin(Request $request): Response
    {
        $path = LogService::exportLogin(self::loginFilters($request));

        OpLog::target('导出登录日志 ' . basename($path));

        return Result::download($path, '登录日志_' . date('Ymd_His') . '.xlsx');
    }

    // ------------------------------------------------------------ 入参

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
