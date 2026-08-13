<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\support\Ctx;
use app\common\support\Env;
use app\common\support\Result;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 统一异常处理
 *
 * 把异常映射为「HTTP 状态码 + 业务码」，业务代码只管抛，不管拼响应。
 * 生产环境不返回堆栈，只给 traceId，详情进日志。
 */
class Handler extends ExceptionHandler
{
    /** 这些异常属于可预期的业务流转，不写错误日志 */
    public $dontReport = [
        ApiException::class,
        BusinessException::class,
        UnauthorizedException::class,
        ForbiddenException::class,
        NotFoundException::class,
        ConflictException::class,
        ValidationException::class,
        RateLimitException::class,
    ];

    public function render(Request $request, Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            $response = Result::error($e->status, $e->bizCode, $e->getMessage(), $e->details);

            if ($e instanceof RateLimitException) {
                $response = $response->withHeader('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        }

        // 未捕获异常：记录完整信息，只对外暴露 traceId
        $traceId = Ctx::traceId();
        \support\Log::error(sprintf(
            "[%s] %s in %s:%d\n%s",
            $traceId,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        $message = Env::isProd()
            ? '服务暂时不可用，请稍后重试'
            : $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();

        return Result::error(500, 10500, $message);
    }
}
