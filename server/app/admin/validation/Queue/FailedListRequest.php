<?php

declare(strict_types=1);

namespace app\admin\validation\Queue;

use app\common\validation\FormRequest;

/** 失败任务列表的筛选条件（`GET /admin/queues/failed`） */
final class FailedListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            // 队列名，见 app/queue/ 下各消费者的 $queue（如 keel:log-cleanup）
            'queue' => ['string|max:64', '队列名'],
        ];
    }
}
