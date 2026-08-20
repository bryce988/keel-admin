<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\ConflictException;
use app\common\model\SysPermissionModel;
use app\common\model\SysRoleModel;
use app\common\model\SysUserModel;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * 角色（RBAC 的授权层）
 *
 * 三层职责分离：定义（菜单权限）→ 授权（本模块） → 分配（用户）。
 * 这里决定「某个角色能干什么、能看到哪些数据」，不决定「谁是这个角色」——
 * 那是用户管理的事。成员管理是本模块唯一一处碰到「人」的地方，
 * 因为从角色视角批量加人是真实需求，但它复用的是同一套分配校验。
 */
class RoleService
{
    public const SORTABLE = ['id', 'sort', 'status', 'created_at'];

    /** 字段级权限走 type=5 的权限点，与功能权限一起授（见 docs/database.md §3.9） */
    public const FIELD_PERM_TYPE = SysPermissionModel::TYPE_FIELD;

    public static function listQuery(array $filters): Builder
    {
        $query = SysRoleModel::query();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (int) $filters['status']);
        }
        if (($filters['data_scope'] ?? '') !== '') {
            $query->where('data_scope', (int) $filters['data_scope']);
        }

        return $query;
    }

    public static function rowMapper(): callable
    {
        // 成员数一次分组查出来，别在每行里 count
        $memberCounts = Db::table('sys_user_roles')
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return fn (SysRoleModel $row): array => [
            'id'           => $row->id,
            'name'         => $row->name,
            'code'         => $row->code,
            'parent_id'    => $row->parent_id,
            'data_scope'   => $row->data_scope,
            'is_builtin'   => $row->is_builtin,
            'sort'         => $row->sort,
            'status'       => $row->status,
            'remark'       => $row->remark,
            'member_count' => (int) ($memberCounts[$row->id] ?? 0),
            'created_at'   => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 详情带上授权明细，前端一次拿全，不用再发三个请求 */
    public static function detail(int $id): array
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($id));

        return $role->toArray() + [
            'permission_ids' => self::grantedPermissionIds($id),
            'dept_ids'       => Db::table('sys_role_depts')->where('role_id', $id)
                ->pluck('dept_id')->map(fn ($v) => (int) $v)->all(),
            'mutex_ids'      => Db::table('sys_role_mutexes')->where('role_id', $id)
                ->pluck('mutex_id')->map(fn ($v) => (int) $v)->all(),
            'inherited_ids'  => self::inheritedPermissionIds($role),
        ];
    }

    /** 可选的下拉项，用户管理分配角色时用 */
    public static function options(): array
    {
        return SysRoleModel::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->get(['id', 'name', 'code', 'data_scope'])
            ->map(fn (SysRoleModel $r) => [
                'id'         => $r->id,
                'name'       => $r->name,
                'code'       => $r->code,
                'data_scope' => $r->data_scope,
            ])
            ->all();
    }

    // ---------------------------------------------------------------- 增改删

    public static function create(array $data): SysRoleModel
    {
        Guard::unique(SysRoleModel::class, 'code', $data['code'], null, '角色编码已存在', BizCode::ROLE_CODE_EXISTS);

        return Db::transaction(function () use ($data) {
            $role = new SysRoleModel();
            $role->fill($data);
            $role->is_builtin = false;   // 内置角色只能由种子脚本产生
            $role->save();

            OpLog::target("角色 {$role->name}({$role->code})");

            return $role;
        });
    }

    public static function update(int $id, array $data): SysRoleModel
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($id));
        Guard::notBuiltin($role, '内置角色不允许修改', BizCode::BUILTIN_ROLE_PROTECTED);

        Guard::unique(SysRoleModel::class, 'code', $data['code'], $id, '角色编码已存在', BizCode::ROLE_CODE_EXISTS);
        Guard::noCycle(
            SysRoleModel::class,
            $id,
            (int) ($data['parent_id'] ?? $role->parent_id),
            '继承角色不可形成环', BizCode::ROLE_INHERIT_CYCLE);

        $before = $role->toArray();

        return Db::transaction(function () use ($role, $data, $before, $id) {
            $role->fill($data);
            $role->save();

            OpLog::target("角色 {$role->name}({$role->code})");
            OpLog::diff($before, $role->toArray());

            // 改了继承关系等于改了实际权限，持有者的缓存要失效
            self::bumpHolders($id);

            return $role;
        });
    }

    public static function delete(int $id): void
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($id));
        Guard::notBuiltin($role, '内置角色不允许删除', BizCode::BUILTIN_ROLE_PROTECTED);

        // 成员关系在中间表里，Guard::notReferenced 只能查单表，这里直接判
        if (Db::table('sys_user_roles')->where('role_id', $id)->exists()) {
            throw new ConflictException('角色下存在用户，无法删除', BizCode::ROLE_HAS_USERS);
        }

        Guard::notReferenced(
            SysRoleModel::class,
            'parent_id',
            $id,
            // 与 20303「角色下存在用户」是两件事：这条要用户先去解除继承关系，
            // 那条要用户先去改人员的角色，前端提示与跳转都不同
            '该角色被其他角色继承，无法删除',
            BizCode::ROLE_INHERITED,
        );

        OpLog::target("角色 {$role->name}({$role->code})");

        Db::transaction(function () use ($role, $id) {
            Db::table('sys_role_permissions')->where('role_id', $id)->delete();
            Db::table('sys_role_depts')->where('role_id', $id)->delete();
            Db::table('sys_role_mutexes')->where('role_id', $id)->orWhere('mutex_id', $id)->delete();
            $role->delete();
        });
    }

    // ---------------------------------------------------------------- 授权

    /**
     * 保存功能权限
     *
     * 自动补齐祖先节点：前端树只回传勾中的叶子，若父目录没被带上，
     * `buildMenuTree` 从根找不到这条链，菜单就整条消失。
     * 与其要求每个调用方记得补，不如在服务端兜住。
     */
    public static function grantPermissions(int $id, array $permissionIds): void
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($id));

        $ids = self::expandWithAncestors(array_map('intval', $permissionIds));

        Db::transaction(function () use ($id, $ids, $role) {
            Db::table('sys_role_permissions')->where('role_id', $id)->delete();

            foreach (array_chunk($ids, 200) as $chunk) {
                Db::table('sys_role_permissions')->insertOrIgnore(
                    array_map(fn (int $pid) => ['role_id' => $id, 'permission_id' => $pid], $chunk)
                );
            }

            OpLog::target("角色 {$role->name}({$role->code})");
            OpLog::changes([['field' => 'permissions', 'old' => '', 'new' => count($ids) . ' 项']]);

            self::bumpHolders($id);
        });
    }

    /** 保存数据范围；data_scope = 5（自定义）时才写部门集合 */
    public static function grantDataScope(int $id, int $dataScope, array $deptIds): void
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($id));

        if ($dataScope === 5 && !$deptIds) {
            throw new BusinessException('自定义数据范围至少要选择一个部门', BizCode::DATA_SCOPE_REQUIRES_DEPT);
        }

        $before = $role->data_scope;

        Db::transaction(function () use ($id, $dataScope, $deptIds, $role, $before) {
            $role->data_scope = $dataScope;
            $role->save();

            Db::table('sys_role_depts')->where('role_id', $id)->delete();

            if ($dataScope === 5) {
                Db::table('sys_role_depts')->insertOrIgnore(
                    array_map(fn ($d) => ['role_id' => $id, 'dept_id' => (int) $d], $deptIds)
                );
            }

            OpLog::target("角色 {$role->name}({$role->code})");
            OpLog::diff(['data_scope' => $before], ['data_scope' => $dataScope]);

            self::bumpHolders($id);
        });
    }

    /** 保存互斥关系；互斥是对称的，两个方向都写 */
    public static function saveMutexes(int $id, array $mutexIds): void
    {
        Guard::found(SysRoleModel::find($id));

        $mutexIds = array_values(array_unique(array_filter(
            array_map('intval', $mutexIds),
            fn (int $mid) => $mid !== $id
        )));

        Db::transaction(function () use ($id, $mutexIds) {
            Db::table('sys_role_mutexes')->where('role_id', $id)->orWhere('mutex_id', $id)->delete();

            foreach ($mutexIds as $mid) {
                Db::table('sys_role_mutexes')->insertOrIgnore([
                    ['role_id' => $id,  'mutex_id' => $mid],
                    ['role_id' => $mid, 'mutex_id' => $id],
                ]);
            }
        });
    }

    // ---------------------------------------------------------------- 成员

    public static function memberQuery(int $roleId): Builder
    {
        Guard::found(SysRoleModel::find($roleId));

        return SysUserModel::query()
            ->with(['dept:id,name'])
            ->whereIn('id', Db::table('sys_user_roles')->where('role_id', $roleId)->pluck('user_id'));
    }

    public static function addMembers(int $roleId, array $userIds): void
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($roleId));

        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        Db::transaction(function () use ($roleId, $userIds, $role) {
            foreach ($userIds as $uid) {
                $current = Db::table('sys_user_roles')->where('user_id', $uid)->pluck('role_id')
                    ->map(fn ($v) => (int) $v)->all();

                self::assertAssignable($uid, array_values(array_unique([...$current, $roleId])));

                Db::table('sys_user_roles')->insertOrIgnore(['user_id' => $uid, 'role_id' => $roleId]);
            }

            OpLog::target("角色 {$role->name} 成员 +" . count($userIds));
            PermissionService::bumpUsers($userIds);
        });
    }

    public static function removeMember(int $roleId, int $userId): void
    {
        /** @var SysRoleModel $role */
        $role = Guard::found(SysRoleModel::find($roleId));

        Db::table('sys_user_roles')->where('role_id', $roleId)->where('user_id', $userId)->delete();

        OpLog::target("角色 {$role->name} 移除成员 #{$userId}");
        PermissionService::bumpUsers([$userId]);
    }

    /**
     * 分配校验：互斥 + 数量上限
     *
     * 用户管理的「分配角色」也走这里——同一条规则两处实现必然会漂移，
     * 而漂移的表现是「从角色页加人能成功，从用户页加同一个人却被拒」。
     */
    public static function assertAssignable(int $userId, array $roleIds): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        $limit = (int) ParamService::value('sys.role.maxPerUser', 5);
        if ($limit > 0 && count($roleIds) > $limit) {
            throw new BusinessException("单账号最多持有 {$limit} 个角色", BizCode::ROLE_LIMIT_EXCEEDED);
        }

        if (count($roleIds) < 2) {
            return;
        }

        $conflicts = Db::table('sys_role_mutexes')
            ->whereIn('role_id', $roleIds)
            ->whereIn('mutex_id', $roleIds)
            ->first();

        if ($conflicts) {
            $names = SysRoleModel::query()
                ->whereIn('id', [$conflicts->role_id, $conflicts->mutex_id])
                ->pluck('name')
                ->all();

            throw new BusinessException(
                '角色「' . implode('」与「', $names) . '」互斥，不可同时授予',
                BizCode::ROLE_MUTUAL_EXCLUSION,
            );
        }
    }

    // ---------------------------------------------------------------- 内部

    private static function grantedPermissionIds(int $roleId): array
    {
        return Db::table('sys_role_permissions')->where('role_id', $roleId)
            ->pluck('permission_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * 从父角色继承来的权限点
     *
     * 只支持单继承一层（docs/database.md §3.4）。前端把这些节点置灰不可取消——
     * 能取消的话，用户以为取消了，实际下次还是从父角色继承回来，白折腾。
     */
    private static function inheritedPermissionIds(SysRoleModel $role): array
    {
        return $role->parent_id > 0 ? self::grantedPermissionIds($role->parent_id) : [];
    }

    /** 补齐每个权限点的祖先链，保证菜单树不断 */
    private static function expandWithAncestors(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $parents = SysPermissionModel::query()->pluck('parent_id', 'id');
        $result  = [];

        foreach ($ids as $id) {
            $cursor = $id;
            for ($depth = 0; $cursor > 0 && $depth < 64; $depth++) {
                if (isset($result[$cursor])) {
                    break;   // 这条链已经补过
                }
                $result[$cursor] = true;
                $cursor = (int) ($parents[$cursor] ?? 0);
            }
        }

        return array_map('intval', array_keys($result));
    }

    /**
     * 授权变了 → 持有该角色的用户权限缓存失效
     *
     * 不做这一步，改完授权最长要等 10 分钟（Redis TTL）才生效，
     * 而管理员会以为「改了没用」然后再改一遍。
     */
    private static function bumpHolders(int $roleId): void
    {
        PermissionService::bumpByRole($roleId);
    }
}
