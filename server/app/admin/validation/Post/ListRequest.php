<?php

declare(strict_types=1);

namespace app\admin\validation\Post;

use app\admin\validation\FormRequest;

/** 岗位列表的筛选条件（`GET /admin/posts`） */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],   // 名称 / 编码模糊匹配
            'status'  => ['in:0,1',        '状态'],
            'dept_id' => ['integer|min:1', '部门'],
        ];
    }
}
