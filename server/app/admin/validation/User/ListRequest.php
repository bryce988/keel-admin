<?php

declare(strict_types=1);

namespace app\admin\validation\User;

use app\admin\validation\FormRequest;

/**
 * 用户列表 / 导出的筛选条件（`GET /admin/users`、`GET /admin/users/export`）
 *
 * 列表与导出共用同一个类是必须的：分开写迟早出现「界面上筛出 20 条，
 * 导出来 2000 条」。
 */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],
            'status'  => ['in:0,1,2',      '状态'],
            'dept_id' => ['integer|min:1', '部门'],
        ];
    }
}
