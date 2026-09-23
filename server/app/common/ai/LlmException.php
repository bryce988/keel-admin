<?php
/**
 * keel admin
 * 模型服务调用失败
 *
 * 带两样东西给上层：HTTP 状态码（落 ai_runs.http_status，401/402 要一眼看出来）
 * 与「给用户看的话」。原始错误体只进日志——里面可能有请求回显。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

final class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        /** 服务商侧的故障（过载、网络）：返还提问人的配额。我们配错了（401/402）同样返还 */
        public readonly bool $refundQuota = true,
        /** 需要提醒管理员的故障：密钥错、余额不足 */
        public readonly bool $alertAdmin = false,
    ) {
        parent::__construct($message);
    }
}
