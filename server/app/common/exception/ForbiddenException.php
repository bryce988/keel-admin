<?php

declare(strict_types=1);

namespace app\common\exception;

/** 403 已认证但无权限 */
class ForbiddenException extends ApiException
{
    public function __construct(string $message = '无权限访问', int $bizCode = 10301)
    {
        parent::__construct(403, $bizCode, $message);
    }
}
