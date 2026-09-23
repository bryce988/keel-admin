<?php
/**
 * keel admin
 * 小k 工具：操作日志
 *
 * 封装 `LogService::operationQuery()`。列表口径不含请求参数与字段变更明细
 * （与操作日志列表页一致，那两列要 `sys:log:operation:detail` 才能看）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\LogService;
use app\common\ai\ToolResult;

class SearchOperationLogsTool extends QueryTool
{
    public function name(): string
    {
        return 'search_operation_logs';
    }

    public function description(): string
    {
        return '查询操作日志：谁在什么时候做了什么操作（新增/修改/删除/导出/授权…）、操作对象、成功与否。'
            . '可按操作人/标题/对象关键词、模块（前缀匹配，如「系统管理」「系统管理/角色」）、操作类型、结果、日期筛选（默认近 7 天）。'
            . 'action：1 新增 2 修改 3 删除 4 导出 5 授权 6 其他；status：1 成功 0 失败。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '操作人账号、操作标题或操作对象的片段'],
                'module'  => ['type' => 'string', 'description' => '模块前缀，如「系统管理/角色」'],
                'action'  => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6], 'description' => '操作类型'],
                'status'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 成功 0 失败'],
                'limit'   => self::limitProp(),
            ] + self::dateProps(),
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword' => ['string|max:50', '关键词'],
            'module'  => ['string|max:64', '模块'],
            'action'  => ['integer|in:1,2,3,4,5,6', '操作类型'],
            'status'  => ['integer|in:0,1', '结果'],
            'limit'   => ['integer|min:1|max:50', '条数'],
        ] + self::dateRules();
    }

    public function perm(): string|array
    {
        return 'sys:log:operation:list';
    }

    public function permLabel(): string
    {
        return '操作日志';
    }

    public function run(array $args): ToolResult
    {
        $start   = $args['start_date'] ?? date('Y-m-d', strtotime('-6 days'));
        $end     = $args['end_date'] ?? date('Y-m-d');
        $filters = [
            'keyword'    => $args['keyword'] ?? '',
            'module'     => $args['module'] ?? '',
            'action'     => $args['action'] ?? '',
            'status'     => $args['status'] ?? '',
            'start_time' => $start,
            'end_time'   => $end,
        ];

        $query  = LogService::operationQuery($filters);
        $total  = (clone $query)->count();
        $action = $this->dictLabels('log_action');
        $status = $this->dictLabels('log_status');
        $mapper = LogService::operationRowMapper();

        $rows = $query->orderByDesc('id')->limit($this->limit($args))->get()
            ->map(static function ($row) use ($mapper, $action, $status) {
                $r = $mapper($row);

                return [
                    'created_at' => $r['created_at'],
                    'username'   => $r['username'],
                    'module'     => $r['module'],
                    'action'     => $action[(string) $r['action']] ?? (string) $r['action'],
                    'title'      => $r['title'],
                    'target'     => $r['target'],
                    'status'     => $status[(string) $r['status']] ?? (string) $r['status'],
                    'error_msg'  => $r['error_msg'],
                    'ip'         => $r['ip'],
                ];
            })->all();

        return new ToolResult(
            rows: $rows,
            total: $total,
            label: self::describeFilters('操作日志', [
                "{$start} ~ {$end}",
                !empty($args['module']) ? "模块「{$args['module']}」" : null,
                isset($args['action']) ? ($action[(string) $args['action']] ?? null) : null,
                !empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null,
            ]),
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/log/operation',
                'query' => self::linkQuery([
                    'keyword'    => $args['keyword'] ?? null,
                    'module'     => $args['module'] ?? null,
                    'action'     => $args['action'] ?? null,
                    'status'     => $args['status'] ?? null,
                    'date_range' => [$start, $end],
                ]),
                'label' => '在操作日志中查看',
            ],
        );
    }
}
