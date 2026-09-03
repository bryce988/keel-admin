<?php

declare(strict_types=1);

namespace app\admin\validation\Log;

use app\common\validation\FormRequest;

/**
 * 登录日志的查询条件（`GET /admin/logs/login` 与 `/export` 共用）
 *
 * 列表与导出共用、不传时间范围兜底成最近 7 天，理由都同 {@see OperationListRequest}。
 */
final class LoginListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword'    => ['string|max:64', '关键词'],     // 账号 / IP / 归属地模糊匹配
            'type'       => ['in:1,2',        '类型'],       // 1 登录 2 登出
            'status'     => ['in:0,1',        '执行结果'],   // 0 失败 1 成功
            'start_time' => ['string|max:19', '开始时间'],   // Y-m-d H:i:s
            'end_time'   => ['string|max:19', '结束时间'],
        ];
    }
}
