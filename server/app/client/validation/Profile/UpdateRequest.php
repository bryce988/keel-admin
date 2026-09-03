<?php

declare(strict_types=1);

namespace app\client\validation\Profile;

use app\common\validation\FormRequest;

/**
 * 修改 C 端资料（`PUT /client/v1/profile`）
 *
 * 只有昵称：手机号是登录账号，改它等于换账号，要走单独的换绑流程；
 * 头像走 {@see \app\client\controller\v1\ProfileController::avatar()}，
 * 上传即写库，不需要再提交一次表单。
 */
class UpdateRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'nickname' => ['required|string|min:1|max:64', '昵称'],
        ];
    }
}
