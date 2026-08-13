<?php

declare(strict_types=1);

namespace app\common\exception;

/** 422 参数校验失败，details 携带字段级错误 */
class ValidationException extends ApiException
{
    public function __construct(array $details, string $message = '参数校验失败')
    {
        parent::__construct(422, 10422, $message, $details);
    }
}
