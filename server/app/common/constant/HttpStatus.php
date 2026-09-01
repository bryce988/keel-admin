<?php

declare(strict_types=1);

namespace app\common\constant;

/**
 * HTTP 状态码（大类）
 *
 * 与 docs/api.md §2.1 一一对应，是「响应行状态码」的唯一权威来源。
 * 成功态由 {@see \app\common\support\Result} 使用，错误态由各异常类使用，
 * 别处不要再写裸数字。
 */
final class HttpStatus
{
    // ---------------------------------------------------------------- 2xx 成功
    public const OK = 200;
    public const CREATED = 201;
    // 已接收、还没做完：异步导出建完任务就返回它，文件由队列稍后生成
    public const ACCEPTED = 202;
    public const NO_CONTENT = 204;

    // ---------------------------------------------------------------- 4xx 客户端
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const CONFLICT = 409;
    // 记录还在、它指向的东西没了（导出文件过期被回收）。
    // 与 404 的区别对用户是有意义的：404 该返回列表，410 该重做一次
    public const GONE = 410;
    public const UNPROCESSABLE_ENTITY = 422;
    public const TOO_MANY_REQUESTS = 429;

    // ---------------------------------------------------------------- 5xx 服务端
    public const INTERNAL_SERVER_ERROR = 500;
    public const SERVICE_UNAVAILABLE = 503;
}
