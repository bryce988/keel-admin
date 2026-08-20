<?php

declare(strict_types=1);

namespace app\admin\validation\User;

/**
 * 编辑用户（`PUT /admin/users/{id}`）
 *
 * 目前与新增的字段规则完全一致，直接复用；将来若「编辑不能改账号」之类
 * 需要分叉，就在这里覆写 `rules()`。`role_ids` 三态（不传=不动、空数组=清空）
 * 不参与校验，由控制器按 `$request->input('role_ids')` 是否为 null 区分。
 */
final class UpdateRequest extends StoreRequest
{
}
