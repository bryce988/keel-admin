<?php

declare(strict_types=1);

namespace app\admin\validation\Notice;

/**
 * 编辑公告（`PUT /admin/notices/{id}`）
 *
 * 与新增同一份规则。发布 / 撤回也走这里（改 `status`），
 * 但界面上的「发布」按钮打的是 `/publish` 那个专用接口——
 * 它在操作日志里是一条独立记录，而不是混在一堆「编辑公告」里。
 */
class UpdateRequest extends StoreRequest
{
}
