<?php
/**
 * keel admin
 * AI 问答运行记录 —— ai_runs
 *
 * 一次提问一行：排队 → 运行 → 完成 / 停止 / 失败。只存元数据（token、费用、耗时、状态），
 * **不存提问、回答、思考内容与查询结果**——前两者在 im_messages 里，后两者会复述业务数据，
 * 审计表里再存一份就多一处要保护的敏感数据（docs/ai-tech.md §8.1）。
 *
 * **不挂 `HasDataScope`**：审计页只给 `ai:log:list`，审计员要看全公司；
 * 提问人自己的 run 由 AiService 按 user_id 显式过滤。
 *
 * @property int         $id
 * @property int         $user_id
 * @property int         $dept_id
 * @property int         $conv_id
 * @property int         $question_msg_id
 * @property int         $answer_msg_id
 * @property int         $status            见 STATUS_*
 * @property string      $provider
 * @property string      $model
 * @property string      $reasoning_effort
 * @property int         $steps
 * @property int         $rounds
 * @property int         $cache_hit_tokens
 * @property int         $cache_miss_tokens
 * @property int         $output_tokens
 * @property int         $reasoning_tokens
 * @property int         $is_peak
 * @property string      $cost_usd
 * @property int         $first_token_ms
 * @property int         $duration_ms
 * @property int         $http_status
 * @property string      $error_msg
 * @property int         $rating
 * @property string      $feedback
 * @property string      $trace_id
 * @property Carbon      $created_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\model;

use Illuminate\Support\Carbon;

class AiRunModel extends BaseModel
{
    public const STATUS_PENDING = 0;
    public const STATUS_RUNNING = 1;
    public const STATUS_DONE    = 2;
    public const STATUS_STOPPED = 3;
    public const STATUS_FAILED  = 4;

    /** 还没结束的两种状态：「一个人同时只能有一个进行中的问答」按它判 */
    public const ACTIVE = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public const UPDATED_AT = null;

    protected $table = 'ai_runs';

    protected $casts = [
        'user_id'           => 'integer',
        'dept_id'           => 'integer',
        'conv_id'           => 'integer',
        'question_msg_id'   => 'integer',
        'answer_msg_id'     => 'integer',
        'status'            => 'integer',
        'steps'             => 'integer',
        'rounds'            => 'integer',
        'cache_hit_tokens'  => 'integer',
        'cache_miss_tokens' => 'integer',
        'output_tokens'     => 'integer',
        'reasoning_tokens'  => 'integer',
        'is_peak'           => 'integer',
        'first_token_ms'    => 'integer',
        'duration_ms'       => 'integer',
        'http_status'       => 'integer',
        'rating'            => 'integer',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];

    /** 没有审计列：提问人就是 user_id，没有「修改人」这回事 */
    public function auditColumns(): array
    {
        return [];
    }
}
