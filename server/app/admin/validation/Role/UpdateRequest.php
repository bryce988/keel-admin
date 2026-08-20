<?php

declare(strict_types=1);

namespace app\admin\validation\Role;

/** 编辑角色（`PUT /admin/roles/{id}`）；字段与新增一致，需要分叉时在这里覆写 `rules()` */
final class UpdateRequest extends StoreRequest
{
}
