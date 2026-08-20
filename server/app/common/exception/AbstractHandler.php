<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;
use app\common\support\Ctx;
use app\common\support\Env;
use support\Log;
use Throwable;
use Webman\Exception\ExceptionHandler;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 异常处理器基类
 *
 * 「怎么把异常映射成状态码」四个端完全一致，所以放在这里；
 * 「错误体长什么样」各端不同，由子类实现 format()——
 * 后台要字段级 details 方便联调，C 端只给精简提示，
 * 开放平台按第三方习惯给字符串错误码（PROJECT.md §8.3）。
 *
 * webman 按 $request->app 选处理器，而 app 是从控制器命名空间推出来的，
 * 所以**闭包路由拿不到分端处理器**，各端的接口必须落在自己的 controller 里。
 */
abstract class AbstractHandler extends ExceptionHandler
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

    /**
     * 各端的错误体结构
     *
     * @param  array|null  $details  字段级校验明细，仅 422 时有值
     */
    abstract protected function format(int $status, int $bizCode, string $message, ?array $details): array;

    public function render(Request $request, Throwable $e): Response
    {
        if ($e instanceof ApiException) {
            $response = $this->json(
                $e->status,
                $this->format($e->status, $e->bizCode, $e->getMessage(), $e->details)
            );

            if ($e instanceof RateLimitException) {
                $response = $response->withHeader('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        }

        // 未捕获异常：完整信息进 error 通道，只对外暴露 traceId
        $traceId = Ctx::traceId();
        Log::channel('error')->error(sprintf(
            "[%s] %s %s\n%s in %s:%d\n%s",
            $traceId,
            $request->method(),
            $request->path(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        $message = Env::isProd()
            ? '服务暂时不可用，请稍后重试'
            : $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();

        return $this->json(
            HttpStatus::INTERNAL_SERVER_ERROR,
            $this->format(HttpStatus::INTERNAL_SERVER_ERROR, BizCode::INTERNAL_ERROR, $message, null)
        );
    }

    protected function json(int $status, array $body): Response
    {
        return new Response($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Trace-Id'   => Ctx::traceId(),
        ], json_encode($body, JSON_UNESCAPED_UNICODE));
    }
}
