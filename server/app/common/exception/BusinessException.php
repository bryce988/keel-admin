<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 400 业务规则不允许 */
class BusinessException extends ApiException
{
    public function __construct(string $message, int $bizCode = BizCode::GENERAL_BAD_REQUEST)
    {
        parent::__construct(HttpStatus::BAD_REQUEST, $bizCode, $message);
    }
}
