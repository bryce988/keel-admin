<?php
/**
 * keel admin
 * 一次问答的「该不该停」
 *
 * 两个来源：用户点了「停止」（Redis 标记 `ai:cancel:<run_id>`，由 HTTP 进程写），
 * 与墙钟超时（参数 `ai.run.timeout`）。
 *
 * 被流式回调频繁调用（一次回答几百个 chunk），所以查 Redis 做了节流：
 * 最多每 300ms 查一次。「停止」晚 0.3 秒生效用户感觉不到，每个 chunk 都打一次 Redis 却是实打实的开销。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

use app\common\support\Cache;

final class RunGuard
{
    public const REASON_CANCEL  = 'cancel';
    public const REASON_TIMEOUT = 'timeout';

    private const CHECK_INTERVAL = 0.3;

    private ?string $reason = null;
    private float $lastCheck = 0.0;

    public function __construct(
        private readonly int $runId,
        private readonly float $deadline,
    ) {
    }

    public static function cancelKey(int $runId): string
    {
        return 'ai:cancel:' . $runId;
    }

    public function shouldStop(): bool
    {
        if ($this->reason !== null) {
            return true;
        }

        $now = microtime(true);
        if ($now >= $this->deadline) {
            $this->reason = self::REASON_TIMEOUT;

            return true;
        }

        if ($now - $this->lastCheck >= self::CHECK_INTERVAL) {
            $this->lastCheck = $now;
            if (Cache::exists(self::cancelKey($this->runId))) {
                $this->reason = self::REASON_CANCEL;

                return true;
            }
        }

        return false;
    }

    /** 停下来的原因，没停为 null */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
