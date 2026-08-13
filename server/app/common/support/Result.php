<?php

declare(strict_types=1);

namespace app\common\support;

use support\Response;

/**
 * 统一响应
 *
 * 约定见 docs/api.md §1.2：
 * - 成功只有 2xx，直接返回数据本体，不包 code 信封
 * - 错误返回 4xx/5xx + { code, message, traceId }
 * - 所有响应写入 X-Trace-Id 响应头
 */
class Result
{
    /** 200 查询 / 更新成功 */
    public static function ok(mixed $data = null): Response
    {
        return self::json(200, $data ?? new \stdClass());
    }

    /** 201 创建成功 */
    public static function created(mixed $data = null): Response
    {
        return self::json(201, $data ?? new \stdClass());
    }

    /** 204 删除等无返回内容的成功 */
    public static function noContent(): Response
    {
        return (new Response(204))->withHeaders(self::headers());
    }

    /** 分页列表 */
    public static function page(array $list, int $total, int $pageNum, int $pageSize): Response
    {
        return self::json(200, [
            'list'     => $list,
            'total'    => $total,
            'pageNum'  => $pageNum,
            'pageSize' => $pageSize,
        ]);
    }

    /** 错误响应，由异常处理器统一调用，业务代码请抛异常而不是直接构造 */
    public static function error(int $status, int $code, string $message, ?array $details = null): Response
    {
        $body = [
            'code'    => $code,
            'message' => $message,
            'traceId' => Ctx::traceId(),
        ];
        if ($details !== null) {
            $body['details'] = $details;
        }

        return self::json($status, $body);
    }

    private static function json(int $status, mixed $body): Response
    {
        return (new Response($status, self::headers(), json_encode($body, JSON_UNESCAPED_UNICODE)));
    }

    private static function headers(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Trace-Id'   => Ctx::traceId(),
        ];
    }
}
