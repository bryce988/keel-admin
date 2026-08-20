<?php

declare(strict_types=1);

namespace app\admin\validation\Log;

use app\admin\validation\FormRequest;

/**
 * 操作日志的查询条件（`GET /admin/logs/operation` 与 `/export` 共用）
 *
 * 共用一份是必须的：分开写迟早出现「界面上筛出 20 条，导出来 2000 条」。
 *
 * **不传时间范围会兜底成最近 7 天**（在 {@see \app\common\service\LogService} 里做，
 * 不在这里）。前端总是会带范围，但接口不能指望调用方——
 * 日志表是全系统最大的表，无界查询能把库拖垮。
 */
final class OperationListRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'keyword'    => ['string|max:64',  '关键词'],     // 操作人 / 描述 / 对象模糊匹配
            'module'     => ['string|max:64',  '模块'],
            'action'     => ['in:1,2,3,4,5,6', '操作类型'],   // 1 新增 2 修改 3 删除 4 导出 5 授权 6 其他
            'status'     => ['in:0,1',         '执行结果'],   // 0 失败 1 成功
            'trace_id'   => ['string|max:64',  'TraceID'],    // 链路 ID，排障时最有用
            'start_time' => ['string|max:19',  '开始时间'],   // Y-m-d H:i:s
            'end_time'   => ['string|max:19',  '结束时间'],
        ];
    }
}
