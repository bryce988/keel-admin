<?php
/**
 * keel admin
 * 问答没法正常结束（被内容审核拦截、步骤超限、服务商过载）
 *
 * 与 LlmException 分开：那边是「调用失败」（带 HTTP 状态码），这边是「调用成功了但没有可用的回答」。
 * message 就是给用户看的话。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

final class AnswerException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $refundQuota = true)
    {
        parent::__construct($message);
    }
}
