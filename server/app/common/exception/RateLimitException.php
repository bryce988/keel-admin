<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 429 限流 */
class RateLimitException extends ApiException
{
    public function __construct(
        string $message = '操作过于频繁，请稍后再试',
        public readonly int $retryAfter = 60,
        // 默认是通用的「请求过于频繁」；AI 配额用尽这类需要前端区别对待的，传自己的码
        int $bizCode = BizCode::RATE_LIMITED,
    ) {
        parent::__construct(HttpStatus::TOO_MANY_REQUESTS, $bizCode, $message);
    }
}
