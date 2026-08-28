<?php
/**
 * keel admin
 * 认证业务
 *
 * 事务边界、业务规则都在 service 层，控制器只做参数编排。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\RateLimitException;
use app\common\exception\UnauthorizedException;
use app\common\exception\ValidationException;
use app\common\model\SysDeptModel;
use app\common\model\SysLoginLogModel;
use app\common\model\SysPermissionModel;
use app\common\model\SysRoleModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\Env;
use app\common\support\IpLocation;

class AuthService
{
    /**
     * 登录
     *
     * 安全约定：
     * - 账号不存在与密码错误返回同一个错误码，避免账号枚举
     * - 连续失败按 账号 + IP 计数并锁定，另有一道按 IP 的总闸
     *
     * ## 为什么锁定必须带 IP 维度
     *
     * 曾经只按账号锁：`login:lock:{username}`。`$ip` 传进来了却只用于写日志，
     * 与注释里写的「账号+IP 双维度」完全不符。后果是任何能打开登录页的人，
     * 拿 5 次错密码就能让指定账号 30 分钟登不进去——而 `admin` 这个账号名
     * 在本项目里是公开且必然存在的，等于谁都能定点锁死超管。
     *
     * 更难受的是：全仓没有任何解锁入口，锁上之后只能干等 TTL 到期，
     * 或者有人 SSH 上去 `redis-cli DEL`。管理员坐在后台里束手无策。
     *
     * 带上 IP 之后，攻击者只锁得到「他自己的 IP × 该账号」这一个组合，
     * 受害者从自己的网络照常登录。
     *
     * ## 为什么同时还要一道 IP 总闸
     *
     * 只加 IP 维度会把 DoS 换成撞库：换个 IP 计数就归零，代理池一挂等于无限次尝试。
     * 而后台端 `config/middleware.php` 里 `'admin' => []` 是空的，
     * 一道限流都没有。所以这里必须自带一道按 IP 的失败总闸（跨账号），
     * 挡住单机横扫用户名。
     *
     * 两个计数器都是固定窗口（`Cache::incr` 只在首次设 TTL），窗口一到自动清零——
     * 滑动窗口会让持续攻击把整个办公室的出口 IP 永久堵死。
     *
     * ## 阈值从哪来
     *
     * 失败次数与锁定时长走 `threshold()`：**参数表优先，`.env` 兜底**。
     * 在此之前它们只读 `.env`，而「参数配置」界面里那两项（`sys.login.failLimit`
     * / `sys.login.lockMinutes`）从来没有被任何代码读过——管理员在界面上改成 3，
     * 保存成功、没有报错、列表显示新值，实际锁定行为纹丝不动。
     * 这比「功能没做」更糟：没做至少看得出来，这个是看着生效其实没生效，
     * 而且是安全设置。
     *
     * IP 总闸仍然只读 `.env`：种子里没有对应参数，不凭空造一个界面上看不见的键。
     */
    /**
     * 安全阈值：参数表 → .env → 字面量，并夹到合法区间
     *
     * 夹区间不是防御性编程的洁癖，是这次改动**必须**带的一步。
     * 阈值以前只有会改 `.env` 的人能动，现在界面上任何有 `sys:param:update`
     * 的人都能填，而这两个值填 0 都会出事：
     *
     * - `failLimit = 0` → `$fails >= 0` 恒真，第一次输错密码就锁号，全员锁死
     * - `lockMinutes = 0` → `$window = 0`，两个失败计数器的 TTL 变成 0，
     *   等于计数永不过期或立即过期（取决于 Redis 语义），锁定机制直接失效
     *
     * 参数表那边只校验类型是 int，管不到取值范围，所以只能在用的地方夹。
     * 上界同理：lockMinutes 填 999999 会把人锁到下辈子，而全仓没有任何解锁入口。
     *
     * ## 「没配」与「配了 0」按同一件事处理
     *
     * `<= 0` 一律回落到 `.env`，而不是夹到下界。这两个阈值都没有「0」这个语义，
     * 所以 0 只可能是「留空」或「填错了」——
     * 而 `ParamService` 对 int 型参数的空值返回的正是 `(int) '' = 0`，
     * 从这里看不出到底是没填还是填了 0。
     *
     * 夹到下界（失败 1 次就锁号）是这两种情况下最糟的解释；
     * 回落到运维配的 `.env` 才是「这项没配好，按原来的来」。
     * 第一版就是夹到下界，实测把参数清空后生效值变成 1，等于把系统锁死。
     */
    private static function threshold(string $key, string $envKey, int $fallback, int $min, int $max): int
    {
        $value = (int) ParamService::value($key);

        if ($value <= 0) {
            $value = Env::int($envKey, $fallback);
        }

        return max($min, min($max, $value));
    }

    public static function login(string $username, string $password, string $ip, string $ua): array
    {
        $limit       = self::threshold('sys.login.failLimit', 'LOGIN_FAIL_LIMIT', 5, 1, 100);
        $lockMinutes = self::threshold('sys.login.lockMinutes', 'LOGIN_LOCK_MINUTES', 30, 1, 1440);
        $ipLimit     = Env::int('LOGIN_IP_FAIL_LIMIT', 20);
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
            throw new UnauthorizedException("账号已锁定，请 {$minutes} 分钟后重试", BizCode::ACCOUNT_LOCKED);
        }

        // withoutDataScope()：登录时 Ctx 里还没有用户，DataScope 本来就会放行，
        // 但写出来才是把「这一步不依赖登录态」钉死在代码里——
        // 将来 DataScope 若改成 fail-closed，这里不会跟着一起登不上去。
        // 软删除条件由 SoftDeletes 自动带上，不再手写 whereNull('deleted_at')
        $user = SysUserModel::withoutDataScope()->where('username', $username)->first();

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
            throw new UnauthorizedException('账号或密码错误', BizCode::ACCOUNT_OR_PASSWORD_ERROR);
        }

        if ((int) $user->status === SysUserModel::STATUS_DISABLED) {
            self::writeLoginLog((int) $user->id, $username, $ip, $ua, false, '账号已停用');
            throw new UnauthorizedException('账号已被停用，请联系管理员', BizCode::ACCOUNT_DISABLED);
        }

        // 只清「这个账号从这个 IP」的计数。IP 总闸不清——
        // 否则攻击者只要手上有一个有效账号，登一次就能把闸门重置，
        // 接着继续横扫其他用户名
        Cache::del($failKey);

        SysUserModel::withoutDataScope()->where('id', $user->id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
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
        // 这是「建立登录态」的那一步，不能依赖登录态，所以显式 withoutDataScope()；
        // 软删除由 SoftDeletes 带上
        $user = SysUserModel::withoutDataScope()->find($uid);
        if (!$user) {
            throw new UnauthorizedException('账号不存在或已被删除');
        }
        if ((int) $user->status === SysUserModel::STATUS_DISABLED) {
            throw new UnauthorizedException('账号已被停用，请联系管理员', BizCode::ACCOUNT_DISABLED);
        }

        // 密码哈希不进请求上下文（Ctx 里的东西会被日志、调试面板顺手打印出来），
        // 模型的 $hidden 已经把它挡在 toArray() 之外，这里不用再手动 unset
        return $user->toArray();
    }

    /** 当前用户的资料 + 角色 + 权限 + 菜单树 */
    public static function profile(array $user): array
    {
        $isSuper = (bool) $user['is_super'];

        $roles = SysRoleModel::query()
            ->join('sys_user_roles as ur', 'ur.role_id', '=', 'sys_roles.id')
            ->where('ur.user_id', $user['id'])
            ->where('sys_roles.status', SysRoleModel::STATUS_ENABLED)
            ->pluck('sys_roles.code')
            ->toArray();

        // 权限点走 PermissionService，与鉴权中间件共用同一份缓存，避免两处逻辑漂移
        $permissions = PermissionService::codesOf($user);

        if ($isSuper) {
            $nodes = SysPermissionModel::query()->enabled()->orderBy('sort')->get()->toArray();
            $dataScope = 1;
        } else {
            $nodes = SysPermissionModel::query()
                ->join('sys_role_permissions as rp', 'rp.permission_id', '=', 'sys_permissions.id')
                ->join('sys_user_roles as ur', 'ur.role_id', '=', 'rp.role_id')
                ->where('ur.user_id', $user['id'])
                ->where('sys_permissions.status', SysPermissionModel::STATUS_ENABLED)
                ->select('sys_permissions.*')
                ->distinct()
                ->orderBy('sys_permissions.sort')
                ->get()
                ->toArray();

            // 多角色取范围最大者（data_scope 数值越小范围越大）
            $dataScope = (int) (SysRoleModel::query()
                ->join('sys_user_roles as ur', 'ur.role_id', '=', 'sys_roles.id')
                ->where('ur.user_id', $user['id'])
                ->min('sys_roles.data_scope') ?? 4);
        }

        $dept = $user['dept_id']
            ? SysDeptModel::withoutDataScope()->find($user['dept_id'])
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
     * 空目录要剪掉：目录（type=1）自己没有 path 与 component，它的全部意义
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
        $hash = (string) SysUserModel::withoutDataScope()->where('id', $user['id'])->value('password');

        if (!password_verify($oldPassword, $hash)) {
            throw new BusinessException('原密码错误', BizCode::OLD_PASSWORD_ERROR);
        }

        // 密码强度是字段级校验，返回 422 + details 让前端标在输入框上，
        // 而不是笼统弹一句（docs/api.md §2.2 把 20006 定为 422）
        if (mb_strlen($newPassword) < 8) {
            throw new ValidationException(
                ['new_password' => ['新密码长度不能少于 8 位']],
                '新密码不符合安全策略',
                BizCode::PASSWORD_POLICY_VIOLATION,
            );
        }
        if ($newPassword === $oldPassword) {
            throw new ValidationException(
                ['new_password' => ['新密码不能与原密码相同']],
                '新密码不符合安全策略',
                BizCode::PASSWORD_POLICY_VIOLATION,
            );
        }

        SysUserModel::withoutDataScope()->where('id', $user['id'])->update([
            'password'       => password_hash($newPassword, PASSWORD_DEFAULT),
            'pwd_updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 改密即作废该用户的全部会话，其他端的 access 与 refresh 一起失效。
        // 这是「改密踢下线」真正生效的地方——只吊销当前 jti 的话，
        // 别的设备上那对令牌照常能用，泄露的 refresh 还能一直换新
        SysUserModel::withoutDataScope()->where('id', $user['id'])->increment('token_version');
    }

    public static function writeLoginLog(
        int $userId, string $username, string $ip, string $ua, bool $success, string $msg, int $type = 1
    ): void {
        [$browser, $os] = self::parseUserAgent($ua);

        // 部门在这里查一次（主键查询，可忽略不计），而不是让五个调用点各传一遍——
        // 总有一处会传漏，而传漏的后果是那条日志谁都看不见（dept_id=0）
        $deptId = $userId > 0
            ? (int) SysUserModel::withoutDataScope()->where('id', $userId)->value('dept_id')
            : 0;

        // created_at 由模型的 timestamps 自动写（该表 UPDATED_AT = null，只有创建时间）；
        // 这张表没有审计列，模型已把 auditColumns() 覆写成空，登录失败时（无登录态）也能写
        SysLoginLogModel::create([
            'user_id'  => $userId,
            'username' => $username,
            'dept_id'  => $deptId,
            'ip'       => $ip,
            // 离线库查，查不到给「未知」而不是留空——安全复核时一眼扫下来，
            // 空白分不清是「查不到」还是「没记」
            'location' => IpLocation::of($ip),
            'browser'  => $browser,
            'os'       => $os,
            'type'     => $type,
            'status'   => $success ? SysLoginLogModel::STATUS_SUCCESS : SysLoginLogModel::STATUS_FAIL,
            'msg'      => $msg,
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
