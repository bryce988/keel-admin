<?php
/**
 * keel admin
 * 跑一次问答：模型 ⇄ 工具的循环
 *
 * 在 AI 消费进程里执行（`app/queue_ai/AiRunConsumer`），不在 HTTP worker 里——
 * 一次问答 5~90 秒，放在 HTTP 里等于每个提问的人占住一个 worker（docs/ai-tech.md §1）。
 *
 * 整个循环包在 `AuthService::actAs(提问人)` 里：工具查到的数据、做的脱敏，
 * 都与提问人自己打开对应页面时一致。**这是本模块的安全核心**。
 *
 * 生命周期：
 *
 *     pending ──▶ running ──▶ done / stopped / failed
 *                    │
 *                    ├─ ai.run.started / ai.thinking / ai.delta / ai.step  （只推给提问人）
 *                    └─ 结束时落一条 type=ai 的消息 → message.new
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

use app\common\model\AiRunModel;
use app\common\model\ImMessageModel;
use app\common\service\AiService;
use app\common\service\AuthService;
use app\common\service\ChatService;
use app\common\service\ParamService;
use app\common\support\Cache;
use app\common\support\ChatFanout;
use app\common\support\Ctx;
use support\Log;

final class AiRunner
{
    /** 增量文本攒多久推一次。模型每秒几十个 token，逐个发布会让 Redis 与网关白白多几十倍消息 */
    private const DELTA_INTERVAL = 0.1;

    private AiRunModel $run;
    private RunGuard $guard;
    private float $startedAt;

    /** 本轮已输出、还没推出去的增量 */
    private string $pending = '';
    private float $lastFlush = 0.0;
    private int $round = 0;

    /** @var array{hit: int, miss: int, output: int, reasoning: int} */
    private array $usage = ['hit' => 0, 'miss' => 0, 'output' => 0, 'reasoning' => 0];

    private ?ToolBox $tools = null;

    public function __construct(private readonly ?LlmProvider $provider = null)
    {
    }

    public static function run(int $runId, ?LlmProvider $provider = null): void
    {
        (new self($provider))->execute($runId);
    }

    private function execute(int $runId): void
    {
        /** @var AiRunModel|null $run */
        $run = AiRunModel::query()->find($runId);
        if (!$run) {
            Log::warning('[ai] run 不存在，跳过', ['run' => $runId]);

            return;
        }

        // 队列重投、手工补投时不重复跑：已经开始或结束的一律跳过（与导出同理）
        if ($run->status !== AiRunModel::STATUS_PENDING) {
            return;
        }

        $this->run       = $run;
        $this->startedAt = microtime(true);

        $timeout     = max(10, (int) (ParamService::value('ai.run.timeout', 90) ?? 90));
        $this->guard = new RunGuard($runId, $this->startedAt + $timeout);

        $provider = $this->provider ?? new DeepSeekProvider();

        $run->status           = AiRunModel::STATUS_RUNNING;
        $run->started_at       = date('Y-m-d H:i:s');
        $run->provider         = $provider->name();
        $run->model            = mb_substr($provider->model(), 0, 64);
        $run->reasoning_effort = $provider->effort();
        $run->save();

        $this->push('ai.run.started', ['conv_id' => $run->conv_id]);

        $content = '';
        $status  = AiRunModel::STATUS_DONE;
        $error   = '';
        $refund  = false;

        try {
            // 排队期间就点了停止：一个 token 都不花
            if ($this->guard->shouldStop()) {
                $status = $this->guard->reason() === RunGuard::REASON_CANCEL ? AiRunModel::STATUS_STOPPED : AiRunModel::STATUS_FAILED;
                $error  = $status === AiRunModel::STATUS_FAILED ? '排队超时，请重试' : '';
                $refund = true;
            } else {
                $content = (string) AuthService::actAs($run->user_id, fn () => $this->loop($provider));

                if ($this->guard->reason() === RunGuard::REASON_CANCEL) {
                    $status = AiRunModel::STATUS_STOPPED;
                } elseif ($this->guard->reason() === RunGuard::REASON_TIMEOUT) {
                    $status = AiRunModel::STATUS_FAILED;
                    $error  = '回答超时，请缩小问题范围后重试';
                }
            }
        } catch (LlmException $e) {
            $status = AiRunModel::STATUS_FAILED;
            $error  = $e->getMessage();
            $refund = $e->refundQuota;
            $run->http_status = $e->httpStatus;
            if ($e->alertAdmin) {
                AiService::alertAdmins($e->getMessage());
            }
        } catch (AnswerException $e) {
            $status = AiRunModel::STATUS_FAILED;
            $error  = $e->getMessage();
            $refund = $e->refundQuota;
        } catch (\Throwable $e) {
            $status = AiRunModel::STATUS_FAILED;
            $error  = '回答时出错了，请稍后再试';
            $refund = true;
            Log::error('[ai] 问答异常', [
                'run'   => $runId,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
        } finally {
            // 身份已经在 actAs 的 finally 里清掉了。这里兜一道：万一将来有人在 actAs 外面调了工具
            if (Ctx::user() !== null) {
                Ctx::clear();
            }
            Cache::del(RunGuard::cancelKey($runId));
        }

        $this->finish($status, $content, $error, $refund);
    }

    /**
     * 模型 ⇄ 工具循环，返回最终回答（未经链接处理）
     *
     * 此时 Ctx 里已经是提问人。
     */
    private function loop(LlmProvider $provider): string
    {
        $user        = Ctx::user();
        $this->tools = new ToolBox($this->run->id);

        $messages = [
            ['role' => 'system', 'content' => Prompt::system()],
            ['role' => 'user', 'content' => Prompt::user(
                $user,
                Prompt::pages($user),
                AiService::history($this->run),
                AiService::questionText($this->run),
            )],
        ];

        $maxSteps  = max(1, (int) (ParamService::value('ai.run.maxSteps', 8) ?? 8));
        $steps     = 0;
        $lastText  = '';
        $allText   = '';

        // 硬上限：maxSteps 次工具调用 + 1 轮收尾 + 1 轮容错，防止模型反复要工具绕成死循环
        for ($i = 0; $i < $maxSteps + 2; $i++) {
            $this->round++;
            $this->pending = '';

            $turn = $provider->turn(
                $messages,
                $this->tools->definitions(),
                $this->guard,
                fn (string $text) => $this->onText($text),
                fn () => $this->push('ai.thinking', ['round' => $this->round]),
            );
            $this->flush(true);

            foreach ($this->usage as $k => $v) {
                $this->usage[$k] = $v + (int) ($turn->usage[$k] ?? 0);
            }

            $lastText = $turn->content;
            $allText .= $turn->content;

            switch ($turn->finish) {
                case LlmTurn::FINISH_TOOL_CALLS:
                    if (!$turn->toolCalls) {
                        return $lastText ?: $allText;
                    }

                    // ⚠️ assistant 消息必须原样带着 reasoning_content 回传（DeepSeek 的要求，§3.4）
                    $messages[] = $turn->assistantMessage();

                    foreach ($turn->toolCalls as $call) {
                        $steps++;
                        $content = $steps > $maxSteps
                            ? json_encode(['error' => '本次查询次数已达上限，请根据已有结果直接回答'], JSON_UNESCAPED_UNICODE)
                            : $this->tools->execute($call);

                        // 每个 tool_call_id 都要有一条对应的 tool 消息，少一条下一轮就 400
                        $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $content];

                        if ($steps <= $maxSteps) {
                            $step = end($this->tools->steps) ?: ['label' => $call['name'], 'rows' => 0, 'denied' => false];
                            $this->push('ai.step', $step);
                        }
                    }

                    $this->run->steps = min(255, $steps);

                    if ($this->guard->shouldStop()) {
                        return $lastText;
                    }
                    break;

                case LlmTurn::FINISH_ABORTED:
                    return $lastText;

                case LlmTurn::FINISH_LENGTH:
                    return ($lastText ?: $allText) . "\n\n（回答过长被截断，请缩小问题范围）";

                case LlmTurn::FINISH_CONTENT_FILTER:
                    // 重试大概率还是被拦，不返还配额
                    throw new AnswerException('这个问题我没法回答，换个问法试试', false);

                case LlmTurn::FINISH_OVERLOADED:
                    throw new AnswerException('AI 服务繁忙，请稍后再试');

                default:
                    // stop：正常结束。最后一轮是空的（极少见）就用之前轮次说过的话
                    return $lastText !== '' ? $lastText : $allText;
            }
        }

        throw new AnswerException('这个问题需要查询的步骤太多了，请拆成几个小问题再问');
    }

    private function onText(string $text): void
    {
        if ($this->run->first_token_ms === 0) {
            $created = $this->run->created_at?->getTimestamp() ?? (int) $this->startedAt;
            $this->run->first_token_ms = max(1, (int) round((microtime(true) - $created) * 1000));
        }

        $this->pending .= $text;
        $this->flush(false);
    }

    /** 把攒着的增量推出去。`round` 让前端知道换轮了——换轮时丢掉上一轮的半截话 */
    private function flush(bool $force): void
    {
        $now = microtime(true);
        if ($this->pending === '' || (!$force && $now - $this->lastFlush < self::DELTA_INTERVAL)) {
            return;
        }

        $this->push('ai.delta', ['round' => $this->round, 'text' => $this->pending]);
        $this->pending   = '';
        $this->lastFlush = $now;
    }

    private function push(string $event, array $data): void
    {
        ChatFanout::toUser($event, $this->run->user_id, $data + ['run_id' => $this->run->id]);
    }

    /**
     * 收尾：落回答消息、更新 run、返还配额
     *
     * 不管成功失败都**一定**落一条消息：前端靠 `message.new` 结束「思考中」状态，
     * 不落的话用户那边永远在转圈。
     */
    private function finish(int $status, string $content, string $error, bool $refund): void
    {
        $run = $this->run;

        [$content, $links] = $this->finalizeLinks($content);

        $text = match ($status) {
            AiRunModel::STATUS_DONE    => trim($content) !== '' ? trim($content) : '（没有生成回答，请换个问法试试）',
            AiRunModel::STATUS_STOPPED => trim($content),
            default                    => $error,
        };

        $extra = [
            'run_id' => $run->id,
            'status' => ['2' => 'done', '3' => 'stopped', '4' => 'failed'][(string) $status] ?? 'failed',
            'steps'  => $this->tools?->steps ?? [],
            'links'  => $links,
        ];
        if ($status === AiRunModel::STATUS_FAILED && trim($content) !== '') {
            // 超时之类的失败，已经输出的半截话也留着，附在错误后面
            $extra['partial'] = trim($content);
        }

        try {
            $message = ChatService::appendMessage($run->conv_id, 0, AiService::NAME, ImMessageModel::TYPE_AI, $text, $extra);
            $run->answer_msg_id = (int) $message['id'];
        } catch (\Throwable $e) {
            Log::error('[ai] 回答落库失败', ['run' => $run->id, 'error' => $e->getMessage()]);
        }

        $run->status            = $status;
        $run->rounds            = min(255, $this->round);
        $run->cache_hit_tokens  = $this->usage['hit'];
        $run->cache_miss_tokens = $this->usage['miss'];
        $run->output_tokens     = $this->usage['output'];
        $run->reasoning_tokens  = $this->usage['reasoning'];
        [$cost, $peak]          = AiService::cost($run->model, $this->usage, $run->started_at?->getTimestamp() ?? time());
        $run->cost_usd          = $cost;
        $run->is_peak           = $peak ? 1 : 0;
        $run->error_msg         = mb_substr($error, 0, 255);
        $run->duration_ms       = (int) round((microtime(true) - $this->startedAt) * 1000);
        $run->finished_at       = date('Y-m-d H:i:s');
        $run->save();

        // 能正常答完，说明密钥与余额都没问题了：审计页的故障横幅可以撤了
        if ($status === AiRunModel::STATUS_DONE) {
            AiService::clearAlert();
        }

        if ($refund) {
            AiService::refundQuota($run->user_id, $run->created_at?->format('Ymd') ?? date('Ymd'));
        }

        $this->push('ai.run.finished', ['status' => $extra['status']]);
    }

    /**
     * 把回答里的链接标记变成链接表
     *
     * - `[[link:N]]`：工具生成的「在列表中查看」，编号就是 ToolBox::$links 的下标
     * - `[[page:/path]]`：模型引用的页面，**服务端再核一遍**是否在提问人能打开的菜单里，
     *   不在的直接去掉（提示词里说了不许写，但不能靠模型听话）
     *
     * 统一改写成 `[[link:K]]` 放进 extra.links，前端只认这一种标记。
     * 模型自己写的 Markdown 链接前端一律当纯文本渲染。
     *
     * @return array{0: string, 1: list<array{path: string, query: array, label: string}>}
     */
    private function finalizeLinks(string $content): array
    {
        $toolLinks = $this->tools?->links ?? [];
        $links     = [];
        $index     = [];

        $pages = [];
        if (str_contains($content, '[[page:')) {
            // actAs 已经退出了，这里重新以提问人身份算一次页面清单
            try {
                $pages = AuthService::actAs($this->run->user_id, static fn () => Prompt::pages(Ctx::user()));
            } catch (\Throwable) {
                $pages = [];
            }
        }
        $pageByPath = array_column($pages, 'name', 'path');

        $content = preg_replace_callback('/\[\[(link|page):([^\]\s]+)\]\]/', function (array $m) use ($toolLinks, $pageByPath, &$links, &$index) {
            $key = $m[1] . ':' . $m[2];
            if (isset($index[$key])) {
                return '[[link:' . $index[$key] . ']]';
            }

            if ($m[1] === 'link') {
                $link = $toolLinks[(int) $m[2]] ?? null;
            } else {
                $link = isset($pageByPath[$m[2]]) ? ['path' => $m[2], 'query' => [], 'label' => '打开「' . $pageByPath[$m[2]] . '」'] : null;
            }

            if ($link === null) {
                return '';
            }

            $links[]     = $link;
            $index[$key] = count($links) - 1;

            return '[[link:' . $index[$key] . ']]';
        }, $content) ?? $content;

        return [$content, $links];
    }
}
