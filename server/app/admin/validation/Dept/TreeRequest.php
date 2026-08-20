<?php

declare(strict_types=1);

namespace app\admin\validation\Dept;

use app\admin\validation\FormRequest;

/**
 * 部门树的筛选条件（`GET /admin/depts`）
 *
 * 用户列表的部门筛选下拉也打这个接口，所以两个权限点任一即可。
 */
final class TreeRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword' => ['string|max:64', '关键词'],   // 名称 / 编码模糊匹配
            'status'  => ['in:0,1',        '状态'],     // 0 停用 1 启用
        ];
    }
}
