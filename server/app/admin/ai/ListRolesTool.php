<?php
/**
 * keel admin
 * 小k 工具：角色
 *
 * 角色表不按部门隔离（全局定义），能看角色列表的人看到的都一样，所以不带口径。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\RoleService;
use app\common\ai\ToolResult;

class ListRolesTool extends QueryTool
{
    public function name(): string
    {
        return 'list_roles';
    }

    public function description(): string
    {
        return '查询角色列表：名称、编码、数据范围、状态、成员数、备注。可按名称关键词、状态筛选。'
            . '数据范围决定这个角色的人能看到哪些部门的数据（全部 / 本部门及下属 / 本部门 / 仅本人 / 自定义）。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '角色名称或编码片段'],
                'status'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 启用 0 停用'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword' => ['string|max:50', '关键词'],
            'status'  => ['integer|in:0,1', '状态'],
        ];
    }

    public function perm(): string|array
    {
        return 'sys:role:list';
    }

    public function permLabel(): string
    {
        return '角色管理';
    }

    public function run(array $args): ToolResult
    {
        $filters = ['keyword' => $args['keyword'] ?? '', 'status' => $args['status'] ?? ''];
        $query   = RoleService::listQuery($filters);
        $mapper  = RoleService::rowMapper();
        $scopes  = $this->dictLabels('data_scope');
        $status  = $this->dictLabels('enable_status');

        $rows = $query->orderBy('sort')->orderBy('id')->limit(50)->get()
            ->map(static function ($row) use ($mapper, $scopes, $status) {
                $r = $mapper($row);

                return [
                    'id'           => $r['id'],
                    'name'         => $r['name'],
                    'code'         => $r['code'],
                    'data_scope'   => $scopes[(string) $r['data_scope']] ?? (string) $r['data_scope'],
                    'status'       => $status[(string) $r['status']] ?? (string) $r['status'],
                    'member_count' => $r['member_count'],
                    'is_super'     => $r['is_super_role'],
                    'remark'       => $r['remark'],
                ];
            })->all();

        return new ToolResult(
            rows: $rows,
            total: count($rows),
            label: self::describeFilters('角色列表', [!empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null]),
            link: ['path' => '/system/role', 'query' => self::linkQuery($filters), 'label' => '在角色管理中查看'],
        );
    }
}
