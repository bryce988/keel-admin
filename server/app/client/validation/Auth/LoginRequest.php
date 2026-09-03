<?php

declare(strict_types=1);

namespace app\client\validation\Auth;

use app\common\validation\FormRequest;

/**
 * C 端登录（`POST /client/v1/auth/login`）
 *
 * 没有图形验证码：App 上那一格几乎必然拍在小屏幕上让人看不清，
 * 防爆破在 {@see \app\client\service\AuthService} 里靠「凭证 + IP」的失败计数做。
 *
 * 手机号只校验长度与数字，不校验号段：脚手架不该内置某国某运营商的号段表，
 * 那是业务规则，会过期。
 */
class LoginRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'phone'    => ['required|string|min:6|max:20', '手机号'],
            'password' => ['required|string|min:6|max:64', '密码'],
        ];
    }
}
