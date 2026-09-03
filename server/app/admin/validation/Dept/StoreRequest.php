<?php

declare(strict_types=1);

namespace app\admin\validation\Dept;

use app\admin\validation\FormRequest;

/**
 * 新增部门（`POST /admin/depts`）
 *
 * 新增与编辑共用一份规则（见 {@see UpdateRequest}），这是刻意的：
 * 分开写迟早出现「新建时校验了、编辑时没校验」这种不对称。
 *
 * 只做格式合法性。编码唯一性、上级部门是否存在、成环检测都在
 * {@see \app\admin\service\DeptService}，失败是 409/400 而非 422。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'parent_id' => ['integer|min:0',          '上级部门'],   // 0 为顶级
            'name'      => ['required|string|max:64', '部门名称'],   // ≤64
            'leader_id' => ['integer|min:0',          '负责人'],     // 用户 ID
            'sort'      => ['integer|min:0|max:9999', '排序'],       // 升序
            'status'    => ['integer|in:0,1',         '状态'],
        ];
    }
}
