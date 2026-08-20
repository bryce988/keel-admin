<?php

declare(strict_types=1);

namespace app\common\exception;

/**
 * C 端错误结构
 *
 * { code, message }
 *
 * 与后台的三点不同，都是刻意的：
 * - 不返回 details：C 端表单只有几个字段，一句话提示就够，
 *   多返回一层结构只会让客户端多写解析代码
 * - 不返回 trace_id：终端用户不是同事，内部标识没必要暴露给他们；
 *   排查仍可用响应头 X-Trace-Id，客户端埋点上报即可
 * - 500 的文案永远是固定话术，不带任何内部信息
 */
class ClientHandler extends AbstractHandler
{
    protected function format(int $status, int $bizCode, string $message, ?array $details): array
    {
        return [
            'code'    => $bizCode,
            'message' => $status >= 500 ? '服务开小差了，请稍后再试' : $message,
        ];
    }
}
