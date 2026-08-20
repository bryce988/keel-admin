<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\support\Ctx;

/**
 * 开放平台错误结构
 *
 * { error_code, error_message, request_id }
 *
 * 用字符串错误码而不是数字：第三方的代码里 `if (err.error_code === 'INVALID_SIGNATURE')`
 * 比 `if (code === 40102)` 可读得多，也不会因为我们内部重排码段而失效。
 * 数字码是内部实现细节，不该成为对外契约的一部分。
 *
 * request_id 与后台的 trace_id 是同一个值，换个名字是因为
 * 「请求 ID」才是第三方文档里的通行叫法。
 */
class OpenHandler extends AbstractHandler
{
    /** 业务码 → 对外的稳定字符串标识 */
    private const CODE_MAP = [
        BizCode::UNAUTHORIZED        => 'UNAUTHORIZED',
        BizCode::FORBIDDEN           => 'FORBIDDEN',
        BizCode::NOT_FOUND           => 'NOT_FOUND',
        BizCode::CONFLICT            => 'CONFLICT',
        BizCode::VALIDATION_FAILED   => 'INVALID_PARAMETER',
        BizCode::RATE_LIMITED        => 'RATE_LIMIT_EXCEEDED',
        BizCode::INTERNAL_ERROR      => 'INTERNAL_ERROR',
        BizCode::INVALID_SIGNATURE   => 'INVALID_SIGNATURE',
        BizCode::SIGNATURE_EXPIRED   => 'SIGNATURE_EXPIRED',
        BizCode::DUPLICATE_NONCE     => 'DUPLICATE_NONCE',
        BizCode::UNKNOWN_APP_KEY     => 'UNKNOWN_APP_KEY',
        BizCode::IP_NOT_ALLOWED      => 'IP_NOT_ALLOWED',
    ];

    protected function format(int $status, int $bizCode, string $message, ?array $details): array
    {
        return [
            'error_code'    => self::CODE_MAP[$bizCode] ?? 'ERROR',
            'error_message' => $message,
            'request_id'    => Ctx::traceId(),
        ];
    }
}
