<?php
/**
 * keel admin
 * 系统概览
 *
 * 只汇总系统本身已有的模块，不编造业务指标——脚手架不含业务逻辑，
 * 摆一堆「今日订单」「转化率」的假数字，接业务的人第一件事还是得全删掉。
 *
 * 所有计数都走模型而不是 `Db::table()`，为的是让数据权限全局 Scope 自动生效：
 * 部门主管看到的「用户数」就该是他管得到的那些人。绕过 Scope 直接查表，
 * 概览会变成一个泄露全局规模的旁路。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysDeptModel;
use app\common\model\SysDictTypeModel;
use app\common\model\SysLoginLogModel;
use app\common\model\SysOperationLogModel;
use app\common\model\SysParamModel;
use app\common\model\SysPermissionModel;
use app\common\model\SysPostModel;
use app\common\model\SysRoleModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Db;
use app\common\support\Env;
use Throwable;
use Workerman\Worker;

class DashboardService
{
    /** 趋势图的天数 */
    private const TREND_DAYS = 7;

    /** 最近操作列表的条数 */
    private const RECENT_LIMIT = 8;

    public static function overview(): array
    {
        // 概览也要 fail-closed：没有 sys:role:list 的人不该从这里得知
        // 「系统里有 4 个角色、44 个权限点」。菜单都对他收敛了，
        // 概览再把规模抖出来，等于开了一条绕过菜单收敛的旁路
        return [
            'stats'   => self::visible(self::stats()),
            'trend'   => self::can('sys:log:login:list') ? self::loginTrend() : [],
            'recent'  => self::can('sys:log:operation:list') ? self::recentOperations() : [],
            'modules' => self::visible(self::moduleSummary()),
            'system'  => self::systemStatus(),
        ];
    }

    /** 按权限过滤，`perm` 为空视为人人可见 */
    private static function visible(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item) => self::can((string) ($item['perm'] ?? ''))
        ));
    }

    private static function can(string $code): bool
    {
        return PermissionService::has(Ctx::user() ?? [], $code);
    }

    /**
     * 四张指标卡
     *
     * 用户与今日登录受数据权限影响，部门/岗位/角色/权限点是全局配置，对谁都一样。
     */
    private static function stats(): array
    {
        $today = date('Y-m-d') . ' 00:00:00';

        $userTotal    = SysUserModel::query()->count();
        $userActive   = SysUserModel::query()->where('status', '<>', SysUserModel::STATUS_DISABLED)->count();
        $loginToday   = SysLoginLogModel::query()->where('created_at', '>=', $today)->count();
        $loginFailed  = SysLoginLogModel::query()->where('created_at', '>=', $today)->where('status', SysLoginLogModel::STATUS_FAIL)->count();
        $opToday      = SysOperationLogModel::query()->where('created_at', '>=', $today)->count();
        $opFailed     = SysOperationLogModel::query()->where('created_at', '>=', $today)->where('status', SysOperationLogModel::STATUS_FAIL)->count();

        return [
            [
                'key'    => 'user',
                'label'  => '用户',
                'value'  => $userTotal,
                'unit'   => '人',
                'hint'   => "在职 {$userActive} · 停用 " . ($userTotal - $userActive),
                'tone'   => 'primary',
                'to'     => '/system/user',
                'perm'   => 'sys:user:list',
            ],
            [
                'key'    => 'org',
                'label'  => '组织',
                'value'  => SysDeptModel::query()->count(),
                'unit'   => '个部门',
                'hint'   => '岗位 ' . SysPostModel::query()->count() . ' 个',
                'tone'   => 'success',
                'to'     => '/system/dept',
                'perm'   => 'sys:dept:list',
            ],
            [
                'key'    => 'auth',
                'label'  => '角色',
                'value'  => SysRoleModel::query()->count(),
                'unit'   => '个',
                'hint'   => '权限点 ' . SysPermissionModel::query()->enabled()->count() . ' 条',
                'tone'   => 'warning',
                'to'     => '/system/role',
                'perm'   => 'sys:role:list',
            ],
            [
                'key'    => 'today',
                'label'  => '今日登录',
                'value'  => $loginToday,
                'unit'   => '次',
                // 失败次数单独点出来：它是这张卡里唯一需要人去看一眼的信号
                'hint'   => $loginFailed > 0 ? "失败 {$loginFailed} 次" : '无失败',
                'tone'   => $loginFailed > 0 ? 'danger' : 'info',
                'to'     => '/log/login',
                'perm'   => 'sys:log:login:list',
                'extra'  => ['op_today' => $opToday, 'op_failed' => $opFailed],
            ],
        ];
    }

    /**
     * 近 7 天登录趋势
     *
     * 按天补齐：没有登录的那天也要有一个 0，否则折线会把两天连成一天，
     * 「周末没人登录」这种模式就看不出来了。
     */
    private static function loginTrend(): array
    {
        $from = date('Y-m-d', strtotime('-' . (self::TREND_DAYS - 1) . ' days')) . ' 00:00:00';

        // 一次查询同时拿总数与失败数：SUM(CASE...) 比再查一遍便宜，
        // 也不会出现两次查询之间刚好有人登录导致对不上的情况
        $rows = SysLoginLogModel::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day,
                         COUNT(*) as total,
                         SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as failed')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $row = $rows[$day] ?? null;
            $total  = (int) ($row->total ?? 0);
            $failed = (int) ($row->failed ?? 0);

            $out[] = [
                'day'     => $day,
                'label'   => date('m-d', strtotime($day)),
                'total'   => $total,
                'success' => $total - $failed,
                'failed'  => $failed,
            ];
        }

        return $out;
    }

    /** 最近的写操作，直接给日志页的入口 */
    private static function recentOperations(): array
    {
        return SysOperationLogModel::query()
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (SysOperationLogModel $row) => [
                'id'         => $row->id,
                'username'   => $row->username,
                'module'     => $row->module,
                'action'     => $row->action,
                'title'      => $row->title,
                'target'     => $row->target,
                'status'     => $row->status,
                'error_msg'  => $row->error_msg,
                'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    /**
     * 各模块的数据量
     *
     * 概览页的定位是「这套系统现在装了些什么」，所以列的是模块规模而不是业务指标。
     */
    private static function moduleSummary(): array
    {
        return [
            ['name' => '用户',     'count' => SysUserModel::query()->count(),                       'to' => '/system/user',  'perm' => 'sys:user:list'],
            ['name' => '部门',     'count' => SysDeptModel::query()->count(),                       'to' => '/system/dept',  'perm' => 'sys:dept:list'],
            ['name' => '岗位',     'count' => SysPostModel::query()->count(),                       'to' => '/system/post',  'perm' => 'sys:post:list'],
            ['name' => '角色',     'count' => SysRoleModel::query()->count(),                       'to' => '/system/role',  'perm' => 'sys:role:list'],
            ['name' => '权限点',   'count' => SysPermissionModel::query()->count(),                 'to' => '/system/menu',  'perm' => 'sys:menu:list'],
            ['name' => '字典',     'count' => SysDictTypeModel::query()->count(),                   'to' => '/system/dict',  'perm' => 'sys:dict:list'],
            ['name' => '参数',     'count' => SysParamModel::query()->count(),                      'to' => '/system/param', 'perm' => 'sys:param:list'],
            ['name' => '操作日志', 'count' => SysOperationLogModel::query()->count(),               'to' => '/log/operation', 'perm' => 'sys:log:operation:list'],
        ];
    }

    /**
     * 运行状态
     *
     * 只报真的能测到的东西。CPU / 磁盘占用这类要读宿主机指标，
     * 容器里读到的往往不是用户以为的那台机器，与其给个会误导的数字不如不给。
     */
    private static function systemStatus(): array
    {
        return [
            'php_version'    => PHP_VERSION,
            'workerman'      => Worker::VERSION,
            // 当前 worker 的常驻内存。webman 是多进程，这只是其中一个进程，
            // 但正是「内存有没有一路涨上去」要盯的那个数
            'memory_mb'      => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'db'             => self::probe(static fn () => Db::conn()->select('SELECT 1')),
            'redis'          => self::probe(static fn () => Cache::conn()->ping()),
            'slow_query_ms'  => Env::int('SLOW_QUERY_MS', 200),
            'server_time'    => date('Y-m-d H:i:s'),
        ];
    }

    /** 连通性探测，失败不抛——概览页不该因为 Redis 抖一下就整页打不开 */
    private static function probe(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
