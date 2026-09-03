<?php

declare(strict_types=1);

namespace app\staff\validation\Auth;

use app\common\validation\FormRequest;

/**
 * 员工移动端登录（`POST /staff/v1/auth/login`）
 *
 * 与后台同一套账号与验证码，所以规则也一样。单独一个类而不是引后台那个：
 * 端与端之间不互相引用（PROJECT.md §8.2），一行 use 省下的代码，
 * 换来的是两端从此在同一个文件里纠缠。
 */
class LoginRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'username'     => ['required|string|max:64', '账号'],
            'password'     => ['required|string|max:64', '密码'],
            'captcha_key'  => ['required|string|max:64', '验证码标识'],
            'captcha_code' => ['required|string|max:8',  '验证码'],
        ];
    }
}
