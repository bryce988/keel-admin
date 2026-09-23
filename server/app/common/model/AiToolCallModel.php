<?php
/**
 * keel admin
 * AI 工具调用审计 —— ai_tool_calls
 *
 * 小k 每查一次数据记一行：以谁的身份、调了哪个工具、什么参数、拿回几行、是否因权限被拒。
 * 不存结果内容，只存行数。
 *
 * `acting_user_id` 取自执行那一刻的 `Ctx::userId()`，**不抄** `ai_runs.user_id`：
 * 两者不一致就是消费进程里身份串号了，专项用例断言的正是这一列（docs/ai-tech.md §4.3）。
 *
 * @property int         $id
 * @property int         $run_id
 * @property int         $acting_user_id
 * @property string      $tool
 * @property string      $label
 * @property array|null  $args
 * @property int         $result_rows
 * @property int         $result_total
 * @property int         $denied
 * @property string      $error_msg
 * @property int         $duration_ms
 * @property Carbon      $created_at
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

class AiToolCallModel extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'ai_tool_calls';

    protected $casts = [
        'run_id'         => 'integer',
        'acting_user_id' => 'integer',
        'args'           => 'array',
        'result_rows'    => 'integer',
        'result_total'   => 'integer',
        'denied'         => 'integer',
        'duration_ms'    => 'integer',
    ];

    public function auditColumns(): array
    {
        return [];
    }
}
