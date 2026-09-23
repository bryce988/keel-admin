<?php
/**
 * keel admin
 * 小k 工具：岗位
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\ai;

use app\admin\service\PostService;
use app\common\ai\ToolResult;

class ListPostsTool extends QueryTool
{
    public function name(): string
    {
        return 'list_posts';
    }

    public function description(): string
    {
        return '查询岗位列表（名称、编码、所属部门、状态）。可按名称关键词、部门（含下级）、状态筛选。'
            . '要知道每个岗位有几个人，用 count_users 并 group_by=post。';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'keyword' => ['type' => 'string', 'description' => '岗位名称或编码片段'],
                'dept_id' => ['type' => 'integer', 'description' => '所属部门 id（含下级部门）'],
                'status'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 启用 0 停用'],
                'limit'   => self::limitProp(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'keyword' => ['string|max:50', '关键词'],
            'dept_id' => ['integer|min:1', '部门'],
            'status'  => ['integer|in:0,1', '状态'],
            'limit'   => ['integer|min:1|max:50', '条数'],
        ];
    }

    public function perm(): string|array
    {
        return 'sys:post:list';
    }

    public function permLabel(): string
    {
        return '岗位管理';
    }

    public function run(array $args): ToolResult
    {
        $filters = [
            'keyword' => $args['keyword'] ?? '',
            'status'  => $args['status'] ?? '',
            'dept_id' => $args['dept_id'] ?? null,
        ];
        $query  = PostService::listQuery($filters);
        $total  = (clone $query)->count();
        $mapper = PostService::rowMapper();
        $status = $this->dictLabels('enable_status');

        $rows = $query->orderBy('sort')->orderBy('id')->limit($this->limit($args))->get()
            ->map(static function ($row) use ($mapper, $status) {
                $r = $mapper($row);

                return [
                    'id'        => $r['id'],
                    'name'      => $r['name'],
                    'code'      => $r['code'],
                    'dept_name' => $r['dept_name'],
                    'status'    => $status[(string) $r['status']] ?? (string) $r['status'],
                    'remark'    => $r['remark'],
                ];
            })->all();

        return new ToolResult(
            rows: $rows,
            total: $total,
            label: self::describeFilters('岗位列表', [!empty($args['keyword']) ? "关键词「{$args['keyword']}」" : null]),
            scopeNote: $this->scopeNote(),
            link: [
                'path'  => '/system/post',
                'query' => self::linkQuery($filters),
                'label' => '在岗位管理中查看',
            ],
        );
    }
}
