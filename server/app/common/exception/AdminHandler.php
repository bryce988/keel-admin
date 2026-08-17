<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\support\Ctx;

/**
 * 管理后台错误结构
 *
 * { code, message, trace_id, details? }
 *
 * 带 details 是因为后台表单字段多，422 时前端要按字段回填错误；
 * 带 trace_id 是因为用户就是同事，报障时直接截图给运维定位。
 */
class AdminHandler extends AbstractHandler
{
    protected function format(int $status, int $bizCode, string $message, ?array $details): array
    {
        $body = [
            'code'     => $bizCode,
            'message'  => $message,
            'trace_id' => Ctx::traceId(),
        ];

        if ($details !== null) {
            $body['details'] = $details;
        }

        return $body;
    }
}
