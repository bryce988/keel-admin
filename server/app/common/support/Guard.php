<?php

declare(strict_types=1);

namespace app\common\support;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\ConflictException;
use app\common\exception\ForbiddenException;
use app\common\exception\NotFoundException;
use app\common\model\scope\DataScope;

/**
 * 写接口的前置断言
 *
 * 七个模块的增删改反复出现同样三种校验：编码不能重复、有引用不让删、
 * 移动节点不能移到自己的子孙下。写七遍必然写出七种细微差别，
 * 而这类差别的代价是「某个模块能建出重复编码」这种要靠用户报障才发现的问题。
 *
 * 用法都是「不满足就抛」，service 里读起来像前置条件声明：
 *
 *   Guard::unique(SysDeptModel::class, 'code', $code, exceptId: $id,
 *                 message: '部门编码已存在', bizCode: BizCode::DEPT_CODE_EXISTS);
 */
final class Guard
{
    /** 取到的记录为空就 404。存在但超出数据权限时同样落到这里，不区分（api.md §2.1） */
    public static function found(mixed $model, string $message = '数据不存在或已被删除'): mixed
    {
        if ($model === null || $model === false) {
            throw new NotFoundException($message);
        }

        return $model;
    }

    /**
     * 唯一性校验
     *
     * ⚠️ 查询必须 `withoutGlobalScopes()`，两个原因缺一不可：
     * - 绕过数据权限：否则部门主管看不到别人部门的编码，会「成功」创建重复值，
     *   然后撞上数据库唯一索引，用户看到的是 500 而不是「编码已存在」
     * - 算上软删的行：`uk_code` 这类唯一索引不包含 `deleted_at`，
     *   被软删的记录仍然占着那个编码。不算它就是同样的 500
     *
     * @param  int|null  $exceptId  编辑时排除自己
     */
    public static function unique(
        string $modelClass,
        string $column,
        mixed $value,
        ?int $exceptId = null,
        string $message = '该值已存在',
        int $bizCode = BizCode::CONFLICT,
    ): void {
        $query = $modelClass::query()->withoutGlobalScopes()->where($column, $value);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        if ($query->exists()) {
            throw new ConflictException($message, $bizCode);
        }
    }

    /**
     * 被引用检查：还有别的表指着我，就不让删
     *
     * 与 unique 的关键区别是保留软删除 Scope——已经被软删的用户不该拦住
     * 删除它所在的部门。只去掉数据权限，那个纯粹是「谁看得见」的问题，
     * 与「这条数据能不能删」无关。
     */
    public static function notReferenced(
        string $modelClass,
        string $column,
        mixed $value,
        string $message = '数据被引用，无法删除',
        int $bizCode = BizCode::CONFLICT,
    ): void {
        $exists = $modelClass::query()
            ->withoutGlobalScope(DataScope::class)
            ->where($column, $value)
            ->exists();

        if ($exists) {
            throw new ConflictException($message, $bizCode);
        }
    }

    /**
     * 树形结构不能形成环：不能把节点挂到自己或自己的子孙下面
     *
     * 沿 parent_id 从新父节点往上走，撞到自己就是环。
     * 部门（20202）、菜单（20403）、角色继承（20306）三处都要这个校验，
     * 而它们判定子孙的方式各不相同（部门有 ancestors、菜单只有 parent_id），
     * 所以这里统一用向上回溯，只依赖 parent_id，三处都能用。
     */
    public static function noCycle(
        string $modelClass,
        int $id,
        int $newParentId,
        string $message = '上级不能是自己或自己的下级',
        int $bizCode = BizCode::GENERAL_BAD_REQUEST,
    ): void {
        if ($newParentId === 0) {
            return;   // 挂到顶级，不可能成环
        }

        if ($newParentId === $id) {
            throw new BusinessException($message, $bizCode);
        }

        $cursor = $newParentId;

        // 深度上限兜底：万一库里已经有一条环形数据，这里不能变成死循环
        for ($depth = 0; $cursor > 0 && $depth < 64; $depth++) {
            if ($cursor === $id) {
                throw new BusinessException($message, $bizCode);
            }

            $cursor = (int) $modelClass::query()
                ->withoutGlobalScopes()
                ->where('id', $cursor)
                ->value('parent_id');
        }

        if ($cursor > 0) {
            throw new BusinessException('树形层级异常，请联系管理员检查数据', $bizCode);
        }
    }

    /**
     * 内置数据不可改动（内置角色 20302、内置参数 20601）
     *
     * 抛 403 而不是 400：这是「不允许你动」而不是「你传错了」，
     * 参数改成什么都没用（api.md §2.2 把这两个码都定在 403）。
     */
    public static function notBuiltin(mixed $model, string $message, int $bizCode): void
    {
        if (!empty($model->is_builtin)) {
            throw new ForbiddenException($message, $bizCode);
        }
    }
}
