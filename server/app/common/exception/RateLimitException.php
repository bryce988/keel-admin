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
    ) {
        parent::__construct(HttpStatus::TOO_MANY_REQUESTS, BizCode::RATE_LIMITED, $message);
    }
}
