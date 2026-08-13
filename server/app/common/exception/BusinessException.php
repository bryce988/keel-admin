<?php

declare(strict_types=1);

namespace app\common\exception;

/** 400 业务规则不允许 */
class BusinessException extends ApiException
{
    public function __construct(string $message, int $bizCode = 10400)
    {
        parent::__construct(400, $bizCode, $message);
    }
}
