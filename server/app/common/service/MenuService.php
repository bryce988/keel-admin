<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\ConflictException;
use app\common\model\SysPermissionModel;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;

/**
 * 菜单与权限点（RBAC 的**定义**层）
 *
 * 这里只定义「系统里存在哪些权限」，**不做授权**——把权限给谁是角色管理的事。
 * 三层职责分离：定义（本模块）→ 授权（角色）→ 分配（用户）。
 *
 * 菜单树同时驱动前端路由：`path` 与 `component` 一改，用户刷新页面就生效，
 * 不用发版。这也意味着改错了会让整个页面打不开，所以校验要严。
 */
class MenuService
{
    /** 全量树，含停用节点与按钮/接口/数据类节点，供管理界面使用 */
    public static function tree(array $filters = []): array
    {
        // ⚠️ 筛选**不能写进 SQL**：按钮挂在菜单下，直接 where type=3 会把父菜单一起滤掉，
        // buildTree 从根节点找不到任何孩子，结果是一棵空树。
        // 树形筛选的正确语义是「命中的节点连同它的祖先链一起保留」。
        $nodes = SysPermissionModel::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (SysPermissionModel $p) => [
            'id'         => $p->id,
            'parent_id'  => $p->parent_id,
            'name'       => $p->name,
            'type'       => $p->type,
            'perm_code'  => $p->perm_code,
            'path'       => $p->path,
            'component'  => $p->component,
            'icon'       => $p->icon,
            'api_method' => $p->api_method,
            'api_path'   => $p->api_path,
            'visible'    => $p->visible,
            'keep_alive' => $p->keep_alive,
                'sort'       => $p->sort,
                'status'     => $p->status,
            ])
            ->all();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $type    = ($filters['type'] ?? '') === '' ? null : (int) $filters['type'];
        $status  = ($filters['status'] ?? '') === '' ? null : (int) $filters['status'];

        if ($keyword !== '' || $type !== null || $status !== null) {
            $nodes = self::filterKeepingAncestors($nodes, function (array $n) use ($keyword, $type, $status) {
                if ($keyword !== ''
                    && !str_contains($n['name'], $keyword)
                    && !str_contains($n['perm_code'], $keyword)) {
                    return false;
                }
                if ($type !== null && $n['type'] !== $type) {
                    return false;
                }

                return !($status !== null && $n['status'] !== $status);
            });
        }

        return self::buildTree($nodes, 0);
    }

    public static function detail(int $id): array
    {
        /** @var SysPermissionModel $node */
        $node = Guard::found(SysPermissionModel::find($id));

        return $node->toArray();
    }

    public static function create(array $data): SysPermissionModel
    {
        self::assertValid($data, null);

        return Db::transaction(function () use ($data) {
            $node = new SysPermissionModel();
            $node->fill(self::normalize($data));
            $node->save();

            OpLog::target("权限点 {$node->name}({$node->perm_code})");
            self::invalidatePermissionCache();

            return $node;
        });
    }

    public static function update(int $id, array $data): SysPermissionModel
    {
        /** @var SysPermissionModel $node */
        $node = Guard::found(SysPermissionModel::find($id));

        self::assertValid($data, $id);
        Guard::noCycle(
            SysPermissionModel::class,
            $id,
            (int) ($data['parent_id'] ?? $node->parent_id),
            '上级菜单不能是自己或其子节点', BizCode::MENU_CYCLE);

        $before = $node->toArray();

        return Db::transaction(function () use ($node, $data, $before) {
            $node->fill(self::normalize($data));
            $node->save();

            OpLog::target("权限点 {$node->name}({$node->perm_code})");
            OpLog::diff($before, $node->toArray());
            self::invalidatePermissionCache();

            return $node;
        });
    }

    public static function delete(int $id): void
    {
        /** @var SysPermissionModel $node */
        $node = Guard::found(SysPermissionModel::find($id));

        Guard::notReferenced(
            SysPermissionModel::class,
            'parent_id',
            $id,
            // 与 20402「被角色引用」是两件事：这条的出路是先删子节点，
            // 那条的出路是改为停用，不能共用一个码
            '该节点下还有子节点，请先删除子节点',
            BizCode::MENU_HAS_CHILDREN,
        );

        // 被角色引用的权限点**只能停用不能删**：直接删掉会让已授权的角色
        // 悄悄少一项权限，而管理员在角色页上看不到任何痕迹
        if (Db::table('sys_role_permissions')->where('permission_id', $id)->exists()) {
            throw new ConflictException('该权限点已被角色引用，请改为停用', BizCode::PERM_IN_USE);
        }

        OpLog::target("权限点 {$node->name}({$node->perm_code})");

        $node->delete();
        self::invalidatePermissionCache();
    }

    // ---------------------------------------------------------------- 内部

    /**
     * 权限点变了要让缓存失效
     *
     * `PermissionService` 的 Redis 缓存 key 里带 perm_version，改名、停用、删除
     * 都会让缓存里的权限标识过时。菜单维护是低频操作，全表递增一次最省心，
     * 比精确算出「哪些用户受影响」可靠得多——算漏了就是权限判断错。
     */
    private static function invalidatePermissionCache(): void
    {
        Db::table('sys_users')->increment('perm_version');
    }

    private static function assertValid(array $data, ?int $exceptId): void
    {
        Guard::unique(
            SysPermissionModel::class,
            'perm_code',
            $data['perm_code'],
            $exceptId,
            '权限标识已存在',
            BizCode::PERM_CODE_EXISTS,
        );

        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            Guard::found(SysPermissionModel::find($parentId), '上级菜单不存在');
        }
    }

    /** 按节点类型清掉不适用的字段，避免脏数据（按钮存了 component 之类） */
    private static function normalize(array $data): array
    {
        $type = (int) ($data['type'] ?? SysPermissionModel::TYPE_MENU);

        $data['type'] = $type;

        if (!in_array($type, [SysPermissionModel::TYPE_DIR, SysPermissionModel::TYPE_MENU], true)) {
            $data['path']       = '';
            $data['component']  = '';
            $data['icon']       = '';
            $data['visible']    = 0;
            $data['keep_alive'] = 0;
        }

        if ($type !== SysPermissionModel::TYPE_API) {
            $data['api_method'] = '';
            $data['api_path']   = '';
        }

        return $data;
    }

    /**
     * 命中 $match 的节点，连同它的整条祖先链一起保留
     *
     * 树形筛选与列表筛选的语义不同：列表里滤掉不匹配的行就行，
     * 树里滤掉一个父节点会让它下面所有命中的子节点一起消失。
     */
    private static function filterKeepingAncestors(array $nodes, callable $match): array
    {
        $byId = array_column($nodes, null, 'id');
        $keep = [];

        foreach ($nodes as $node) {
            if (!$match($node)) {
                continue;
            }

            $keep[$node['id']] = true;
            $cursor = $node['parent_id'];
            while ($cursor > 0 && isset($byId[$cursor])) {
                $keep[$cursor] = true;
                $cursor = $byId[$cursor]['parent_id'];
            }
        }

        return array_values(array_filter($nodes, fn ($n) => isset($keep[$n['id']])));
    }

    private static function buildTree(array $rows, int $parentId): array
    {
        $tree = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== $parentId) {
                continue;
            }
            $children = self::buildTree($rows, $row['id']);
            if ($children) {
                $row['children'] = $children;
            }
            $tree[] = $row;
        }

        return $tree;
    }
}
