<?php

declare(strict_types=1);

namespace app\admin\validation\Queue;

use app\common\validation\FormRequest;

/** 定时任务执行记录的筛选条件（`GET /admin/queues/tasks/logs`） */
final class TaskLogListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            // 任务标识，见 TaskProcess::TASKS 的 name（如 log-cleanup）
            'task_name' => ['string|max:64', '任务'],
            'status'    => ['in:0,1,2',     '执行状态'],   // 0排队中 1成功 2失败
        ];
    }
}
