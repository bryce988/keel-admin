<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 422 参数校验失败，details 携带字段级错误 */
class ValidationException extends ApiException
{
    /**
     * @param  int  $bizCode  默认通用码 {@see BizCode::VALIDATION_FAILED}；有专属码时传入
     *                        （如新密码不合规是 {@see BizCode::PASSWORD_POLICY_VIOLATION}）
     */
    public function __construct(array $details, string $message = '参数校验失败', int $bizCode = BizCode::VALIDATION_FAILED)
    {
        parent::__construct(HttpStatus::UNPROCESSABLE_ENTITY, $bizCode, $message, $details);
    }
}
