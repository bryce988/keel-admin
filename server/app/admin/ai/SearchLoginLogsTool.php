<?php
/**
 * keel admin
 * 小k 工具：登录日志
 *
 * 封装 `LogService::loginQuery()`，日志本身也受数据权限约束（按登录人部门）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\LogService;
use app\common\ai\ToolResult;

class SearchLoginLogsTool extends QueryTool
{
    public function name(): string
    {
        return 'search_login_logs';
    }

    public function description(): string
    {
        return '查询登录日志：谁、什么时候、从哪个 IP/地点登录，成功还是失败。可按账号/IP/地点关键词、结果、类型、日期筛选（默认近 7 天）。'
            . 'summary 里有按结果分的计数与失败次数最多的账号。status：1 成功 0 失败；type：1 登录 2 登出 3 发送验证码。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '账号、IP 或登录地点片段'],
                'status'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 成功 0 失败'],
                'type'    => ['type' => 'integer', 'enum' => [1, 2, 3], 'description' => '1 登录 2 登出 3 发送验证码'],
                'limit'   => self::limitProp(),
            ] + self::dateProps(),
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword' => ['string|max:50', '关键词'],
            'status'  => ['integer|in:0,1', '结果'],
            'type'    => ['integer|in:1,2,3', '类型'],
            'limit'   => ['integer|min:1|max:50', '条数'],
        ] + self::dateRules();
    }

    public function perm(): string|array
    {
        return 'sys:log:login:list';
    }

    public function permLabel(): string
    {
        return '登录日志';
    }

    public function run(array $args): ToolResult
    {
        $start   = $args['start_date'] ?? date('Y-m-d', strtotime('-6 days'));
        $end     = $args['end_date'] ?? date('Y-m-d');
        $filters = [
            'keyword'    => $args['keyword'] ?? '',
            'status'     => $args['status'] ?? '',
            'type'       => $args['type'] ?? '',
            'start_time' => $start,
            'end_time'   => $end,
        ];

        $query = LogService::loginQuery($filters);
        $total = (clone $query)->count();

        $statusText = $this->dictLabels('log_status');
        $typeText   = $this->dictLabels('login_type');

        $byStatus = [];
        foreach ((clone $query)->reorder()->selectRaw('status as k, count(*) as c')->groupBy('status')->pluck('c', 'k') as $k => $c) {
            $byStatus[$statusText[(string) $k] ?? (string) $k] = (int) $c;
        }
        $topFailed = (clone $query)->reorder()->where('status', 0)
            ->selectRaw('username, count(*) as c')->groupBy('username')->orderByDesc('c')->limit(5)
            ->pluck('c', 'username')->map(fn ($v) => (int) $v)->all();

        $mapper = LogService::loginRowMapper();
        $rows   = $query->orderByDesc('id')->limit($this->limit($args))->get()
            ->map(static function ($row) use ($mapper, $statusText, $typeText) {
                $r = $mapper($row);
                unset($r['user_id']);
                $r['status'] = $statusText[(string) $r['status']] ?? (string) $r['status'];
                $r['type']   = $typeText[(string) $r['type']] ?? (string) $r['type'];

                return $r;
            })->all();

        return new ToolResult(
            rows: $rows,
            total: $total,
            label: self::describeFilters('登录日志', [
                "{$start} ~ {$end}",
                isset($args['status']) ? ($statusText[(string) $args['status']] ?? null) : null,
                !empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null,
            ]),
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/log/login',
                'query' => self::linkQuery([
                    'keyword'    => $args['keyword'] ?? null,
                    'status'     => $args['status'] ?? null,
                    'type'       => $args['type'] ?? null,
                    'date_range' => [$start, $end],
                ]),
                'label' => '在登录日志中查看',
            ],
            summary: ['by_status' => $byStatus, 'top_failed_accounts' => $topFailed],
        );
    }
}
