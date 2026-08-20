<?php

declare(strict_types=1);

namespace app\admin\validation\Param;

/**
 * 编辑系统参数（`PUT /admin/params/{id}`）
 *
 * 部分更新：`group` / `name` / `param_key` 不必填，其余规则与新增完全一致。
 * 编辑内置参数时 service 还会忽略键与类型的改动。
 */
final class UpdateRequest extends StoreRequest
{
    protected function isCreating(): bool
    {
        return false;
    }
}
