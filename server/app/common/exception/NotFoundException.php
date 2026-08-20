<?php

declare(strict_types=1);

namespace app\common\exception;

use app\common\constant\BizCode;
use app\common\constant\HttpStatus;

/** 404 不存在（含存在但无权见的统一伪装） */
class NotFoundException extends ApiException
{
    public function __construct(string $message = '数据不存在或已被删除', int $bizCode = BizCode::NOT_FOUND)
    {
        parent::__construct(HttpStatus::NOT_FOUND, $bizCode, $message);
    }
}
