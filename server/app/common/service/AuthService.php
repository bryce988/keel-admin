<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\exception\UnauthorizedException;
use app\common\support\Cache;
use app\common\support\Db;
use app\common\support\Env;

/**
 * 认证业务
 *
 * 事务边界、业务规则都在 service 层，控制器只做参数编排。
 */
class AuthService
{
    /**
     * 登录
     *
     * 安全约定：
     * - 账号不存在与密码错误返回同一个错误码，避免账号枚举
     * - 连续失败按 账号+IP 双维度计数，超限锁定
     */
    public static function login(string $username, string $password, string $ip, string $ua): array
    {
        $lockKey = "login:lock:{$username}";
        $failKey = "login:fail:{$username}";

        if (Cache::exists($lockKey)) {
            $minutes = (int) ceil(Cache::ttl($lockKey) / 60);
            self::writeLoginLog(0, $username, $ip, $ua, false, '账号已锁定');
            throw new UnauthorizedException("账号已锁定，请 {$minutes} 分钟后重试", 20003);
        }

        $user = Db::table('sys_users')
            ->whereNull('deleted_at')
            ->where('username', $username)
            ->first();

        $ok = $user && password_verify($password, $user->password);

        if (!$ok) {
            $limit = Env::int('LOGIN_FAIL_LIMIT', 5);
            $lockMinutes = Env::int('LOGIN_LOCK_MINUTES', 30);
            $fails = Cache::incr($failKey, $lockMinutes * 60);

            if ($fails >= $limit) {
                Cache::set($lockKey, 1, $lockMinutes * 60);
                Cache::del($failKey);
            }

            self::writeLoginLog((int) ($user->id ?? 0), $username, $ip, $ua, false, '账号或密码错误');
            throw new UnauthorizedException('账号或密码错误', 20001);
        }

        if ((int) $user->status === 0) {
            self::writeLoginLog((int) $user->id, $username, $ip, $ua, false, '账号已停用');
            throw new UnauthorizedException('账号已被停用，请联系管理员', 20002);
        }

        Cache::del($failKey);

        Db::table('sys_users')->where('id', $user->id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        self::writeLoginLog((int) $user->id, $username, $ip, $ua, true, '');

        $tokens = JwtService::issue((int) $user->id, (int) $user->perm_version);

        // 密码从未修改过 → 强制首次登录改密
        $tokens['mustChangePassword'] = $user->pwd_updated_at === null;

        return $tokens;
    }

    /** 加载用户，供鉴权中间件使用 */
    public static function loadUser(int $uid): array
    {
        $user = Db::table('sys_users')->whereNull('deleted_at')->where('id', $uid)->first();
        if (!$user) {
            throw new UnauthorizedException('账号不存在或已被删除');
        }
        if ((int) $user->status === 0) {
            throw new UnauthorizedException('账号已被停用，请联系管理员', 20002);
        }

        $row = (array) $user;
        // 密码哈希不进请求上下文：Ctx 里的东西会被日志、调试面板顺手打印出来
        unset($row['password']);

        return $row;
    }

    /** 当前用户的资料 + 角色 + 权限 + 菜单树 */
    public static function profile(array $user): array
    {
        $isSuper = (bool) $user['is_super'];

        $roles = Db::table('sys_user_roles as ur')
            ->join('sys_roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user['id'])
            ->whereNull('r.deleted_at')
            ->where('r.status', 1)
            ->pluck('r.code')
            ->toArray();

        // 权限点走 PermissionService，与鉴权中间件共用同一份缓存，避免两处逻辑漂移
        $permissions = PermissionService::codesOf($user);

        if ($isSuper) {
            $nodes = Db::table('sys_permissions')->where('status', 1)->orderBy('sort')->get()->toArray();
            $dataScope = 1;
        } else {
            $nodes = Db::table('sys_permissions as p')
                ->join('sys_role_permissions as rp', 'rp.permission_id', '=', 'p.id')
                ->join('sys_user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
                ->where('ur.user_id', $user['id'])
                ->where('p.status', 1)
                ->select('p.*')
                ->distinct()
                ->orderBy('p.sort')
                ->get()
                ->toArray();

            // 多角色取范围最大者（data_scope 数值越小范围越大）
            $dataScope = (int) (Db::table('sys_user_roles as ur')
                ->join('sys_roles as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user['id'])
                ->min('r.data_scope') ?? 4);
        }

        $dept = $user['dept_id']
            ? Db::table('sys_depts')->where('id', $user['dept_id'])->first()
            : null;

        return [
            'user' => [
                'id'       => (int) $user['id'],
                'username' => $user['username'],
                'realName' => $user['real_name'],
                'avatar'   => $user['avatar'],
                'deptId'   => (int) $user['dept_id'],
                'deptName' => $dept->name ?? '',
                'isSuper'  => $isSuper,
            ],
            'roles'       => $roles,
            'permissions' => $permissions,
            'dataScope'   => $dataScope,
            'menus'       => self::buildMenuTree(array_map(fn ($n) => (array) $n, $nodes)),
        ];
    }

    /** 只保留目录与菜单（type 1、2），按钮权限在 permissions 数组里 */
    private static function buildMenuTree(array $nodes, int $parentId = 0): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            if ((int) $node['parent_id'] !== $parentId || !in_array((int) $node['type'], [1, 2], true)) {
                continue;
            }
            $children = self::buildMenuTree($nodes, (int) $node['id']);
            $item = [
                'id'        => (int) $node['id'],
                'name'      => $node['name'],
                'path'      => $node['path'],
                'component' => $node['component'],
                'icon'      => $node['icon'],
                'permCode'  => $node['perm_code'],
                'visible'   => (bool) $node['visible'],
                'keepAlive' => (bool) $node['keep_alive'],
            ];
            if ($children) {
                $item['children'] = $children;
            }
            $tree[] = $item;
        }

        return $tree;
    }

    public static function changePassword(array $user, string $oldPassword, string $newPassword): void
    {
        $hash = (string) Db::table('sys_users')->where('id', $user['id'])->value('password');

        if (!password_verify($oldPassword, $hash)) {
            throw new BusinessException('原密码错误', 20005);
        }
        if (strlen($newPassword) < 8) {
            throw new BusinessException('新密码长度不能少于 8 位', 20006);
        }

        Db::table('sys_users')->where('id', $user['id'])->update([
            'password'       => password_hash($newPassword, PASSWORD_DEFAULT),
            'pwd_updated_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public static function writeLoginLog(
        int $userId, string $username, string $ip, string $ua, bool $success, string $msg, int $type = 1
    ): void {
        [$browser, $os] = self::parseUserAgent($ua);

        Db::table('sys_login_logs')->insert([
            'user_id'    => $userId,
            'username'   => $username,
            'ip'         => $ip,
            'browser'    => $browser,
            'os'         => $os,
            'type'       => $type,
            'status'     => $success ? 1 : 0,
            'msg'        => $msg,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function parseUserAgent(string $ua): array
    {
        $browser = match (true) {
            str_contains($ua, 'Edg')     => 'Edge',
            str_contains($ua, 'Chrome')  => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari')  => 'Safari',
            default                      => 'Unknown',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS')  => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone')  => 'iOS',
            str_contains($ua, 'Linux')   => 'Linux',
            default                      => 'Unknown',
        };

        return [$browser, $os];
    }
}
