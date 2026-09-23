<?php
/**
 * keel admin
 * 小k 工具：部门（组织架构）
 *
 * 封装 `DeptService::tree()`：部门与各部门人数都受数据权限约束，
 * 部门主管拿到的是他那棵子树。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\DeptService;
use app\common\ai\ToolResult;

class ListDeptsTool extends QueryTool
{
    public function name(): string
    {
        return 'list_depts';
    }

    public function description(): string
    {
        return '查询部门（组织架构），返回每个部门的 id、名称、上级路径、状态与本级人数（user_count，不含下级）。'
            . '其他工具需要 dept_id 时先用它按名称找 id。可按名称关键词筛选（会保留命中部门的上级链）。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '部门名称片段'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['keyword' => ['string|max:50', '关键词']];
    }

    /** 与 GET /admin/depts/tree 同口径：部门树也是用户列表的筛选条件 */
    public function perm(): string|array
    {
        return ['sys:dept:list', 'sys:user:list'];
    }

    public function permLabel(): string
    {
        return '部门管理';
    }

    public function run(array $args): ToolResult
    {
        $tree = DeptService::tree(['keyword' => $args['keyword'] ?? '']);

        $status = $this->dictLabels('enable_status');
        $rows   = [];
        $walk   = static function (array $nodes, string $path) use (&$walk, &$rows, $status) {
            foreach ($nodes as $n) {
                $full   = $path === '' ? (string) $n['name'] : "{$path} / {$n['name']}";
                $rows[] = [
                    'id'         => (int) $n['id'],
                    'name'       => (string) $n['name'],
                    'path'       => $full,
                    'user_count' => (int) ($n['user_count'] ?? 0),
                    'status'     => $status[(string) $n['status']] ?? (string) $n['status'],
                ];
                $walk((array) ($n['children'] ?? []), $full);
            }
        };
        $walk($tree, '');

        return new ToolResult(
            rows: $rows,
            total: count($rows),
            label: self::describeFilters('部门列表', [!empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null]),
            scopeNote: $this->scopeNote(),
            link: ['path' => '/system/dept', 'query' => [], 'label' => '在部门管理中查看'],
        );
    }
}
