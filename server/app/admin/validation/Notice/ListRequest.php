<?php

declare(strict_types=1);

namespace app\admin\validation\Notice;

use app\common\validation\FormRequest;

/** 公告列表的筛选条件（`GET /admin/notices`） */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],   // 标题 / 正文模糊匹配
            'status'  => ['in:0,1',        '状态'],     // 0 草稿 1 已发布
            'type'    => ['string|max:32', '类型'],     // 字典 notice_type
        ];
    }
}
