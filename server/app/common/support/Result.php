<?php

declare(strict_types=1);

namespace app\common\support;

use app\common\constant\HttpStatus;
use support\Response;

/**
 * 统一响应
 *
 * 约定见 docs/api.md §1.2：
 * - 成功只有 2xx，直接返回数据本体，不包 code 信封
 * - 错误返回 4xx/5xx + { code, message, trace_id }
 * - 字段名一律 snake_case，与数据库字段名一致
 * - 所有响应写入 X-Trace-Id 响应头（HTTP 头保持惯用的中划线写法）
 */
class Result
{
    /** 200 查询 / 更新成功 */
    public static function ok(mixed $data = null): Response
    {
        return self::json(HttpStatus::OK, $data ?? new \stdClass());
    }

    /** 201 创建成功 */
    public static function created(mixed $data = null): Response
    {
        return self::json(HttpStatus::CREATED, $data ?? new \stdClass());
    }

    /**
     * 202 已接收，处理还没完成
     *
     * 异步任务专用（目前只有数据导出）：请求本身成功了，但结果要等队列。
     * 不用 200 是因为前端要能区分「事情做完了」和「事情排上队了」——
     * 后者的提示语、后续动作都不一样（不是「导出成功」，是「去导出列表等」）。
     */
    public static function accepted(mixed $data = null): Response
    {
        return self::json(HttpStatus::ACCEPTED, $data ?? new \stdClass());
    }

    /** 204 删除等无返回内容的成功 */
    public static function noContent(): Response
    {
        return (new Response(HttpStatus::NO_CONTENT))->withHeaders(self::headers());
    }

    /** 分页列表 */
    public static function page(array $list, int $total, int $pageNum, int $pageSize): Response
    {
        return self::json(HttpStatus::OK, [
            'list'      => $list,
            'total'     => $total,
            'page_num'  => $pageNum,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 文件下载
     *
     * webman 自带的 `download()` 只写 `filename="原始字节"`，中文名在部分客户端会乱码。
     * 这里按 RFC 6266 同时给两种形式：ASCII 兜底 + `filename*=UTF-8''` 百分号编码，
     * 认得 `filename*` 的客户端优先用它，老客户端退回 ASCII 名。
     */
    public static function download(string $path, string $filename): Response
    {
        // ASCII 兜底名：去掉所有非 ASCII 字符，只留扩展名可辨认
        $ascii = preg_replace('/[^\x20-\x7e]/', '', $filename) ?: 'download';
        $ascii = str_replace(['"', "\r", "\n", "\0"], '', $ascii);

        return (new Response())
            ->withFile($path)
            ->withHeaders([
                'Content-Disposition' => sprintf(
                    "attachment; filename=\"%s\"; filename*=UTF-8''%s",
                    $ascii,
                    rawurlencode($filename)
                ),
                // 让前端能读到这个头：跨域时不暴露的话 JS 拿不到文件名
                'Access-Control-Expose-Headers' => 'Content-Disposition',
                'X-Trace-Id' => Ctx::traceId(),
            ]);
    }

    /** 错误响应，由异常处理器统一调用，业务代码请抛异常而不是直接构造 */
    public static function error(int $status, int $code, string $message, ?array $details = null): Response
    {
        $body = [
            'code'     => $code,
            'message'  => $message,
            'trace_id' => Ctx::traceId(),
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
