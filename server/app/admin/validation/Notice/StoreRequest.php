<?php

declare(strict_types=1);

namespace app\admin\validation\Notice;

use app\common\validation\FormRequest;

/**
 * 新增公告（`POST /admin/notices`）
 *
 * `published_at` / `publisher_id` / `publisher_name` 不在规则里，所以请求体里
 * 带上也进不了 `validated()`：发布时间与发布人由服务端在状态跨过发布线时盖章
 * （{@see \app\common\service\NoticeService::stampPublish()}），
 * 允许前端传等于允许伪造「谁在什么时候发的」，而这正是公告唯一要审计的两件事。
 */
class StoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'title'   => ['required|string|max:128',   '标题'],
            'content' => ['required|string|max:20000', '正文'],
            'type'    => ['string|max:32',             '类型'],
            'status'  => ['integer|in:0,1',            '状态'],
        ];
    }
}
