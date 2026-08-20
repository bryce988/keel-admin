<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

/** 编辑字典项（`PUT /admin/dict-items/{id}`）；字段与新增一致，需要分叉时在这里覆写 `rules()` */
final class UpdateItemRequest extends StoreItemRequest
{
}
