<?php
/**
 * keel admin
 * 过期日志清理
 *
 * 日志是全库增长最快的表，不清理迟早把磁盘吃满，而磁盘满了之后
 * 连「写一条日志说磁盘满了」都做不到——所以这件事必须是自动的。
 *
 * 保留天数走系统参数 `sys.log.retainDays`（默认 180 天），
 * 在参数配置页就能改，不用改代码也不用重启。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysLoginLogModel;
use app\common\model\SysOperationLogModel;
use support\Log;

class LogCleanupService
{
    private const RETAIN_KEY = 'sys.log.retainDays';

    /** 单次 DELETE 的行数上限，避免一条语句锁表太久 */
    private const CHUNK = 1000;

    /**
     * 一轮清理，返回各表删除的行数
     *
     * @return array{operation: int, login: int, before: string}
     */
    public static function run(): array
    {
        $days = max(1, (int) ParamService::value(self::RETAIN_KEY, 180));
        $before = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $operation = self::purge(SysOperationLogModel::class, $before);
        $login     = self::purge(SysLoginLogModel::class, $before);

        Log::info('日志清理完成', [
            'before'    => $before,
            'retain'    => $days,
            'operation' => $operation,
            'login'     => $login,
        ]);

        return ['operation' => $operation, 'login' => $login, 'before' => $before];
    }

    /**
     * 分批删除，直到没有更早的记录
     *
     * 必须 `withoutGlobalScopes()`：两张日志表都带 `HasDataScope`，
     * 而清理跑在定时任务/队列进程里——那里没有登录用户，
     * `Ctx::user()` 是空的。带着数据权限跑，轻则一行删不掉，
     * 重则被判成「无部门列」而全表放行，两种都不是我们要的。
     * 这里要的语义很明确：按时间删，与谁看得见无关。
     *
     * 一次删 1000 行而不是一条 DELETE 干掉几百万行：
     * 后者会长时间持有行锁，把正在写日志的请求全堵住。
     *
     * @param  class-string  $modelClass
     */
    private static function purge(string $modelClass, string $before): int
    {
        $total = 0;

        do {
            $deleted = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('created_at', '<', $before)
                ->limit(self::CHUNK)
                ->delete();

            $total += $deleted;
        } while ($deleted >= self::CHUNK);

        return $total;
    }
}
