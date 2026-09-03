<?php

declare(strict_types=1);

namespace app\staff\validation\Profile;

use app\common\validation\FormRequest;

/**
 * 改个人资料（`PUT /staff/v1/profile`）
 *
 * 手机上只放姓名与邮箱：手机号换绑要验当前密码（api.md §11），
 * 是独立流程；头像走上传接口，一步到位不需要再提交表单。
 */
class UpdateRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'real_name' => ['required|string|max:64',  '姓名'],
            'email'     => ['string|email|max:128',    '邮箱'],
        ];
    }
}
