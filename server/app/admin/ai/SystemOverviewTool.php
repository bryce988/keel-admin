<?php
/**
 * keel admin
 * 小k 工具：系统概览
 *
 * 就是首页仪表盘的数据（`DashboardService::overview()`），它内部已经按权限裁剪过：
 * 没有 sys:role:list 的人拿不到角色数，与首页看到的完全一致。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\common\ai\ToolResult;
use app\common\service\DashboardService;

class SystemOverviewTool extends QueryTool
{
    public function name(): string
    {
        return 'system_overview';
    }

    public function description(): string
    {
        return '系统概览（首页仪表盘的数据）：用户/部门/岗位/角色等规模、今日登录与失败次数、近 7 天每日登录趋势、最近几条操作记录、'
            . '数据库与缓存是否正常。问「系统现在什么情况」「今天有多少人登录」这类笼统问题时先用它。';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function perm(): string|array
    {
        return 'sys:dashboard:view';
    }

    public function permLabel(): string
    {
        return '仪表盘';
    }

    public function run(array $args): ToolResult
    {
        $o = DashboardService::overview();

        $summary = [
            'stats'   => array_map(static fn (array $s) => [
                'label' => $s['label'],
                'value' => $s['value'],
                'unit'  => $s['unit'] ?? '',
                'hint'  => $s['hint'] ?? '',
            ], $o['stats']),
            'modules'           => array_map(static fn (array $m) => ['name' => $m['name'], 'count' => $m['count']], $o['modules']),
            'login_trend_7days' => $o['trend'],
            'recent_operations' => $o['recent'],
            'health'            => [
                'db'    => $o['system']['db'] ?? null,
                'redis' => $o['system']['redis'] ?? null,
            ],
        ];

        return new ToolResult(
            rows: [],
            total: count($o['stats']),
            label: '系统概览',
            scopeNote: $this->scopeNote(),
            link: ['path' => '/home/dashboard', 'query' => [], 'label' => '打开仪表盘'],
            summary: $summary,
        );
    }
}
