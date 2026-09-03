<?php

declare(strict_types=1);

namespace app\admin\validation\Param;

use app\common\validation\FormRequest;
use app\common\service\ParamService;

/**
 * 新增系统参数（`POST /admin/params`）
 *
 * 与编辑的差别只有「哪几个字段必填」，所以规则表写一份，
 * 用 {@see self::isCreating()} 这个钩子区分——{@see UpdateRequest} 覆写它即可。
 * 原来是 `validate(Request $request, bool $creating)` 的那个布尔参数。
 */
class StoreRequest extends FormRequest
{
    /** 新增时 `group` / `name` / `param_key` 必填；编辑是部分更新 */
    protected function isCreating(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        $required = $this->isCreating() ? 'required|' : '';

        return [
            'group'       => [$required . 'string|in:' . implode(',', array_keys(ParamService::GROUPS)), '分组'],
            'name'        => [$required . 'string|max:64', '参数名称'],
            'param_key'   => [$required . 'code|max:128',  '参数键'],
            // 参数值不限内容也不限长度：json 类型的参数整段存在这里
            'param_value' => ['string',                    '参数值'],
            'value_type'  => ['string|in:string,int,bool,json', '值类型'],
            'is_secret'   => ['boolean',                   '密钥'],   // 只写不读，出参是固定掩码
            'remark'      => ['string|max:255',            '备注'],
        ];
    }
}
