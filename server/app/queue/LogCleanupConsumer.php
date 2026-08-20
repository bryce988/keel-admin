<?php

declare(strict_types=1);

namespace app\queue;

use app\common\service\LogCleanupService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

/**
 * 消费「清理过期日志」任务
 *
 * 为什么绕一道队列，而不让定时任务直接删：
 * 定时任务进程是 `count => 1`（多进程会重复触发），所以那一个进程里
 * 任何耗时操作都会推迟后面所有的计划任务。日志表大的时候一轮清理
 * 可能跑几分钟，期间别的定时任务全在排队。
 * 投进队列之后，定时任务只负责「到点了」这一件事，几毫秒就返回。
 *
 * 这也是业务方投递任务的写法参考：
 *   Redis::send('keel:your-queue', ['id' => 1]);
 * 对应写一个 `app/queue/XxxConsumer.php`，`$queue` 填同一个名字即可，
 * 不需要在任何地方注册——消费进程按目录扫描。
 */
class LogCleanupConsumer implements Consumer
{
    /**
     * 队列名带 `keel:` 前缀
     *
     * 队列 db 里除了我们还可能有别人的 key，前缀让 `KEYS keel:*` 一眼看清
     * 哪些队列是这个系统的。业务方新增队列建议沿用这个前缀。
     */
    public string $queue = 'keel:log-cleanup';

    public string $connection = 'default';

    public function consume($data): void
    {
        $result = LogCleanupService::run();

        Log::info('队列：日志清理', $result + ['trigger' => $data['trigger'] ?? 'unknown']);
    }

    /**
     * 重试 5 次仍失败时的兜底
     *
     * 不吞异常、也不在这里重试——消息会自动进 `keel:log-cleanup-failed`，
     * 这里只负责留下一条能被搜到的记录。清理失败不影响主流程，
     * 但连续失败意味着磁盘迟早要满，所以记的是 error 级别。
     */
    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('队列：日志清理失败', [
            'error'    => $e->getMessage(),
            'attempts' => $package['attempts'] ?? 0,
        ]);
    }
}
