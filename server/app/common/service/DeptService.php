<?php
/**
 * keel admin
 * 部门
 *
 * 这张表是数据权限的载体：`ancestors` 祖级路径决定了「本部门及下属」
 * 能看到哪些数据。所以移动部门时子孙的 ancestors 必须同步刷新，
 * 漏刷的后果不是显示错乱，而是权限失效——用户看得到本不该看的数据。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\model\SysDeptModel;
use app\common\model\SysPostModel;
use app\common\model\SysUserModel;
use app\common\support\Db;
use app\common\support\Guard;
use app\common\support\OpLog;

class DeptService
{
    /** 某个部门及其所有下级的 id，走 ancestors 前缀匹配 */
    public static function subtreeIds(int $deptId): array
    {
        $dept = SysDeptModel::find($deptId);
        if (!$dept) {
            return [$deptId];
        }

        $prefix = $dept->descendantPrefix();

        return SysDeptModel::query()
            ->where(function ($q) use ($deptId, $prefix) {
                $q->where('id', $deptId)
                    ->orWhere('ancestors', $prefix)
                    ->orWhere('ancestors', 'like', $prefix . ',%');
            })
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 部门树
     *
     * 同时服务两个场景：部门管理页（要全字段）与用户列表的筛选面板（只用 id/name）。
     * 多返回几个字段比维护两个几乎一样的接口划算。
     */
    public static function tree(array $filters = []): array
    {
        // 筛选放在建树之前用「命中即保留祖先链」处理，不能直接写进 SQL：
        // 停用的父部门被滤掉后，它下面启用的子部门会跟着从树上消失
        $rows = SysDeptModel::query()->orderBy('sort')->orderBy('id')->get();

        // 用户数一次分组查出来，不在递归里逐个 count——那是标准的 N+1
        $userCounts = SysUserModel::query()
            ->whereIn('dept_id', $rows->pluck('id'))
            ->selectRaw('dept_id, count(*) as total')
            ->groupBy('dept_id')
            ->pluck('total', 'dept_id');

        $nodes = $rows->map(fn (SysDeptModel $d) => [
            'id'         => $d->id,
            'parent_id'  => $d->parent_id,
            'name'       => $d->name,
            'code'       => $d->code,
            'leader_id'  => $d->leader_id,
            'sort'       => $d->sort,
            'status'     => $d->status,
            'user_count' => (int) ($userCounts[$d->id] ?? 0),
            'created_at' => $d->created_at?->format('Y-m-d H:i:s'),
        ])->all();

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $status  = ($filters['status'] ?? '') === '' ? null : (int) $filters['status'];

        if ($keyword !== '' || $status !== null) {
            $nodes = self::filterKeepingAncestors($nodes, function (array $n) use ($keyword, $status) {
                if ($keyword !== ''
                    && !str_contains($n['name'], $keyword)
                    && !str_contains($n['code'], $keyword)) {
                    return false;
                }

                return !($status !== null && $n['status'] !== $status);
            });
        }

        return self::buildTree($nodes);
    }

    public static function detail(int $id): array
    {
        /** @var SysDeptModel $dept */
        $dept = Guard::found(SysDeptModel::find($id));

        return $dept->toArray();
    }

    public static function create(array $data): SysDeptModel
    {
        Guard::unique(SysDeptModel::class, 'code', $data['code'], null, '部门编码已存在', BizCode::DEPT_CODE_EXISTS);

        $parentId = (int) ($data['parent_id'] ?? 0);

        // 部门表的归属列是自己的 id（见 deptColumn），所以判的是新上级。
        // parent_id = 0 是建顶级部门，只有全部数据范围能建。
        // 「仅本部门 / 自定义部门」的集合是固定 id 列表，建完的子部门不在里面，建完就看不见——
        // 这不是校验能补的，是这两种范围本来就不该配 sys:dept:create
        Guard::inDeptScope($parentId, message: '上级部门超出你的数据范围');

        return Db::transaction(function () use ($data, $parentId) {
            $dept = new SysDeptModel();
            $dept->fill($data);
            $dept->parent_id = $parentId;
            $dept->ancestors = self::ancestorsOf($parentId);
            $dept->save();

            OpLog::target("部门 {$dept->name}({$dept->id})");

            return $dept;
        });
    }

    public static function update(int $id, array $data): SysDeptModel
    {
        /** @var SysDeptModel $dept */
        $dept = Guard::found(SysDeptModel::find($id));

        Guard::unique(SysDeptModel::class, 'code', $data['code'], $id, '部门编码已存在', BizCode::DEPT_CODE_EXISTS);

        $newParentId = (int) ($data['parent_id'] ?? $dept->parent_id);
        Guard::noCycle(SysDeptModel::class, $id, $newParentId, '上级部门不能是自己或其子部门', BizCode::DEPT_CYCLE);

        // 挪走的落点也要在范围内，否则能把自己范围内的整棵子树挂到看不见的地方去。
        // 被挪的部门自身无需再判：能 find 到就说明它已经过了读侧 Scope
        Guard::inDeptScope($newParentId, message: '上级部门超出你的数据范围');

        $before = $dept->toArray();

        return Db::transaction(function () use ($dept, $data, $newParentId, $before) {
            $oldPrefix = $dept->descendantPrefix();

            $dept->fill($data);
            $dept->parent_id = $newParentId;
            $dept->ancestors = self::ancestorsOf($newParentId);
            $dept->save();

            // 自己的位置变了，整棵子树的祖级路径都要跟着平移
            $newPrefix = $dept->descendantPrefix();
            if ($newPrefix !== $oldPrefix) {
                self::shiftDescendants($oldPrefix, $newPrefix);
            }

            OpLog::target("部门 {$dept->name}({$dept->id})");
            OpLog::diff($before, $dept->toArray());

            return $dept;
        });
    }

    public static function delete(int $id): void
    {
        /** @var SysDeptModel $dept */
        $dept = Guard::found(SysDeptModel::find($id));

        $message = '部门下存在用户、岗位或子部门，无法删除';
        Guard::notReferenced(SysDeptModel::class, 'parent_id', $id, $message, BizCode::DEPT_HAS_CHILDREN);
        Guard::notReferenced(SysUserModel::class, 'dept_id', $id, $message, BizCode::DEPT_HAS_CHILDREN);
        Guard::notReferenced(SysPostModel::class, 'dept_id', $id, $message, BizCode::DEPT_HAS_CHILDREN);

        OpLog::target("部门 {$dept->name}({$dept->id})");

        $dept->delete();
    }

    // ---------------------------------------------------------------- 内部

    /** 新建/移动到某个父节点时，自己的祖级路径 */
    private static function ancestorsOf(int $parentId): string
    {
        if ($parentId === 0) {
            return '0';
        }

        /** @var SysDeptModel $parent */
        $parent = Guard::found(SysDeptModel::find($parentId), '上级部门不存在');

        return $parent->descendantPrefix();
    }

    /**
     * 平移整棵子树的 ancestors
     *
     * 例：技术部(id=2) 从「总公司」挪到「运营部」
     *   oldPrefix = '0,1,2'   newPrefix = '0,1,3,2'
     *   子节点   '0,1,2'   → '0,1,3,2'
     *   孙节点   '0,1,2,5' → '0,1,3,2,5'
     * 即「换掉前缀，保留后缀」。用一条 UPDATE 完成，不递归——
     * 子树可能有几百个节点，逐个 save 既慢又容易中途失败留下半更新状态。
     */
    private static function shiftDescendants(string $oldPrefix, string $newPrefix): void
    {
        Db::conn()->update(
            'UPDATE sys_depts
                SET ancestors = CONCAT(?, SUBSTRING(ancestors, ?)), updated_at = ?
              WHERE deleted_at IS NULL
                AND (ancestors = ? OR ancestors LIKE ?)',
            [
                $newPrefix,
                strlen($oldPrefix) + 1,   // SUBSTRING 从 1 开始计数
                date('Y-m-d H:i:s'),
                $oldPrefix,
                $oldPrefix . ',%',
            ]
        );
    }

    /** 命中的节点连同它的整条祖先链一起保留，否则树会从中间断掉 */
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

    /**
     * 建树：父节点不在本次结果集里的一律当根
     *
     * ⚠️ 根不能写死成 parent_id = 0。数据权限会把上级部门滤掉，技术部主管拿到的行里
     * 最浅的一层就是技术部本身，它的 parent_id 指向看不见的总公司，按 0 找根一个都挂不上，
     * 返回空数组。表现是部门管理页空白、用户表单的部门下拉只剩「未分配」，
     * 而「未分配」又是写侧唯一不许选的值，于是部门主管一个用户都建不出来。
     */
    private static function buildTree(array $rows): array
    {
        $ids      = array_column($rows, 'id');
        $byParent = [];

        foreach ($rows as $row) {
            $parent = in_array($row['parent_id'], $ids, true) ? $row['parent_id'] : 0;
            $byParent[$parent][] = $row;
        }

        return self::attachChildren($byParent, 0);
    }

    /** @param  array<int, array<array>>  $byParent  父 id => 子节点，预先分好组避免 O(n²) */
    private static function attachChildren(array $byParent, int $parentId): array
    {
        $tree = [];

        foreach ($byParent[$parentId] ?? [] as $row) {
            $children = self::attachChildren($byParent, $row['id']);
            if ($children) {
                $row['children'] = $children;
            }
            $tree[] = $row;
        }

        return $tree;
    }
}
