<?php

declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;
/**
 * 菜单与权限点
 *
 * 菜单、按钮、接口、字段权限合并为一棵树，type 区分节点性质：
 * 1 目录 · 2 菜单 · 3 按钮 · 4 接口 · 5 数据(字段)
 */
class SysPermissionModel extends BaseModel
{
    use HasStatus;

    public const TYPE_DIR    = 1;   // 目录，只用来分组。无子节点时要剪掉，否则侧边栏有点开空白的死条目
    public const TYPE_MENU   = 2;   // 菜单，对应一个前端页面。无子节点是正常叶子，不能剪
    public const TYPE_BUTTON = 3;   // 按钮，前端用 v-permission 收敛界面，不是安全边界
    public const TYPE_API    = 4;   // 接口，route.php 里 perm 声明的就是它，真正的拦截在这一层
    public const TYPE_FIELD  = 5;   // 字段，控制敏感字段返回明文还是脱敏值，如 sys:field:user:phone

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
