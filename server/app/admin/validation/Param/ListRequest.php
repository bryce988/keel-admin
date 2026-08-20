<?php

declare(strict_types=1);

namespace app\admin\validation\Param;

use app\admin\validation\FormRequest;
use app\common\service\ParamService;

/** 系统参数列表的筛选条件（`GET /admin/params`）；`group` 不传返回全部 */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'group' => ['string|in:' . implode(',', array_keys(ParamService::GROUPS)), '分组'],
        ];
    }
}
