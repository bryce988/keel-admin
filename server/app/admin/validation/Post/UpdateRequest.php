<?php

declare(strict_types=1);

namespace app\admin\validation\Post;

/** 编辑岗位（`PUT /admin/posts/{id}`）；字段与新增一致，需要分叉时在这里覆写 `rules()` */
final class UpdateRequest extends StoreRequest
{
}
