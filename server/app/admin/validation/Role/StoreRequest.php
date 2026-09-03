<?php

declare(strict_types=1);

namespace app\admin\validation\Role;

use app\common\validation\FormRequest;

/**
 * 新增角色（`POST /admin/roles`）
 *
 * `data_scope` 只校验取值范围，真正的部门清单走
 * {@see DataScopeRequest}（`PUT /admin/roles/{id}/data-scope`）——
 * 两件事分开是因为范围 5「自定义」才需要部门清单，塞在一起会让另外四种范围
 * 多带一个永远为空的字段。
 *
 * 与编辑共用一份规则，见 {@see UpdateRequest}。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name'       => ['required|string|max:64', '角色名称'],
            'parent_id'  => ['integer|min:0',          '继承自'],       // 继承哪个角色的权限，0 为不继承（RBAC1）
            'data_scope' => ['integer|in:1,2,3,4,5',   '数据范围'],
            'sort'       => ['integer|min:0|max:9999', '排序'],
            'status'     => ['integer|in:0,1',         '状态'],
            'remark'     => ['string|max:255',         '备注'],
        ];
    }
}
