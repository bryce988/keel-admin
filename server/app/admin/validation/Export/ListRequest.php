<?php

declare(strict_types=1);

namespace app\admin\validation\Export;

use app\common\validation\FormRequest;

/** 导出任务列表的筛选条件（`GET /admin/exports`） */
final class ListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'biz'    => ['string|max:32', '业务类型'],   // 见 config/export.php
            'status' => ['in:0,1,2,3',    '状态'],       // 0排队 1处理中 2已完成 3失败
        ];
    }
}
