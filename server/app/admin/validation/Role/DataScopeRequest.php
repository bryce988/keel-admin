<?php

declare(strict_types=1);

namespace app\admin\validation\Role;

use app\admin\validation\FormRequest;

/**
 * 设置角色数据范围（`PUT /admin/roles/{id}/data-scope`）
 *
 * `dept_ids` 只在 `data_scope=5`（自定义）时有意义，其余四种范围传了也会被
 * service 忽略——这条「什么时候该带部门」的业务规则不在这里判，
 * 它失败是 400 而不是 422。
 */
final class DataScopeRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'data_scope' => ['required|integer|in:1,2,3,4,5', '数据范围'],
            // 只校验「是数组」，元素类型没管——service 会 intval 后按存在的部门过滤。
            // 换到 illuminate 之后可以加 'dept_ids.*' => ['integer|min:1', '部门'] 收紧
            'dept_ids'   => ['array',                         '部门'],
        ];
    }
}
