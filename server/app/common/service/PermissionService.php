<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Db;

/**
 * 权限点解析
 *
 * 两级缓存：
 * - 请求内用 Ctx，同一请求里多次判权不重复查
 * - 跨请求用 Redis，key 里带 perm_version，授权一变更 key 就变，旧缓存自然失效，
 *   用户无需重新登录即刻生效（PROJECT.md §15 验收项）
 */
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
     * 用查询构造器而不是模型：这里在鉴权链路上，不该再触发数据权限 Scope。
     */
    private static function queryCodes(int $userId): array
    {
        return Db::table('sys_permissions as p')
            ->join('sys_role_permissions as rp', 'rp.permission_id', '=', 'p.id')
            ->join('sys_user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
            ->join('sys_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->where('p.status', 1)
            ->where('r.status', 1)
            ->whereNull('r.deleted_at')
            ->where('p.perm_code', '<>', '')
            ->distinct()
            ->pluck('p.perm_code')
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
        Db::table('sys_users')->whereIn('id', $userIds)->increment('perm_version');
    }

    /** 某个角色下的所有用户都要刷新 */
    public static function bumpByRole(int $roleId): void
    {
        $userIds = Db::table('sys_user_roles')->where('role_id', $roleId)->pluck('user_id')->all();
        self::bumpUsers(array_map('intval', $userIds));
    }
}
