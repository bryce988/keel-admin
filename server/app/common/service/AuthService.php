<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use app\common\exception\RateLimitException;
use app\common\exception\UnauthorizedException;
use app\common\exception\ValidationException;
use app\common\support\Cache;
use app\common\support\Db;
use app\common\support\Env;
use app\common\support\IpLocation;

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
     * - 连续失败按 **账号 + IP** 计数并锁定，另有一道**按 IP 的总闸**
     *
     * ## 为什么锁定必须带 IP 维度
     *
     * 曾经只按账号锁：`login:lock:{username}`。`$ip` 传进来了却只用于写日志，
     * 与注释里写的「账号+IP 双维度」完全不符。后果是**任何能打开登录页的人，
     * 拿 5 次错密码就能让指定账号 30 分钟登不进去**——而 `admin` 这个账号名
     * 在本项目里是公开且必然存在的，等于谁都能定点锁死超管。
     *
     * 更难受的是：全仓**没有任何解锁入口**，锁上之后只能干等 TTL 到期，
     * 或者有人 SSH 上去 `redis-cli DEL`。管理员坐在后台里束手无策。
     *
     * 带上 IP 之后，攻击者只锁得到「他自己的 IP × 该账号」这一个组合，
     * 受害者从自己的网络照常登录。
     *
     * ## 为什么同时还要一道 IP 总闸
     *
     * 只加 IP 维度会把 DoS 换成撞库：换个 IP 计数就归零，代理池一挂等于无限次尝试。
     * 而后台端 `config/middleware.php` 里 `'admin' => []` 是空的，
     * **一道限流都没有**。所以这里必须自带一道按 IP 的失败总闸（跨账号），
     * 挡住单机横扫用户名。
     *
     * 两个计数器都是固定窗口（`Cache::incr` 只在首次设 TTL），窗口一到自动清零——
     * 滑动窗口会让持续攻击把整个办公室的出口 IP 永久堵死。
     */
    public static function login(string $username, string $password, string $ip, string $ua): array
    {
        $limit       = Env::int('LOGIN_FAIL_LIMIT', 5);
        $ipLimit     = Env::int('LOGIN_IP_FAIL_LIMIT', 20);
        $lockMinutes = Env::int('LOGIN_LOCK_MINUTES', 30);
        $window      = $lockMinutes * 60;

        // 同一 IP 的失败次数按 IP 归集，与具体账号无关
        $ipFailKey = 'login:fail:ip:' . md5($ip);

        // 锁定与计数都带 IP：锁的是「这个人从这个地方登」，不是「这个账号」
        $scope   = $username . ':' . md5($ip);
        $lockKey = "login:lock:{$scope}";
        $failKey = "login:fail:{$scope}";

        // IP 总闸放在最前：横扫用户名的请求根本不该走到查库
        if ($ipLimit > 0 && (int) (Cache::get($ipFailKey) ?? 0) >= $ipLimit) {
            self::writeLoginLog(0, $username, $ip, $ua, false, '来源 IP 失败次数超限');
            throw new RateLimitException('该来源失败次数过多，请稍后再试', max(Cache::ttl($ipFailKey), 1));
        }

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
            // 两个计数器一起涨：一个决定「这个账号从这个 IP」要不要锁，
            // 一个决定「这个 IP」要不要被整体挡住
            Cache::incr($ipFailKey, $window);
            $fails = Cache::incr($failKey, $window);

            if ($fails >= $limit) {
                Cache::set($lockKey, 1, $window);
                Cache::del($failKey);
            }

            self::writeLoginLog((int) ($user->id ?? 0), $username, $ip, $ua, false, '账号或密码错误');
            throw new UnauthorizedException('账号或密码错误', 20001);
        }

        if ((int) $user->status === 0) {
            self::writeLoginLog((int) $user->id, $username, $ip, $ua, false, '账号已停用');
            throw new UnauthorizedException('账号已被停用，请联系管理员', 20002);
        }

        // 只清「这个账号从这个 IP」的计数。**IP 总闸不清**——
        // 否则攻击者只要手上有一个有效账号，登一次就能把闸门重置，
        // 接着继续横扫其他用户名
        Cache::del($failKey);

        Db::table('sys_users')->where('id', $user->id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        self::writeLoginLog((int) $user->id, $username, $ip, $ua, true, '');

        $tokens = JwtService::issue((int) $user->id, (int) $user->perm_version, (int) $user->token_version);

        // 密码从未修改过 → 强制首次登录改密
        $tokens['must_change_password'] = $user->pwd_updated_at === null;

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
                'id'        => (int) $user['id'],
                'username'  => $user['username'],
                'real_name' => $user['real_name'],
                'avatar'    => $user['avatar'],
                'dept_id'   => (int) $user['dept_id'],
                'dept_name' => $dept->name ?? '',
                'is_super'  => $isSuper,
            ],
            'roles'       => $roles,
            'permissions' => $permissions,
            'data_scope'  => $dataScope,
            'menus'       => self::buildMenuTree(array_map(fn ($n) => (array) $n, $nodes)),
        ];
    }

    /**
     * 只保留目录与菜单（type 1、2），按钮权限在 permissions 数组里
     *
     * **空目录要剪掉**：目录（type=1）自己没有 path 与 component，它的全部意义
     * 就是装下面的菜单。授权时只勾到目录、没勾任何子菜单是很常见的手滑，
     * 不剪的话侧边栏会出现一个点开什么都没有的死条目——
     * 用户看到的是「有这个功能但坏了」，而不是「你没有这个权限」。
     *
     * 菜单（type=2）没有子节点是正常的，那就是一个叶子页面，不能剪。
     */
    private static function buildMenuTree(array $nodes, int $parentId = 0): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            if ((int) $node['parent_id'] !== $parentId || !in_array((int) $node['type'], [1, 2], true)) {
                continue;
            }

            $children = self::buildMenuTree($nodes, (int) $node['id']);

            if ((int) $node['type'] === 1 && !$children) {
                continue;
            }

            $item = [
                'id'         => (int) $node['id'],
                'name'       => $node['name'],
                'path'       => $node['path'],
                'component'  => $node['component'],
                'icon'       => $node['icon'],
                'perm_code'  => $node['perm_code'],
                'visible'    => (bool) $node['visible'],
                'keep_alive' => (bool) $node['keep_alive'],
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

        // 密码强度是**字段级**校验，返回 422 + details 让前端标在输入框上，
        // 而不是笼统弹一句（docs/api.md §2.2 把 20006 定为 422）
        if (mb_strlen($newPassword) < 8) {
            throw new ValidationException(
                ['new_password' => ['新密码长度不能少于 8 位']],
                '新密码不符合安全策略',
                20006
            );
        }
        if ($newPassword === $oldPassword) {
            throw new ValidationException(
                ['new_password' => ['新密码不能与原密码相同']],
                '新密码不符合安全策略',
                20006
            );
        }

        Db::table('sys_users')->where('id', $user['id'])->update([
            'password'       => password_hash($newPassword, PASSWORD_DEFAULT),
            'pwd_updated_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // 改密即作废该用户的**全部**会话，其他端的 access 与 refresh 一起失效。
        // 这是「改密踢下线」真正生效的地方——只吊销当前 jti 的话，
        // 别的设备上那对令牌照常能用，泄露的 refresh 还能一直换新
        Db::table('sys_users')->where('id', $user['id'])->increment('token_version');
    }

    public static function writeLoginLog(
        int $userId, string $username, string $ip, string $ua, bool $success, string $msg, int $type = 1
    ): void {
        [$browser, $os] = self::parseUserAgent($ua);

        // 部门在这里查一次（主键查询，可忽略不计），而不是让五个调用点各传一遍——
        // 总有一处会传漏，而传漏的后果是那条日志谁都看不见（dept_id=0）
        $deptId = $userId > 0
            ? (int) Db::table('sys_users')->where('id', $userId)->value('dept_id')
            : 0;

        Db::table('sys_login_logs')->insert([
            'user_id'    => $userId,
            'username'   => $username,
            'dept_id'    => $deptId,
            'ip'         => $ip,
            // 离线库查，查不到给「未知」而不是留空——安全复核时一眼扫下来，
            // 空白分不清是「查不到」还是「没记」
            'location'   => IpLocation::of($ip),
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
