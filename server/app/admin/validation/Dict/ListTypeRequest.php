<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

use app\admin\validation\FormRequest;

/**
 * 字典类型列表的筛选条件（`GET /admin/dicts`）
 *
 * 与 {@see ListItemRequest} 规则相同但语义不同：这里的 `keyword` 匹配的是
 * 字典的名称与编码，那边匹配的是字典项的文案与值。分成两个类是为了让
 * 其中一边加筛选项时不会悄悄影响另一边。
 */
final class ListTypeRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],   // 字典名称 / 编码
            'status'  => ['in:0,1',        '状态'],
        ];
    }
}
