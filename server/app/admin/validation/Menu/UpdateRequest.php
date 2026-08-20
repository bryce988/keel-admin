<?php

declare(strict_types=1);

namespace app\admin\validation\Menu;

/** 编辑菜单（`PUT /admin/menus/{id}`）；字段与新增一致，需要分叉时在这里覆写 `rules()` */
final class UpdateRequest extends StoreRequest
{
}
