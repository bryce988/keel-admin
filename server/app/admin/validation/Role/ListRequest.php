<?php

declare(strict_types=1);

namespace app\admin\validation\Role;

use app\common\validation\FormRequest;

/** 角色列表的筛选条件（`GET /admin/roles`） */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword'    => ['string|max:64', '关键词'],
            'status'     => ['in:0,1',        '状态'],
            'data_scope' => ['in:1,2,3,4,5',  '数据范围'],
        ];
    }
}
