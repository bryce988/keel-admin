<?php

declare(strict_types=1);

namespace app\admin\validation\Post;

use app\admin\validation\FormRequest;

/**
 * 新增岗位（`POST /admin/posts`）
 *
 * 与编辑共用一份规则，见 {@see UpdateRequest}。
 *
 * 没有 `code`：岗位编码由 {@see \app\admin\service\PostService::makeCode()}
 * 按主键生成。`validated()` 只返回这里声明过的键，所以请求体里带上 `code` 也进不去，
 * 不需要另外再挡一道。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name'            => ['required|string|max:64', '岗位名称'],
            'dept_id'         => ['integer|min:0',          '所属部门'],
            'default_role_id' => ['integer|min:0',          '默认角色'],   // 新人入职按岗位带角色
            'sort'            => ['integer|min:0|max:9999', '排序'],
            'status'          => ['integer|in:0,1',         '状态'],
            'remark'          => ['string|max:255',         '备注'],
        ];
    }
}
