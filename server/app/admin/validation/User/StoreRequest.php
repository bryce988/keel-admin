<?php

declare(strict_types=1);

namespace app\admin\validation\User;

use app\admin\validation\FormRequest;

/**
 * 新增用户（`POST /admin/users`）
 *
 * 只做格式合法性：账号是否重复、角色是否互斥等业务规则仍在
 * {@see \app\common\service\UserService}，失败是 409/400 而非 422。
 * `role_ids` 不在这里校验——它走角色分配接口的同一套业务校验。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'username'  => ['required|string|min:2|max:64', '账号'],
            'real_name' => ['required|string|max:64',       '姓名'],
            'phone'     => ['phone',                        '手机号'],
            'email'     => ['email|max:128',                '邮箱'],
            'dept_id'   => ['integer|min:0',                '部门'],
            'post_id'   => ['integer|min:0',                '岗位'],
            'status'    => ['integer|in:0,1',               '状态'],
            'remark'    => ['string|max:255',               '备注'],
            'password'  => ['string|max:64',                '密码'],
        ];
    }
}
