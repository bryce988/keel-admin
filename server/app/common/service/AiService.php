<?php
/**
 * keel admin
 * AI 助手「小k」—— 会话、提问、停止、配额、审计
 *
 * 设计见 docs/ai-prd.md 与 docs/ai-tech.md。回答本身在 AI 消费进程里跑（`app\common\ai\AiRunner`），
 * 这里只做 HTTP 侧的事：校验、落提问、投递队列，都是毫秒级的。
 *
 * ## 小k 没有自己的身份
 *
 * 它不是 sys_users 里的账号，也没有角色。每一次查询都以提问人的身份执行，
 * 能看到的 ⊆ 提问人能看到的（ai-prd §4.1）。所以这个文件里没有任何「小k 能看什么」的判断——
 * 那些全在工具调用的现有 service 里，由数据权限与字段权限决定。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\service;

use app\common\ai\DeepSeekProvider;
use app\common\ai\LlmException;
use app\common\ai\RunGuard;
use app\common\constant\BizCode;
use app\common\exception\BusinessException;
use app\common\exception\ConflictException;
use app\common\exception\NotFoundException;
use app\common\exception\RateLimitException;
use app\common\model\AiRunModel;
use app\common\model\AiToolCallModel;
use app\common\model\ImConversationMemberModel;
use app\common\model\ImConversationModel;
use app\common\model\ImMessageModel;
use app\common\model\SysUserModel;
use app\common\support\Cache;
use app\common\support\Ctx;
use app\common\support\Db;
use Illuminate\Database\Eloquent\Builder;
use support\Log;
use Webman\RedisQueue\Redis;

class AiService
{
    /** 显示名。小k 的回答 sender_name 就是它 */
    public const NAME = '小k';

    /** AI 消费进程组监听的队列（`app/queue_ai/AiRunConsumer`） */
    public const QUEUE = 'keel:ai';

    /** 单条提问的长度上限 */
    public const MAX_QUESTION = 2000;

    /** 权限点 */
    public const PERM = 'ai:use';

    // ================================================================ 能不能用

    /** 功能总开关（参数 ai.enabled） */
    public static function enabled(): bool
    {
        return (bool) (int) (ParamService::value('ai.enabled', 0) ?? 0);
    }

    /**
     * 这个人能不能用小k
     *
     * 入口在消息页里，所以同时要有 chat:use（ai-prd §4.1）。
     * 路由上已经声明了 ai:use，这里给「消息列表要不要显示小k 那一行」用。
     */
    public static function canUse(array $user): bool
    {
        return self::enabled()
            && PermissionService::has($user, self::PERM)
            && PermissionService::has($user, 'chat:use');
    }

    /**
     * 消息列表里「小k」那一行（随未读汇总一起下发，与系统公告那一行同一个路子）
     *
     * 不创建会话：没聊过的人 conv_id 为 0，点进去时才建。
     */
    public static function chatEntry(int $userId): ?array
    {
        $user = Ctx::user();
        if ($user === null || (int) $user['id'] !== $userId || !self::canUse($user)) {
            return null;
        }

        /** @var ImConversationModel|null $conv */
        $conv = ImConversationModel::query()->where('peer_key', ImConversationModel::aiKey($userId))->first();
        if (!$conv) {
            return ['conv_id' => 0, 'unread' => 0, 'latest_text' => '', 'latest_at' => null, 'running' => false];
        }

        $member = ImConversationMemberModel::query()
            ->where('conv_id', $conv->id)->where('user_id', $userId)->first();

        return [
            'conv_id'     => (int) $conv->id,
            'unread'      => max(0, (int) $conv->max_seq - (int) ($member->last_read_seq ?? 0)),
            'latest_text' => (string) $conv->last_msg_text,
            'latest_at'   => $conv->last_msg_at?->format('Y-m-d H:i:s'),
            'running'     => self::activeRun($userId) !== null,
        ];
    }

    // ================================================================ 会话

    /**
     * 取我的小k 会话，没有就建（并写一条欢迎语）
     *
     * 并发安全靠 `uk_peer`（`ai:<uid>`），两个标签页同时首次打开不会建出两个。
     */
    public static function conversation(int $userId): array
    {
        self::assertUsable();

        $key  = ImConversationModel::aiKey($userId);
        $conv = ImConversationModel::query()->where('peer_key', $key)->first();

        if (!$conv) {
            try {
                $conv = Db::transaction(function () use ($key, $userId) {
                    $conv = ImConversationModel::create([
                        'type'         => ImConversationModel::TYPE_AI,
                        'peer_key'     => $key,
                        'member_count' => 1,
                        'status'       => ImConversationModel::STATUS_NORMAL,
                    ]);
                    ImConversationMemberModel::create([
                        'conv_id' => $conv->id,
                        'user_id' => $userId,
                        'role'    => ImConversationMemberModel::ROLE_MEMBER,
                    ]);

                    return $conv;
                });

                self::welcome((int) $conv->id);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!str_contains($e->getMessage(), '1062')) {
                    throw $e;
                }
                $conv = ImConversationModel::query()->where('peer_key', $key)->firstOrFail();
            }
        }

        $run = self::activeRun($userId);

        return ChatService::detail((int) $conv->id, $userId) + [
            'active_run_id' => $run?->id ?? 0,
            'suggestions'   => self::suggestions(Ctx::user() ?? []),
        ];
    }

    /**
     * 欢迎语 + 按提问人权限生成的示例问题
     *
     * 示例问题只给他查得动的：没有日志权限的人不该看到「最近谁登录失败了」，
     * 点下去得到一句「你没有权限」，第一印象就坏了。
     */
    private static function welcome(int $convId): void
    {
        ChatService::appendMessage($convId, 0, self::NAME, ImMessageModel::TYPE_AI,
            "你好，我是小k。我可以帮你查后台里的数据，也可以回答系统怎么用。\n\n"
            . "我只能看到**你自己有权限看到的数据**，也只能查询、不能替你修改。",
            ['kind' => 'welcome', 'links' => [], 'steps' => []],
        );
    }

    /** @return list<string> */
    public static function suggestions(array $user): array
    {
        $pool = [
            ['sys:user:list', '我能看到的在职员工有多少人？按部门分一下'],
            ['sys:user:list', '最近 30 天新建了哪些账号？'],
            ['sys:log:login:list', '最近一周有谁登录失败过？'],
            ['sys:log:operation:list', '今天有谁改过角色或权限？'],
            ['contact:view', '技术部有哪些同事？'],
            ['', '最新的公告说了什么？'],
            ['', '我是什么角色，能看到哪些数据？'],
            ['', '数据范围的五种选项有什么区别？'],
        ];

        $out = [];
        foreach ($pool as [$perm, $q]) {
            if (PermissionService::has($user, $perm)) {
                $out[] = $q;
            }
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    // ================================================================ 提问

    /**
     * 提问：落提问消息 + 建 run + 投递队列，返回 202
     *
     * 顺序是设计过的：
     * 1. 事务里锁住会话行，判「有没有进行中的问答」再建 run——两个标签页同时点发送，
     *    只有一个能进来（409 给另一个）
     * 2. 落提问消息（它自己的事务，提交后广播）
     * 3. 投递队列。投递失败时 run 标失败并返还配额，不留一个永远「排队中」的问答
     */
    public static function ask(int $userId, string $content, string $clientMsgId = ''): array
    {
        self::assertUsable();

        $content = trim($content);
        if ($content === '') {
            throw new BusinessException('请输入问题');
        }
        if (mb_strlen($content) > self::MAX_QUESTION) {
            throw new BusinessException('问题不能超过 ' . self::MAX_QUESTION . ' 字', BizCode::AI_QUESTION_TOO_LONG);
        }

        if (!DeepSeekProvider::configured()) {
            throw new BusinessException('AI 服务尚未配置，请联系管理员', BizCode::AI_NOT_CONFIGURED);
        }

        self::assertBudget();

        $convId = (int) self::conversation($userId)['id'];
        $user   = Ctx::user() ?? [];

        $run = Db::transaction(function () use ($convId, $userId, $user) {
            ImConversationModel::query()->lockForUpdate()->find($convId);

            if (self::activeRun($userId) !== null) {
                throw new ConflictException('上一个问题还没回答完', BizCode::AI_RUN_IN_PROGRESS);
            }

            return AiRunModel::create([
                'user_id'  => $userId,
                'dept_id'  => (int) ($user['dept_id'] ?? 0),
                'conv_id'  => $convId,
                'status'   => AiRunModel::STATUS_PENDING,
                'trace_id' => Ctx::traceId(),
            ]);
        });

        // 配额在建 run 之后再扣：409 的那一下不该吃掉一次配额
        try {
            self::takeQuota($userId);
        } catch (RateLimitException $e) {
            $run->delete();
            throw $e;
        }

        $message = ChatService::appendMessage(
            $convId, $userId, self::displayName($user), ImMessageModel::TYPE_TEXT, $content,
            ['run_id' => (int) $run->id], $clientMsgId,
        );

        $run->question_msg_id = (int) $message['id'];
        $run->save();

        try {
            Redis::send(self::QUEUE, ['run_id' => (int) $run->id]);
        } catch (\Throwable $e) {
            Log::error('[ai] 投递失败', ['run' => $run->id, 'error' => $e->getMessage()]);
            self::failRun($run, '服务暂时不可用，请稍后再试');
            self::refundQuota($userId, date('Ymd'));
        }

        return ['message' => $message, 'run_id' => (int) $run->id];
    }

    /**
     * 停止：写一个 Redis 标记，消费进程看到就中断连接
     *
     * 不是自己的 run 一律 404。已经结束的幂等返回——两个标签页同时点停止是正常操作。
     */
    public static function cancel(int $userId, int $runId): void
    {
        $run = AiRunModel::query()->where('id', $runId)->where('user_id', $userId)->first();
        if (!$run) {
            throw new NotFoundException();
        }
        if (!in_array($run->status, AiRunModel::ACTIVE, true)) {
            return;
        }

        Cache::set(RunGuard::cancelKey($runId), '1', 600);
    }

    /** 新对话：插一条分隔，之后的提问不再带之前的上下文 */
    public static function reset(int $userId): array
    {
        $convId = (int) self::conversation($userId)['id'];

        if (self::activeRun($userId) !== null) {
            throw new ConflictException('上一个问题还没回答完', BizCode::AI_RUN_IN_PROGRESS);
        }

        return ChatService::appendMessage($convId, 0, '', ImMessageModel::TYPE_SYSTEM, '新对话', ['kind' => 'ai_reset']);
    }

    /** 👍 / 👎。只能评价自己的、已经结束的 run */
    public static function feedback(int $userId, int $runId, int $rating, string $reason): void
    {
        $run = AiRunModel::query()->where('id', $runId)->where('user_id', $userId)->first();
        if (!$run || in_array($run->status, AiRunModel::ACTIVE, true)) {
            throw new NotFoundException();
        }

        $run->rating   = max(-1, min(1, $rating));
        $run->feedback = $run->rating === -1 ? mb_substr(trim($reason), 0, 500) : '';
        $run->save();
    }

    // ================================================================ 给 AiRunner 用

    /** 提问原文 */
    public static function questionText(AiRunModel $run): string
    {
        return (string) ImMessageModel::query()->whereKey($run->question_msg_id)->value('content');
    }

    /**
     * 最近一次「新对话」之后、本次提问之前的问答，最多 Prompt::HISTORY_ROUNDS 轮
     *
     * 只取成功结束的回答（失败的错误提示不是上下文）。
     *
     * @return list<array{q: string, a: string}>
     */
    public static function history(AiRunModel $run): array
    {
        $question = ImMessageModel::query()->find($run->question_msg_id);
        if (!$question) {
            return [];
        }

        $member = ImConversationMemberModel::query()
            ->where('conv_id', $run->conv_id)->where('user_id', $run->user_id)->first();

        $msgs = ImMessageModel::query()
            ->where('conv_id', $run->conv_id)
            ->where('seq', '>', (int) ($member->min_seq ?? 0))
            ->where('seq', '<', (int) $question->seq)
            ->where('status', ImMessageModel::STATUS_NORMAL)
            ->orderByDesc('seq')
            ->limit(\app\common\ai\Prompt::HISTORY_ROUNDS * 3)
            ->get()
            ->reverse()
            ->values();

        $rounds  = [];
        $pending = null;
        foreach ($msgs as $m) {
            $kind = $m->extra['kind'] ?? null;
            if ($m->type === ImMessageModel::TYPE_SYSTEM && $kind === 'ai_reset') {
                $rounds  = [];
                $pending = null;
                continue;
            }
            if ($m->type === ImMessageModel::TYPE_TEXT && (int) $m->sender_id === $run->user_id) {
                $pending = (string) $m->content;
                continue;
            }
            if ($m->type === ImMessageModel::TYPE_AI && $pending !== null && ($m->extra['status'] ?? '') === 'done') {
                $answer   = trim((string) preg_replace('/\[\[link:\d+\]\]/', '', (string) $m->content));
                $rounds[] = ['q' => $pending, 'a' => $answer];
                $pending  = null;
            }
        }

        return array_slice($rounds, -\app\common\ai\Prompt::HISTORY_ROUNDS);
    }

    /**
     * 估算费用（美元）与是否高峰
     *
     * 单价在 config/ai.php。高峰：UTC 周一至周五 01:00–04:00、06:00–10:00。
     *
     * @param array{hit: int, miss: int, output: int, reasoning: int} $usage
     * @return array{0: string, 1: bool}
     */
    public static function cost(string $model, array $usage, int $at): array
    {
        $prices = (array) config('ai.prices', []);
        $p      = $prices[$model] ?? ($prices['deepseek-flash'] ?? null);
        if (!$p) {
            return ['0', false];
        }

        $dow  = (int) gmdate('N', $at);
        $hour = (int) gmdate('G', $at);
        $peak = $dow <= 5 && (($hour >= 1 && $hour < 4) || ($hour >= 6 && $hour < 10));
        $i    = $peak ? 1 : 0;

        $usd = ($usage['hit'] * $p['hit'][$i] + $usage['miss'] * $p['miss'][$i] + $usage['output'] * $p['out'][$i]) / 1_000_000;

        return [number_format($usd, 6, '.', ''), $peak];
    }

    /**
     * 密钥错、余额不足时提醒超管（同一小时只发一次）
     *
     * 用系统公告？不——公告是全员可见的。这里只记 error 日志 + 设一个标记，
     * 审计页顶部读这个标记显示横幅。真要推送等有了通知中心再说。
     */
    public static function alertAdmins(string $message): void
    {
        if (!Cache::setNx('ai:alert', 3600, $message)) {
            return;
        }
        Log::error('[ai] 需要管理员处理：' . $message);
        Cache::set('ai:alert:last', json_encode(['message' => $message, 'at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE), 86400);
    }

    /**
     * 故障解除：测试连接通过、或有问答正常完成时调用
     *
     * 不清的话管理员修好了密钥，审计页的红色横幅还要挂满 24 小时，
     * 看的人会以为还没修好。
     */
    public static function clearAlert(): void
    {
        Cache::del('ai:alert');
        Cache::del('ai:alert:last');
    }

    /** 最近一次需要管理员处理的故障（审计页横幅），没有为 null */
    public static function lastAlert(): ?array
    {
        $raw = Cache::get('ai:alert:last');

        return $raw ? (json_decode($raw, true) ?: null) : null;
    }

    // ================================================================ 配额与预算

    private static function quotaKey(int $userId, string $ymd): string
    {
        return "ai:quota:{$userId}:{$ymd}";
    }

    /** 每人每天提问数（参数 ai.quota.daily，0 = 不限） */
    private static function takeQuota(int $userId): void
    {
        $limit = (int) (ParamService::value('ai.quota.daily', 50) ?? 50);
        if ($limit <= 0) {
            return;
        }

        $key   = self::quotaKey($userId, date('Ymd'));
        $count = Cache::incr($key, 90000);

        if ($count > $limit) {
            Cache::conn()->decr($key);
            $retry = strtotime('tomorrow') - time();

            throw new RateLimitException("今天的 {$limit} 次提问已经用完，明天 0 点重置", max(1, $retry), BizCode::AI_QUOTA_EXCEEDED);
        }
    }

    /** 服务商侧的故障不该吃掉用户的配额 */
    public static function refundQuota(int $userId, string $ymd): void
    {
        try {
            $key = self::quotaKey($userId, $ymd);
            if ((int) Cache::get($key) > 0) {
                Cache::conn()->decr($key);
            }
        } catch (\Throwable) {
            // 返还失败不影响主流程
        }
    }

    /** 全公司月预算（参数 ai.budget.monthly，美元，0 = 不限） */
    private static function assertBudget(): void
    {
        $budget = (float) (ParamService::value('ai.budget.monthly', 0) ?? 0);
        if ($budget <= 0) {
            return;
        }

        $spent = (float) AiRunModel::query()
            ->where('created_at', '>=', date('Y-m-01 00:00:00'))
            ->sum('cost_usd');

        if ($spent >= $budget) {
            throw new BusinessException('本月 AI 用量已达上限，请联系管理员', BizCode::AI_BUDGET_EXCEEDED);
        }
    }

    // ================================================================ 进行中的 run

    /**
     * 这个人正在进行的问答
     *
     * **顺带清理卡死的 run**：消费进程在回答途中被 reload/restart 杀掉时，run 会永远停在 running，
     * 用户那边永远「思考中」、也永远发不了下一个问题。超过「超时 + 60 秒」还没结束的，
     * 就地判失败并补一条失败消息。放在这里而不是定时任务里：只有这个人来问的时候才需要知道，
     * 每分钟扫一次全表、再写一条任务日志，是为一个罕见情况付常驻成本。
     */
    public static function activeRun(int $userId): ?AiRunModel
    {
        /** @var AiRunModel|null $run */
        $run = AiRunModel::query()
            ->where('user_id', $userId)
            ->whereIn('status', AiRunModel::ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (!$run) {
            return null;
        }

        $timeout = max(10, (int) (ParamService::value('ai.run.timeout', 90) ?? 90));
        // 排队中的按创建时间算，多给一倍：队列里排着别人的问答是正常的
        $since = $run->status === AiRunModel::STATUS_RUNNING
            ? ($run->started_at?->getTimestamp() ?? time())
            : ($run->created_at?->getTimestamp() ?? time()) - $timeout;

        if (time() - $since > $timeout + 60) {
            self::failRun($run, '回答中断了，请重新提问');

            return null;
        }

        return $run;
    }

    /** 把一个没能正常结束的 run 判失败，并补一条消息（前端靠它结束「思考中」） */
    private static function failRun(AiRunModel $run, string $message): void
    {
        $affected = AiRunModel::query()
            ->whereKey($run->id)
            ->whereIn('status', AiRunModel::ACTIVE)
            ->update([
                'status'      => AiRunModel::STATUS_FAILED,
                'error_msg'   => $message,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

        // 并发时只让一个人补消息
        if ($affected === 0) {
            return;
        }

        try {
            $msg = ChatService::appendMessage($run->conv_id, 0, self::NAME, ImMessageModel::TYPE_AI, $message, [
                'run_id' => $run->id, 'status' => 'failed', 'steps' => [], 'links' => [],
            ]);
            AiRunModel::query()->whereKey($run->id)->update(['answer_msg_id' => (int) $msg['id']]);
        } catch (\Throwable $e) {
            Log::error('[ai] 补写失败消息出错', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    // ================================================================ 审计（ai:log:list）

    public static function runQuery(array $filters): Builder
    {
        $q = AiRunModel::query();

        if (!empty($filters['keyword'])) {
            $kw  = trim((string) $filters['keyword']);
            // 用户表带数据权限，这里要的是「按账号/姓名找人」而不是「我能看到的人」——
            // 审计员本来就要看全公司，而这个页面的权限点只给审计角色
            $ids = SysUserModel::withoutDataScope()
                ->where(fn ($w) => $w->where('username', 'like', "%{$kw}%")->orWhere('real_name', 'like', "%{$kw}%"))
                ->limit(200)->pluck('id')->all();
            $q->whereIn('user_id', $ids ?: [0]);
        }
        if (($filters['status'] ?? '') !== '') {
            $q->where('status', (int) $filters['status']);
        }
        if (($filters['rating'] ?? '') !== '') {
            $q->where('rating', (int) $filters['rating']);
        }
        // 与日志页同一套时间参数：只给到日期时补齐成当天的首尾
        if (!empty($filters['start_time'])) {
            $start = (string) $filters['start_time'];
            $q->where('created_at', '>=', strlen($start) === 10 ? "{$start} 00:00:00" : $start);
        }
        if (!empty($filters['end_time'])) {
            $end = (string) $filters['end_time'];
            $q->where('created_at', '<=', strlen($end) === 10 ? "{$end} 23:59:59" : $end);
        }

        return $q;
    }

    /** 列表行。批量取提问人姓名，不在每行里查 */
    public static function runMapPage(array $rows): array
    {
        $ids   = array_values(array_unique(array_map(static fn ($r) => (int) $r->user_id, $rows)));
        $users = SysUserModel::withoutDataScope()->whereIn('id', $ids ?: [0])->get(['id', 'username', 'real_name'])->keyBy('id');

        return array_map(static function (AiRunModel $r) use ($users) {
            $u = $users[$r->user_id] ?? null;

            return [
                'id'                => $r->id,
                'user_id'           => $r->user_id,
                'user_name'         => $u ? ($u->real_name ?: $u->username) : '已删除用户',
                'username'          => $u?->username ?? '',
                'status'            => $r->status,
                'model'             => $r->model,
                'reasoning_effort'  => $r->reasoning_effort,
                'steps'             => $r->steps,
                'rounds'            => $r->rounds,
                'cache_hit_tokens'  => $r->cache_hit_tokens,
                'cache_miss_tokens' => $r->cache_miss_tokens,
                'output_tokens'     => $r->output_tokens,
                'reasoning_tokens'  => $r->reasoning_tokens,
                'cost_usd'          => (string) $r->cost_usd,
                'first_token_ms'    => $r->first_token_ms,
                'duration_ms'       => $r->duration_ms,
                'http_status'       => $r->http_status,
                'error_msg'         => $r->error_msg,
                'rating'            => $r->rating,
                'feedback'          => $r->feedback,
                'trace_id'          => $r->trace_id,
                'created_at'        => $r->created_at?->format('Y-m-d H:i:s'),
                'finished_at'       => $r->finished_at?->format('Y-m-d H:i:s'),
            ];
        }, $rows);
    }

    /** 用量汇总（审计页顶部）：本月与今天的次数、费用，以及最近一次需要管理员处理的故障 */
    public static function usageSummary(): array
    {
        $sum = static function (string $from) {
            $row = AiRunModel::query()->where('created_at', '>=', $from)
                ->selectRaw('count(*) as runs, coalesce(sum(cost_usd),0) as cost, sum(status = 4) as failed, '
                    . 'coalesce(sum(cache_hit_tokens),0) as hit, coalesce(sum(cache_miss_tokens),0) as miss')
                ->first();

            $hit  = (int) ($row->hit ?? 0);
            $miss = (int) ($row->miss ?? 0);

            return [
                'runs'           => (int) ($row->runs ?? 0),
                'failed'         => (int) ($row->failed ?? 0),
                'cost_usd'       => number_format((float) ($row->cost ?? 0), 4, '.', ''),
                'cache_hit_rate' => $hit + $miss > 0 ? round($hit / ($hit + $miss) * 100, 1) : null,
            ];
        };

        return [
            'today'  => $sum(date('Y-m-d 00:00:00')),
            'month'  => $sum(date('Y-m-01 00:00:00')),
            'budget' => (float) (ParamService::value('ai.budget.monthly', 0) ?? 0),
            'alert'  => self::lastAlert(),
        ];
    }

    /** 审计详情：run 元数据 + 工具调用明细。**不含对话内容**（ai-prd §8.4） */
    public static function runDetail(int $id): array
    {
        /** @var AiRunModel|null $run */
        $run = AiRunModel::query()->find($id);
        if (!$run) {
            throw new NotFoundException();
        }

        $row = self::runMapPage([$run])[0];

        $row['tool_calls'] = AiToolCallModel::query()->where('run_id', $id)->orderBy('id')->get()
            ->map(static fn (AiToolCallModel $c) => [
                'id'             => $c->id,
                'tool'           => $c->tool,
                'label'          => $c->label,
                'acting_user_id' => $c->acting_user_id,
                'args'           => $c->args,
                'result_rows'    => $c->result_rows,
                'result_total'   => $c->result_total,
                'denied'         => (bool) $c->denied,
                'error_msg'      => $c->error_msg,
                'duration_ms'    => $c->duration_ms,
                'created_at'     => $c->created_at?->format('Y-m-d H:i:s'),
            ])->all();

        return $row;
    }

    /**
     * 「测试连接」：用**已保存**的配置查一次余额
     *
     * 不接受前端传密钥，否则这个接口就成了「拿任意密钥去试」的跳板。
     */
    public static function providerTest(): array
    {
        try {
            $b = (new DeepSeekProvider())->balance();
            if ($b['is_available']) {
                self::clearAlert();
            }

            return ['ok' => true] + $b + ['error' => ''];
        } catch (LlmException $e) {
            return ['ok' => false, 'is_available' => false, 'balances' => [], 'error' => $e->getMessage()];
        }
    }

    // ================================================================ 内部

    private static function assertUsable(): void
    {
        if (!self::enabled()) {
            throw new BusinessException('AI 助手未启用', BizCode::AI_DISABLED);
        }
    }

    private static function displayName(array $user): string
    {
        return (string) (($user['real_name'] ?? '') ?: ($user['username'] ?? ''));
    }
}
