<?php

declare(strict_types=1);

namespace app\common\model\scope;

use app\common\support\Ctx;
use app\common\support\Db;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 数据权限全局 Scope
 *
 * 挂在模型上自动注入归属过滤条件，业务代码禁止手写 where dept_id in (...)
 * （见 CLAUDE.md 硬性约定）。手写的过滤总会在某个新接口上被忘记，
 * 而全局 Scope 是默认开启、需要显式 withoutDataScope() 才能绕过的。
 *
 * 范围取值见 sys_roles.data_scope：
 *   1 全部  2 本部门及下属  3 本部门  4 仅本人  5 自定义部门集合
 * 多角色时取范围最大者（数值越小范围越大）。
 */
final class DataScope implements Scope
{
    public const ALL       = 1;
    public const DEPT_TREE = 2;
    public const DEPT      = 3;
    public const SELF      = 4;
    public const CUSTOM    = 5;

    public function apply(Builder $builder, Model $model): void
    {
        $user = Ctx::user();

        // 无登录态：命令行脚本、登录前的查询，不注入条件
        if ($user === null) {
            return;
        }

        // 超级管理员跳过一切数据权限（sys_users.is_super）
        if (!empty($user['is_super'])) {
            return;
        }

        $level = self::level((int) $user['id']);
        if ($level === self::ALL) {
            return;
        }

        /** @var \app\common\model\concern\HasDataScope $model */
        $deptCol  = $model->deptColumn();
        $ownerCol = $model->ownerColumn();

        $qualifiedDept  = $deptCol !== null ? $model->qualifyColumn($deptCol) : null;
        $qualifiedOwner = $ownerCol !== null ? $model->qualifyColumn($ownerCol) : null;

        $deptIds = match ($level) {
            self::DEPT_TREE => self::deptTree((int) $user['dept_id']),
            self::DEPT      => [(int) $user['dept_id']],
            self::CUSTOM    => self::customDepts((int) $user['id']),
            default         => null,
        };

        // 条件整体包一层闭包，防止 OR 泄漏到调用方已有的 where 上
        $builder->where(function (Builder $q) use ($level, $deptIds, $qualifiedDept, $qualifiedOwner, $user) {
            if ($level === self::SELF) {
                // 无归属人列的表退化为按部门隔离；两者都没有说明该表不参与数据权限
                if ($qualifiedOwner !== null) {
                    $q->where($qualifiedOwner, (int) $user['id']);
                } elseif ($qualifiedDept !== null) {
                    $q->where($qualifiedDept, (int) $user['dept_id']);
                }

                return;
            }

            if ($qualifiedDept === null) {
                return;
            }

            // 自定义范围没配任何部门时按「无可见数据」处理，而不是放行全部
            $q->whereIn($qualifiedDept, $deptIds ?: [0]);
        });
    }

    /**
     * 当前用户**可写**的部门集合，`null` = 不限
     *
     * 读侧由上面的 apply() 自动注入，写侧（前端传上来的 dept_id）做不到自动——
     * 详见 PROJECT.md §6.4 里「为什么不挂 saving 事件」那两条。所以把算好的集合
     * 公开出来，由 {@see \app\common\support\Guard::inDeptScope()} 显式断言，
     * 两侧共用同一份计算（含 Ctx 缓存），不会出现「读得到但写不进」的错位。
     *
     * 规则一句话：**可写集合 = 可读集合，写完的记录自己必须还看得见。**
     *
     * 「仅本人」退化成本部门：读侧按 owner 列过滤，而写侧要判的是一个还不存在的
     * 新记录该落在哪个部门，没有「人」可依据，只能取本部门。
     */
    public static function writableDeptIds(): ?array
    {
        $user = Ctx::user();

        // 与 apply() 的前两个分支保持一致：无登录态（命令行、seed）与超管都不限
        if ($user === null || !empty($user['is_super'])) {
            return null;
        }

        return match (self::level((int) $user['id'])) {
            self::ALL       => null,
            self::DEPT_TREE => self::deptTree((int) $user['dept_id']),
            self::CUSTOM    => self::customDepts((int) $user['id']),
            // DEPT 与 SELF 都只到本部门
            default         => [(int) $user['dept_id']],
        };
    }

    /**
     * 当前用户的数据范围，一个请求内只算一次
     *
     * 这里刻意用查询构造器而不是模型：模型自身挂着本 Scope，会无限递归。
     */
    private static function level(int $userId): int
    {
        $cached = Ctx::get('dataScope.level');
        if ($cached !== null) {
            return (int) $cached;
        }

        $level = (int) (Db::table('sys_user_roles as ur')
            ->join('sys_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->whereNull('r.deleted_at')
            ->where('r.status', 1)
            ->min('r.data_scope') ?? self::SELF);

        Ctx::set('dataScope.level', $level);

        return $level;
    }

    /**
     * 本部门及所有下属部门
     *
     * 走 sys_depts.ancestors 的前缀匹配（idx_ancestors 可用），
     * 比递归查询快得多——这正是 ancestors 字段存在的理由（docs/database.md §3.2）。
     */
    private static function deptTree(int $deptId): array
    {
        $cached = Ctx::get('dataScope.deptTree');
        if ($cached !== null) {
            return $cached;
        }

        $ids = [];
        if ($deptId > 0) {
            // ⚠️ 这两处**必须**用 Db::table 而不是 SysDeptModel：
            // SysDeptModel 自己 use 了 HasDataScope，在 Scope 内部再查它就是自己触发自己，
            // 无限递归。软删除条件因此也只能手写——拿不到模型的 SoftDeletes。
            // 别顺手「统一成模型」，这是全仓唯一不能换的两处
            $self = Db::table('sys_depts')->where('id', $deptId)->first();
            if ($self) {
                $prefix = ($self->ancestors === '' ? '' : $self->ancestors . ',') . $deptId;
                $ids = Db::table('sys_depts')
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($deptId, $prefix) {
                        $q->where('id', $deptId)
                            ->orWhere('ancestors', $prefix)
                            ->orWhere('ancestors', 'like', $prefix . ',%');
                    })
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            }
        }

        Ctx::set('dataScope.deptTree', $ids);

        return $ids;
    }

    /** data_scope = 5 时，角色上勾选的部门集合 */
    private static function customDepts(int $userId): array
    {
        $cached = Ctx::get('dataScope.customDepts');
        if ($cached !== null) {
            return $cached;
        }

        $ids = Db::table('sys_role_depts as rd')
            ->join('sys_user_roles as ur', 'ur.role_id', '=', 'rd.role_id')
            ->where('ur.user_id', $userId)
            ->distinct()
            ->pluck('rd.dept_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        Ctx::set('dataScope.customDepts', $ids);

        return $ids;
    }
}
