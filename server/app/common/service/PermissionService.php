<?php
/**
 * keel admin
 * 权限点解析
 *
 * 两级缓存：
 * - 请求内用 Ctx，同一请求里多次判权不重复查
 * - 跨请求用 Redis，key 里带 perm_version，授权一变更 key 就变，旧缓存自然失效，
 *   用户无需重新登录即刻生效（PROJECT.md §15 验收项）
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysPermissionModel;
use app\common\model\SysRoleModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Db;

class PermissionService
{
    private const TTL = 600;

    /** 超级管理员返回 ['*'] */
    public static function codesOf(array $user): array
    {
        if (!empty($user['is_super'])) {
            return ['*'];
        }

        $cached = Ctx::get('perm.codes');
        if ($cached !== null) {
            return $cached;
        }

        $key  = sprintf('perm:codes:%d:%d', (int) $user['id'], (int) $user['perm_version']);
        $json = Cache::get($key);

        if ($json !== null) {
            $codes = json_decode($json, true) ?: [];
        } else {
            $codes = self::queryCodes((int) $user['id']);
            Cache::set($key, json_encode($codes, JSON_UNESCAPED_UNICODE), self::TTL);
        }

        Ctx::set('perm.codes', $codes);

        return $codes;
    }

    public static function has(array $user, string $code): bool
    {
        if ($code === '') {
            return true;
        }
        $codes = self::codesOf($user);

        return in_array('*', $codes, true) || in_array($code, $codes, true);
    }

    /** 任一命中即可 */
    public static function hasAny(array $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if (self::has($user, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从库里取该用户所有启用的权限标识
     *
     * `sys_permissions` 与 `sys_roles` 都不接数据权限（它们是全局定义表，
     * 不按部门隔离），所以用模型不会触发 Scope，也就不存在「鉴权链路上又去查权限」的循环。
     *
     * `r.deleted_at` 只能手写：软删除条件是模型自己的全局 Scope，
     * join 进来的表拿不到——这是 join 的固有限制，不是这里偷懒。
     */
    private static function queryCodes(int $userId): array
    {
        return SysPermissionModel::query()
            ->join('sys_role_permissions as rp', 'rp.permission_id', '=', 'sys_permissions.id')
            ->join('sys_user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->join('sys_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('sys_permissions.status', SysPermissionModel::STATUS_ENABLED)
            ->where('r.status', SysRoleModel::STATUS_ENABLED)
            ->whereNull('r.deleted_at')
            ->where('sys_permissions.perm_code', '<>', '')
            ->distinct()
            ->pluck('sys_permissions.perm_code')
            ->all();
    }

    /**
     * 授权变更后调用：递增权限版本号使缓存失效
     *
     * 角色、角色-权限、用户-角色三处发生变化时都要调，否则改了不生效。
     */
    public static function bumpUsers(array $userIds): void
    {
        if (!$userIds) {
            return;
        }
        // toBase()：走模型是为了拿到软删除 Scope（已删用户不必再刷），
        // 但要绕开 Eloquent 自动更新 updated_at——perm_version 是缓存失效计数器，
        // 不是「这个人的资料变了」。改一次角色权限就把名下所有用户的 updated_at 推一遍，
        // 这一列就再也说明不了任何事情
        SysUserModel::withoutDataScope()->toBase()->whereIn('id', $userIds)->increment('perm_version');
    }

    /** 某个角色下的所有用户都要刷新 */
    public static function bumpByRole(int $roleId): void
    {
        $userIds = Db::table('sys_user_roles')->where('role_id', $roleId)->pluck('user_id')->all();
        self::bumpUsers(array_map('intval', $userIds));
    }
}
