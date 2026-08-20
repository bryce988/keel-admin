<?php

declare(strict_types=1);

namespace app\admin\validation\Dept;

/**
 * 编辑部门（`PUT /admin/depts/{id}`）
 *
 * 字段与新增完全一致，直接复用；将来若要分叉（比如「有下级时不许改编码」
 * 这类只在编辑时成立的约束）就在这里覆写 `rules()`。
 */
final class UpdateRequest extends StoreRequest
{
}
