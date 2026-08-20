<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 409 唯一性冲突、被引用、乐观锁 */
class ConflictException extends ApiException
{
    public function __construct(string $message, int $bizCode = BizCode::CONFLICT)
    {
        parent::__construct(HttpStatus::CONFLICT, $bizCode, $message);
    }
}
