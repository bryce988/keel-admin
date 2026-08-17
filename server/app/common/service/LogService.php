<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\model\SysLoginLogModel;
use app\common\model\SysOperationLogModel;
use app\common\support\Guard;
use app\common\support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * 日志查询（只读）
 *
 * 日志只写不改不删，所以这里没有 create/update/delete——写入分别在
 * OperationLogMiddleware 与 AuthService 里，本类只负责查和导。
 *
 * 两张表都带 `HasDataScope`：日志本身也受数据权限约束，
 * 部门主管只能看到本部门的操作记录。这一点不能靠界面收敛，
 * 「看不到但能查」的日志等于没有隔离。
 */
class LogService
{
    public const OPERATION_SORTABLE = ['id', 'created_at', 'duration', 'status'];
    public const LOGIN_SORTABLE     = ['id', 'created_at', 'status'];

    /**
     * 时间范围的默认跨度（天）
     *
     * 日志是全库最大的表，没有时间范围的查询就是全表扫描。
     * 前端总是会带上范围，但接口不能指望调用方——不传就按最近 7 天兜底，
     * 而不是放行一个无界查询（api.md §10）。
     */
    private const DEFAULT_DAYS = 7;

    /** 导出上限，与用户导出共用同一个参数 */
    private const MAX_EXPORT_KEY = 'sys.export.maxRows';

    // ------------------------------------------------------------ 操作日志

    public static function operationQuery(array $filters): Builder
    {
        $query = SysOperationLogModel::query();

        [$start, $end] = self::timeRange($filters);
        $query->where('created_at', '>=', $start)->where('created_at', '<=', $end);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                    ->orWhere('title', 'like', "%{$keyword}%")
                    ->orWhere('target', 'like', "%{$keyword}%");
            });
        }

        if (!empty($filters['module'])) {
            // 前缀匹配：模块名形如「系统管理/用户」，传「系统管理」要能查到整个大类
            $query->where('module', 'like', $filters['module'] . '%');
        }

        if (($filters['action'] ?? '') !== '') {
            $query->where('action', (int) $filters['action']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (!empty($filters['trace_id'])) {
            // 排障入口：从报错弹窗里的 trace_id 直接定位到那一次请求
            $query->where('trace_id', $filters['trace_id']);
        }

        return $query;
    }

    public static function operationRowMapper(): callable
    {
        // 列表不返回 params / changes：两个 JSON 字段可能很大，
        // 一页 20 行传回去光这两列就是几百 KB，而列表页一列都不显示
        return fn (SysOperationLogModel $row): array => [
            'id'         => $row->id,
            'trace_id'   => $row->trace_id,
            'username'   => $row->username,
            'module'     => $row->module,
            'action'     => $row->action,
            'title'      => $row->title,
            'target'     => $row->target,
            'api_method' => $row->api_method,
            'api_path'   => $row->api_path,
            'ip'         => $row->ip,
            'status'     => $row->status,
            'error_msg'  => $row->error_msg,
            'duration'   => $row->duration,
            // 变更字段数：列表上先给个提示，用户才知道哪几行值得点开看
            'change_count' => is_array($row->changes) ? count($row->changes) : 0,
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public static function operationDetail(int $id): array
    {
        /** @var SysOperationLogModel $log */
        $log = Guard::found(SysOperationLogModel::find($id), '日志不存在');

        return self::operationRowMapper()($log) + [
            'user_id'    => $log->user_id,
            'dept_id'    => $log->dept_id,
            'user_agent' => $log->user_agent,
            'params'     => $log->params ?? [],
            // 字段级 diff，日志最有价值的部分：[{field, old, new}]
            'changes'    => $log->changes ?? [],
        ];
    }

    public static function exportOperation(array $filters): string
    {
        $query = self::assertExportable(self::operationQuery($filters));

        return Spreadsheet::writeXlsx('operation-logs', [
            '时间', '操作人', '模块', '操作', '描述', '对象',
            '方法', '路径', 'IP', '结果', '失败原因', '耗时(ms)', 'TraceID',
        ], function (callable $emit) use ($query) {
            $query->orderByDesc('id')->chunk(500, function ($rows) use ($emit) {
                foreach ($rows as $row) {
                    $emit([
                        $row->created_at?->format('Y-m-d H:i:s') ?? '',
                        $row->username,
                        $row->module,
                        self::ACTION_TEXT[$row->action] ?? '其他',
                        $row->title,
                        $row->target,
                        $row->api_method,
                        $row->api_path,
                        $row->ip,
                        $row->status ? '成功' : '失败',
                        $row->error_msg,
                        $row->duration,
                        $row->trace_id,
                    ]);
                }
            });
        });
    }

    /** 导出用的中文文案。列表页走字典（log_action），导出是纯文本文件，字典帮不上忙 */
    private const ACTION_TEXT = [
        1 => '新增', 2 => '修改', 3 => '删除', 4 => '导出', 5 => '授权', 6 => '其他',
    ];

    // ------------------------------------------------------------ 登录日志

    public static function loginQuery(array $filters): Builder
    {
        $query = SysLoginLogModel::query();

        [$start, $end] = self::timeRange($filters);
        $query->where('created_at', '>=', $start)->where('created_at', '<=', $end);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")->orWhere('ip', 'like', "%{$keyword}%");
            });
        }

        if (($filters['type'] ?? '') !== '') {
            $query->where('type', (int) $filters['type']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }

        return $query;
    }

    public static function loginRowMapper(): callable
    {
        return fn (SysLoginLogModel $row): array => [
            'id'         => $row->id,
            'user_id'    => $row->user_id,
            'username'   => $row->username,
            'ip'         => $row->ip,
            'location'   => $row->location,
            'browser'    => $row->browser,
            'os'         => $row->os,
            'type'       => $row->type,
            'status'     => $row->status,
            'msg'        => $row->msg,
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public static function exportLogin(array $filters): string
    {
        $query = self::assertExportable(self::loginQuery($filters));
        $mapper = self::loginRowMapper();

        return Spreadsheet::writeXlsx('login-logs', [
            '时间', '账号', 'IP', '归属地', '浏览器', '操作系统', '类型', '结果', '说明',
        ], function (callable $emit) use ($query, $mapper) {
            $query->orderByDesc('id')->chunk(500, function ($rows) use ($emit, $mapper) {
                foreach ($rows as $row) {
                    $item = $mapper($row);
                    $emit([
                        $item['created_at'] ?? '',
                        $item['username'],
                        $item['ip'],
                        $item['location'],
                        $item['browser'],
                        $item['os'],
                        $item['type'] === SysLoginLogModel::TYPE_LOGOUT ? '登出' : '登录',
                        $item['status'] ? '成功' : '失败',
                        $item['msg'],
                    ]);
                }
            });
        });
    }

    // ------------------------------------------------------------ 内部

    /**
     * 解析时间范围，缺省补最近 7 天
     *
     * 只给开始或只给结束都要能用：用户在界面上常常只填一头。
     *
     * @return array{0: string, 1: string}
     */
    private static function timeRange(array $filters): array
    {
        $start = trim((string) ($filters['start_time'] ?? ''));
        $end   = trim((string) ($filters['end_time'] ?? ''));

        if ($end === '') {
            $end = date('Y-m-d H:i:s');
        }

        if ($start === '') {
            $start = date('Y-m-d H:i:s', strtotime($end) - self::DEFAULT_DAYS * 86400);
        }

        // 前端的日期选择器常常只给到日期（2026-08-17），补齐成当天最后一秒，
        // 否则「查今天」会查不到今天任何一条
        if (strlen($end) === 10) {
            $end .= ' 23:59:59';
        }
        if (strlen($start) === 10) {
            $start .= ' 00:00:00';
        }

        return [$start, $end];
    }

    /** 导出前的行数闸门，与用户导出同一个上限参数 */
    private static function assertExportable(Builder $query): Builder
    {
        $limit = (int) ParamService::value(self::MAX_EXPORT_KEY, 50000);

        if ($query->toBase()->getCountForPagination() > $limit) {
            throw new BusinessException("导出数据量超过上限 {$limit} 行，请缩小时间范围", 20701);
        }

        return $query;
    }
}
