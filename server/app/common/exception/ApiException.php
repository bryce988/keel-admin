<?php

declare(strict_types=1);

namespace app\common\exception;

use RuntimeException;

/**
 * 业务异常基类
 *
 * HTTP 状态码表大类、业务码表具体原因，两者都在这里携带，
 * 由异常处理器统一转换为响应，控制器不手写状态码。
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,      // HTTP 状态码
        public readonly int $bizCode,     // 业务码，见 docs/api.md §2.2
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, $bizCode);
    }
}
