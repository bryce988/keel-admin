<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 403 已认证但无权限 */
class ForbiddenException extends ApiException
{
    public function __construct(string $message = '无权限访问', int $bizCode = BizCode::FORBIDDEN)
    {
        parent::__construct(HttpStatus::FORBIDDEN, $bizCode, $message);
    }
}
