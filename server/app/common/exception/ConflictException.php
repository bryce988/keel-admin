<?php

declare(strict_types=1);

namespace app\common\exception;

/** 409 唯一性冲突、被引用、乐观锁 */
class ConflictException extends ApiException
{
    public function __construct(string $message, int $bizCode = 10409)
    {
        parent::__construct(409, $bizCode, $message);
    }
}
