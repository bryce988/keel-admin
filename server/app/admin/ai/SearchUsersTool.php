<?php
/**
 * keel admin
 * 小k 工具：查用户列表
 *
 * 封装 `UserService::listQuery()` + `rowMapper()`，与「系统管理 / 用户管理」列表同一条链路：
 * 数据权限、字段脱敏都在那里生效，这里一行过滤都不写。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\UserService;
use app\common\ai\ToolResult;
use app\common\model\SysDeptModel;

class SearchUsersTool extends QueryTool
{
    public function name(): string
    {
        return 'search_users';
    }

    public function description(): string
    {
        return '查询员工账号列表（系统管理 / 用户管理的数据）。可按姓名/账号/手机号关键词、部门（含下级部门）、状态、创建时间筛选。'
            . '返回 total（符合条件的总数）与前若干条明细。只关心数量时 limit 传 1 并读 total；要按部门/岗位分组统计请用 count_users。'
            . '「新入职」按账号创建时间 created_at 理解。status：1 启用（在职），0 停用。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword'      => ['type' => 'string', 'description' => '姓名、账号或手机号的片段'],
                'dept_id'      => ['type' => 'integer', 'description' => '部门 id，含下级部门。不知道 id 时先用 list_depts 查'],
                'status'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 启用 0 停用；不传为全部'],
                'created_from' => ['type' => 'string', 'description' => '账号创建日期起 YYYY-MM-DD'],
                'created_to'   => ['type' => 'string', 'description' => '账号创建日期止 YYYY-MM-DD'],
                'limit'        => self::limitProp(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword'      => ['string|max:50', '关键词'],
            'dept_id'      => ['integer|min:1', '部门'],
            'status'       => ['integer|in:0,1', '状态'],
            'created_from' => ['date_format:Y-m-d', '创建日期起'],
            'created_to'   => ['date_format:Y-m-d', '创建日期止'],
            'limit'        => ['integer|min:1|max:50', '条数'],
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

    public function sensitive(): array
    {
        return ['phone' => 'phone', 'email' => 'email'];
    }

    public function run(array $args): ToolResult
    {
        $filters = [
            'keyword' => $args['keyword'] ?? null,
            'status'  => $args['status'] ?? '',
            'dept_id' => $args['dept_id'] ?? null,
        ];

        $query = UserService::listQuery($filters);
        if (!empty($args['created_from'])) {
            $query->where('created_at', '>=', $args['created_from'] . ' 00:00:00');
        }
        if (!empty($args['created_to'])) {
            $query->where('created_at', '<=', $args['created_to'] . ' 23:59:59');
        }

        $total  = (clone $query)->count();
        $mapper = UserService::rowMapper();
        $rows   = $query->orderByDesc('id')->limit($this->limit($args))->get()
            ->map(static function ($row) use ($mapper) {
                $r = $mapper($row);
                unset($r['avatar'], $r['is_super']);

                return $r;
            })->all();

        $status = $this->dictLabels('user_status');
        foreach ($rows as &$r) {
            $r['status_text'] = $status[(string) $r['status']] ?? (string) $r['status'];
        }
        unset($r);

        $deptName = null;
        if (!empty($args['dept_id'])) {
            $deptName = SysDeptModel::query()->whereKey($args['dept_id'])->value('name') ?? ('部门#' . $args['dept_id']);
        }

        $label = self::describeFilters('用户列表', [
            $deptName ? "{$deptName}及下级" : null,
            isset($args['status']) ? ($status[(string) $args['status']] ?? null) : null,
            !empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null,
            !empty($args['created_from']) || !empty($args['created_to'])
                ? '创建于 ' . ($args['created_from'] ?? '…') . ' ~ ' . ($args['created_to'] ?? '今天') : null,
        ]);

        return new ToolResult(
            rows: $rows,
            total: $total,
            label: $label,
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/system/user',
                'query' => self::linkQuery([
                    'keyword' => $args['keyword'] ?? null,
                    'status'  => $args['status'] ?? null,
                    'dept_id' => $args['dept_id'] ?? null,
                ]),
                'label' => '在用户列表中查看',
            ],
        );
    }
}
