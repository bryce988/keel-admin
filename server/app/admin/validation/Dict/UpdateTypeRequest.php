<?php

declare(strict_types=1);

namespace app\admin\validation\Dict;

/** 编辑字典类型（`PUT /admin/dicts/{id}`）；字段与新增一致，需要分叉时在这里覆写 `rules()` */
final class UpdateTypeRequest extends StoreTypeRequest
{
}
