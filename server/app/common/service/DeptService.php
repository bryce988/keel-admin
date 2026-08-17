<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysDept;

/**
 * 部门查询
 */
class DeptService
{
    /** 某个部门及其所有下级的 id，走 ancestors 前缀匹配 */
    public static function subtreeIds(int $deptId): array
    {
        $dept = SysDept::find($deptId);
        if (!$dept) {
            return [$deptId];
        }

        $prefix = $dept->descendantPrefix();

        return SysDept::query()
            ->where(function ($q) use ($deptId, $prefix) {
                $q->where('id', $deptId)
                    ->orWhere('ancestors', $prefix)
                    ->orWhere('ancestors', 'like', $prefix . ',%');
            })
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** 部门树，供筛选面板与表单的级联选择使用 */
    public static function tree(): array
    {
        $rows = SysDept::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->get()
            ->map(fn (SysDept $d) => [
                'id'       => $d->id,
                'parentId' => $d->parent_id,
                'name'     => $d->name,
                'code'     => $d->code,
                'sort'     => $d->sort,
            ])
            ->all();

        return self::buildTree($rows, 0);
    }

    private static function buildTree(array $rows, int $parentId): array
    {
        $tree = [];
        foreach ($rows as $row) {
            if ($row['parentId'] !== $parentId) {
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
