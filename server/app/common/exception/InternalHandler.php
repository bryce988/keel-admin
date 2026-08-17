<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\support\Ctx;

/**
 * 内部服务错误结构
 *
 * { code, message, trace_id, details?, exception? }
 *
 * 调用方是我们自己的服务，不是人也不是第三方，所以**信息给足**：
 * 非业务异常时连异常类名一起返回，调用方的日志里直接能看到根因，
 * 不用再去两个服务的日志里对 traceId。
 *
 * 这依赖 /internal/* 只在内网可达（PROJECT.md §8.1），
 * 一旦这组接口暴露到公网，这里必须立刻收紧。
 */
class InternalHandler extends AbstractHandler
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

        if ($status >= 500) {
            $body['exception'] = true;   // 具体堆栈在 error 通道，按 trace_id 查
        }

        return $body;
    }
}
