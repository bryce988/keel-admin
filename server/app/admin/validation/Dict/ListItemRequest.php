<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

use app\common\validation\FormRequest;

/**
 * 字典项列表的筛选条件（`GET /admin/dicts/{code}/items/all`）
 *
 * 字典编码走路由段，不在这里校验。与 {@see ListTypeRequest} 的区别见那边的注释。
 */
final class ListItemRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],   // 字典项文案 / 值
            'status'  => ['in:0,1',        '状态'],
        ];
    }
}
