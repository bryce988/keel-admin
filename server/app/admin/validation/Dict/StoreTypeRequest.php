<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

use app\common\validation\FormRequest;

/**
 * 新增字典类型（`POST /admin/dicts`）
 *
 * 与编辑共用一份规则，见 {@see UpdateTypeRequest}。
 * 「已有字典项时不许改编码」是编辑独有的业务约束，在
 * {@see \app\admin\service\DictService} 里判，失败是 409。
 */
class StoreTypeRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name'   => ['required|string|max:64', '字典名称'],
            'code'   => ['required|code|max:64',   '字典编码'],   // 字典项的外键
            'status' => ['integer|in:0,1',         '状态'],
            'remark' => ['string|max:255',         '备注'],
        ];
    }
}
