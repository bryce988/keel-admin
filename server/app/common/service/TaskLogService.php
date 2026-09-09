<?php
/**
 * keel admin
 * 定时任务执行日志
 *
 * 记的是「每次定时任务跑出什么结果」。链路上有两个进程，缺一不可：
 *
 *   TaskProcess（投递）  →  start()   插入一行，status = 排队中
 *   队列消费进程（干活）  →  track()   计时执行，回填成功/失败、耗时、结果摘要
 *
 * ## 为什么投递时就写，而不是干完再写
 *
 * 「投出去了但没人消费」是这套机制最典型的故障（消费者被删、队列名拼错、
 * 消费进程根本没起来），而它**不抛任何异常**——干完才写的话，
 * 这种故障在日志里一条记录都没有，界面上看着一切正常。
 * 先写一行，那次执行就会一直停在「排队中」，一眼能看见。
 *
 * ## 放 common 而不是 admin
 *
 * 写入方是 `app/process` 与 `app/queue`（不属于任何端），读取方是后台。
 * `app/common` 是被依赖方，反过来 use `app/admin` 是禁止的（CLAUDE.md）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SysTaskLogModel;
use support\Log;
use Throwable;

class TaskLogService
{
    /** `message` 列宽 500，摘要与错误都按它截断 */
    private const MESSAGE_MAX = 500;

    /**
     * 投递时落一行「排队中」，返回日志 id
     *
     * 返回 0 表示这次没记上（库连不上之类）。**不抛异常**：任务日志是旁路，
     * 它挂了不该把定时任务本身带停——那会把一个「看不到记录」的小问题
     * 升级成「任务不跑了」的大问题。
     */
    public static function start(string $taskName, string $taskDesc, string $queue, string $trigger = 'cron'): int
    {
        try {
            $log = new SysTaskLogModel();
            $log->task_name = $taskName;
            // 说明冗余存一份：以后改了代码里的措辞，历史记录仍是当时那句
            $log->task_desc = $taskDesc;
            $log->queue     = $queue;
            $log->trigger   = $trigger;
            $log->status    = SysTaskLogModel::STATUS_PENDING;
            $log->save();

            return (int) $log->id;
        } catch (Throwable $e) {
            Log::error('任务日志：写入失败', ['task' => $taskName, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * 消费者用它包住真正的活：计时 + 记结果 + 原样抛出异常
     *
     *   public function consume($data): void
     *   {
     *       TaskLogService::track($data, fn () => LogCleanupService::run());
     *   }
     *
     * 异常**必须原样抛出**：吞掉就等于告诉队列「这条消息处理成功了」，
     * 重试机制形同虚设（见 `config/plugin/webman/redis-queue/redis.php` 的注释）。
     * 所以这里只负责在抛出前把失败写进日志。
     *
     * `$data` 里没有 `task_log_id`（比如手动往队列里投了一条）时不记日志，
     * 照常执行——这条链路对业务方是可选的。
     *
     * @template T
     * @param  array<string, mixed>  $data  队列消息体
     * @param  callable(): T  $work
     * @return T
     */
    public static function track(array $data, callable $work): mixed
    {
        $id    = (int) ($data['task_log_id'] ?? 0);
        $start = microtime(true);

        try {
            $result = $work();
        } catch (Throwable $e) {
            self::finish($id, SysTaskLogModel::STATUS_FAIL, $e->getMessage(), $start);

            throw $e;
        }

        self::finish($id, SysTaskLogModel::STATUS_SUCCESS, self::summarize($result), $start);

        return $result;
    }

    /**
     * 回填结果
     *
     * 同样吞掉自身异常：日志写不进去不该影响任务的成败判定。
     */
    private static function finish(int $id, int $status, string $message, float $start): void
    {
        if ($id <= 0) {
            return;
        }

        try {
            SysTaskLogModel::query()->where('id', $id)->update([
                'status'      => $status,
                'message'     => mb_substr($message, 0, self::MESSAGE_MAX),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Log::error('任务日志：回填失败', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 把消费者的返回值压成一句摘要
     *
     * 清理类任务返回的是 `['operation' => 12, 'login' => 3, ...]` 这种数组，
     * 原样 json 存下来就是「这次到底干了多少活」，比一句「成功」有用得多。
     */
    private static function summarize(mixed $result): string
    {
        if ($result === null || $result === true) {
            return '执行成功';
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 投递失败时直接记成失败
     *
     * Redis 挂了这类情况：任务根本没进队列，不会有消费者来回填，
     * 不标一下就会永远挂在「排队中」，与「没人消费」混为一谈。
     */
    public static function markDispatchFailed(int $id, string $error): void
    {
        self::finish($id, SysTaskLogModel::STATUS_FAIL, '投递失败：' . $error, microtime(true));
    }

    /**
     * 按时间清理（供 LogCleanupService 调用）
     *
     * 与两张业务日志表同一个保留期：任务日志同样只增不减，
     * 一天两条看着不多，三年就是两千多行、且没人会去删。
     */
    public static function purge(string $before, int $chunk = 1000): int
    {
        $total = 0;

        do {
            $deleted = SysTaskLogModel::query()
                ->where('created_at', '<', $before)
                ->limit($chunk)
                ->delete();

            $total += $deleted;
        } while ($deleted >= $chunk);

        return $total;
    }
}
