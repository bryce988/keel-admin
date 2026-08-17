<?php

declare(strict_types=1);

namespace app\common\model;

/**
 * 菜单与权限点
 *
 * 菜单、按钮、接口、字段权限合并为一棵树，type 区分节点性质：
 * 1 目录 · 2 菜单 · 3 按钮 · 4 接口 · 5 数据(字段)
 */
class SysPermissionModel extends BaseModel
{
    public const TYPE_DIR    = 1;
    public const TYPE_MENU   = 2;
    public const TYPE_BUTTON = 3;
    public const TYPE_API    = 4;
    public const TYPE_FIELD  = 5;

    protected $table = 'sys_permissions';

    protected $casts = [
        'parent_id'  => 'integer',
        'type'       => 'integer',
        'visible'    => 'boolean',
        'keep_alive' => 'boolean',
        'sort'       => 'integer',
        'status'     => 'integer',
    ];

    public function auditColumns(): array
    {
        return [];
    }

    /**
     * 扁平节点转树
     *
     * @param  array  $nodes  已按 sort 排好序的节点数组（数据库原始键名）
     * @param  callable|null  $map  节点映射函数，不传则原样输出
     */
    public static function toTree(array $nodes, int $parentId = 0, ?callable $map = null): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            if ((int) $node['parent_id'] !== $parentId) {
                continue;
            }
            $item     = $map ? $map($node) : $node;
            $children = self::toTree($nodes, (int) $node['id'], $map);
            if ($children) {
                $item['children'] = $children;
            }
            $tree[] = $item;
        }

        return $tree;
    }
}
