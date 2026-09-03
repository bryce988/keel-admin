<?php

declare(strict_types=1);

namespace app\admin\validation\Menu;

use app\common\validation\FormRequest;

/** 菜单/权限树的筛选条件（`GET /admin/menus/tree`） */
final class TreeRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],
            'type'    => ['in:1,2,3,4,5',  '类型'],   // 1 目录 2 菜单 3 按钮 4 接口 5 字段
            'status'  => ['in:0,1',        '状态'],
        ];
    }
}
