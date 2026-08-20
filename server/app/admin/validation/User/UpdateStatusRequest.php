<?php

declare(strict_types=1);

namespace app\admin\validation\User;

use app\admin\validation\FormRequest;

/**
 * 启用 / 停用用户（`PUT /admin/users/{id}/status`）
 */
final class UpdateStatusRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'status' => ['required|integer|in:0,1', '状态'],
        ];
    }
}
