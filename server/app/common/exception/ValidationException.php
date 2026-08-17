<?php

declare(strict_types=1);

namespace app\common\exception;

/** 422 参数校验失败，details 携带字段级错误 */
class ValidationException extends ApiException
{
    /**
     * @param  int  $bizCode  默认通用码 10422；有专属码时传入（如新密码不合规是 20006）
     */
    public function __construct(array $details, string $message = '参数校验失败', int $bizCode = 10422)
    {
        parent::__construct(422, $bizCode, $message, $details);
    }
}
