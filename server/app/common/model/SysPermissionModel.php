<?php
/**
 * keel admin
 * 菜单与权限点 —— sys_permissions
 *
 * 菜单、按钮、接口、字段权限合并成一棵树，靠 type 区分节点性质（见 TYPE_*）。
 * 合成一棵是为了让「授权」只有一个界面：勾一个节点就同时决定了菜单显不显示、
 * 按钮露不露、接口通不通。
 *
 * 这张表不接数据权限，也没有审计字段（auditColumns 返回空）——
 * 权限点是系统配置不是业务数据，由 scripts/seed.php 维护。
 *
 * 字段说明以 database/schema.sql 的列注释为准，改表结构时两边一起改。
 *
 * @property int    $id         主键
 * @property int    $parent_id  上级节点，0 = 顶级
 * @property string $name       显示名称
 * @property int    $type       节点类型：1 目录 · 2 菜单 · 3 按钮 · 4 接口 · 5 字段（见 TYPE_*）
 * @property string $perm_code  权限标识，如 sys:user:create，全局唯一
 * @property string $path       前端路由路径，type=2 用
 * @property string $component  前端组件路径，目录填 Layout
 * @property string $icon       菜单图标（Element Plus 图标名）
 * @property string $api_method 绑定的接口方法，type=4 用
 * @property string $api_path   绑定的接口路径，type=4 用
 * @property bool   $visible    是否显示在侧边栏
 * @property bool   $keep_alive 页面是否缓存
 * @property int    $sort       排序，值越小越靠前
 * @property int    $status     状态：0 停用 · 1 启用（见 HasStatus）
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use app\common\model\concern\HasStatus;
use Illuminate\Support\Carbon;

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
