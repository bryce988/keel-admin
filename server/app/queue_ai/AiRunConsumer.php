<?php
/**
 * keel admin
 * 消费「回答一个问题」任务
 *
 * ## 为什么在单独的目录、单独的进程组里
 *
 * 一次问答 5~90 秒。和导出、日志清理挤在默认那组消费进程里（`QUEUE_WORKERS=2`），
 * 两个人同时问小k，导出就要排队一分半。所以 AI 有自己的进程组（`AI_WORKERS`，
 * 见 config/plugin/webman/redis-queue/process.php）。
 *
 * ⚠️ 目录必须是 `app/queue_ai/` 而**不能**是 `app/queue/ai/`：插件用 RecursiveDirectoryIterator
 * 扫 consumer_dir，子目录会被默认那组进程一起加载，两组进程抢同一个队列，隔离形同虚设。
 *
 * 真正的活在 `AiRunner::run()`，包括**以提问人身份执行**这件要命的事（docs/ai-tech.md §4）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\queue_ai;

use app\common\ai\AiRunner;
use app\common\service\AiService;
use support\Log;
use Throwable;
use Webman\RedisQueue\Consumer;

class AiRunConsumer implements Consumer
{
    public string $desc = 'AI 助手「小k」回答问题';

    public string $queue = AiService::QUEUE;

    public string $connection = 'default';

    public function consume($data): void
    {
        $runId = (int) ($data['run_id'] ?? 0);
        if ($runId <= 0) {
            Log::warning('AI 队列收到无效消息', ['data' => $data]);

            return;
        }

        AiRunner::run($runId);
    }

    /**
     * 兜底
     *
     * `AiRunner` 内部已经把失败写回 run 并补了消息。走到这里说明它自己都没跑起来
     * （数据库连不上之类），run 会停在「排队中」——提问人下次打开时 `activeRun()` 会把它判失败。
     */
    public function onConsumeFailure(Throwable $e, $package): void
    {
        Log::error('AI 队列消费失败', [
            'run'      => $package['data']['run_id'] ?? 0,
            'error'    => $e->getMessage(),
            'attempts' => $package['attempts'] ?? 0,
        ]);
    }
}
