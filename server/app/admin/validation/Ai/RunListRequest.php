<?php

declare(strict_types=1);

namespace app\admin\validation\Ai;

use app\common\validation\FormRequest;

/**
 * AI 调用记录的查询条件（`GET /admin/ai/runs`）
 */
final class RunListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword'    => ['string|max:64', '提问人'],     // 账号 / 姓名
            'status'     => ['in:0,1,2,3,4',  '状态'],
            'rating'     => ['in:-1,0,1',     '反馈'],
            'start_time' => ['string|max:19', '开始时间'],
            'end_time'   => ['string|max:19', '结束时间'],
        ];
    }
}
