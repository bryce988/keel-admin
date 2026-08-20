<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 401 未认证：未登录、token 失效、登录失败 */
class UnauthorizedException extends ApiException
{
    public function __construct(string $message = '登录已过期，请重新登录', int $bizCode = BizCode::UNAUTHORIZED)
    {
        parent::__construct(HttpStatus::UNAUTHORIZED, $bizCode, $message);
    }
}
