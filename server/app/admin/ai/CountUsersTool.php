<?php
/**
 * keel admin
 * 小k 工具：员工人数统计（可分组）
 *
 * 模型不该拉 500 行回去自己数，所以单独给一个聚合工具。
 *
 * ⚠️ 聚合**必须**建在 `UserService::listQuery()` 返回的 Builder 上（带数据权限 Scope），
 * 不能另起 `Db::table('sys_users')`：那样「各部门人数」会把范围外的部门也数进来——
 * 比泄露明细更隐蔽，因为一个数字看不出它越界了（docs/ai-tech.md §5.6）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\UserService;
use app\common\ai\ToolResult;
use app\common\model\SysDeptModel;
use app\common\model\SysPostModel;

class CountUsersTool extends QueryTool
{
    public function name(): string
    {
        return 'count_users';
    }

    public function description(): string
    {
        return '统计员工人数，可按部门（dept）、岗位（post）或状态（status）分组。筛选条件同 search_users。'
            . '问「多少人」「各部门人数」「每个岗位几个人」时用它，不要用 search_users 拉明细再自己数。'
            . '按部门分组时是各部门**本级**的人数，不含下级部门。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'group_by'     => ['type' => 'string', 'enum' => ['none', 'dept', 'post', 'status'], 'description' => '分组维度，默认 none 只给总数'],
                'dept_id'      => ['type' => 'integer', 'description' => '只统计这个部门（含下级部门）'],
                'status'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 启用（在职）0 停用；不传为全部'],
                'created_from' => ['type' => 'string', 'description' => '账号创建日期起 YYYY-MM-DD'],
                'created_to'   => ['type' => 'string', 'description' => '账号创建日期止 YYYY-MM-DD'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'group_by'     => ['string|in:none,dept,post,status', '分组'],
            'dept_id'      => ['integer|min:1', '部门'],
            'status'       => ['integer|in:0,1', '状态'],
            'created_from' => ['date_format:Y-m-d', '创建日期起'],
            'created_to'   => ['date_format:Y-m-d', '创建日期止'],
        ];
    }

    public function perm(): string|array
    {
        return 'sys:user:list';
    }

    public function permLabel(): string
    {
        return '用户管理';
    }

    public function run(array $args): ToolResult
    {
        $query = UserService::listQuery([
            'status'  => $args['status'] ?? '',
            'dept_id' => $args['dept_id'] ?? null,
        ]);
        if (!empty($args['created_from'])) {
            $query->where('created_at', '>=', $args['created_from'] . ' 00:00:00');
        }
        if (!empty($args['created_to'])) {
            $query->where('created_at', '<=', $args['created_to'] . ' 23:59:59');
        }

        // 聚合不需要 with() 的预加载，去掉免得白跑两条查询
        $query->setEagerLoads([]);

        $total   = (clone $query)->count();
        $groupBy = (string) ($args['group_by'] ?? 'none');
        $groups  = [];

        if ($groupBy !== 'none') {
            $column = ['dept' => 'dept_id', 'post' => 'post_id', 'status' => 'status'][$groupBy];
            $counts = (clone $query)->reorder()
                ->selectRaw("{$column} as k, count(*) as c")
                ->groupBy($column)
                ->pluck('c', 'k')
                ->all();

            // 名称也走带数据权限的模型：部门主管看到的部门名，只会是他看得到的那些
            $names = match ($groupBy) {
                'dept'   => SysDeptModel::query()->whereIn('id', array_keys($counts))->pluck('name', 'id')->all(),
                'post'   => SysPostModel::query()->whereIn('id', array_keys($counts))->pluck('name', 'id')->all(),
                'status' => $this->dictLabels('user_status'),
            };

            arsort($counts);
            foreach ($counts as $k => $c) {
                $name = $names[$k] ?? match ($groupBy) {
                    'dept'   => (int) $k === 0 ? '未分配部门' : "部门#{$k}",
                    'post'   => (int) $k === 0 ? '未设置岗位' : "岗位#{$k}",
                    default  => (string) $k,
                };
                $groups[] = ['name' => $name, 'count' => (int) $c];
            }
        }

        $status = $this->dictLabels('user_status');
        $label  = self::describeFilters('人数统计', [
            !empty($args['dept_id']) ? ((SysDeptModel::query()->whereKey($args['dept_id'])->value('name') ?? '部门#' . $args['dept_id']) . '及下级') : null,
            isset($args['status']) ? ($status[(string) $args['status']] ?? null) : null,
            $groupBy !== 'none' ? ['dept' => '按部门', 'post' => '按岗位', 'status' => '按状态'][$groupBy] : null,
        ]);

        return new ToolResult(
            rows: [],
            total: $total,
            label: $label,
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/system/user',
                'query' => self::linkQuery(['status' => $args['status'] ?? null, 'dept_id' => $args['dept_id'] ?? null]),
                'label' => '在用户列表中查看',
            ],
            summary: $groups ? ['groups' => $groups] : [],
        );
    }
}
