<?php

declare(strict_types=1);

namespace app\common\exception;

/** 404 不存在（含存在但无权见的统一伪装） */
class NotFoundException extends ApiException
{
    public function __construct(string $message = '数据不存在或已被删除', int $bizCode = 10404)
    {
        parent::__construct(404, $bizCode, $message);
    }
}
