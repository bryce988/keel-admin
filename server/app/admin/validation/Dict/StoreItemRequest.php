<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

use app\common\validation\FormRequest;

/**
 * 新增字典项（`POST /admin/dict-items`）
 *
 * 与编辑共用一份规则，见 {@see UpdateItemRequest}。
 */
class StoreItemRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'type_code' => ['required|code|max:64',   '所属字典'],
            'label'     => ['required|string|max:64', '显示文案'],
            'value'     => ['required|string|max:64', '存储值'],       // 业务表里存的就是它
            // 空串合法：没有 tag_type 的字典项渲染成默认灰标签。
            // 提交空串正是「清空标签色」的方式，Validator 会把它原样带出去
            'tag_type'  => ['string|in:,success,warning,danger,primary,info', '标签颜色'],
            'sort'      => ['integer|min:0|max:9999', '排序'],
            'status'    => ['integer|in:0,1',         '状态'],
            'remark'    => ['string|max:255',         '备注'],
        ];
    }
}
