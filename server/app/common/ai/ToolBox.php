<?php
/**
 * keel admin
 * 一次问答可用的工具集合 + 执行管线
 *
 * 权限判两道（docs/ai-tech.md §5.3）：
 * 1. 发给模型的工具清单先按提问人的权限过滤——没权限的工具模型根本不知道存在。这一道是体验
 * 2. 执行时再判一次——这一道才是边界。与「前端 v-permission 不是安全边界」同一个道理：
 *    模型完全可能凭空编一个工具名来调
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

use app\common\exception\ValidationException;
use app\common\model\AiToolCallModel;
use app\common\service\ParamService;
use app\common\service\PermissionService;
use app\common\support\Arr;
use app\common\support\Ctx;
use app\common\support\Validator;
use support\Log;

final class ToolBox
{
    /** 一次返回给模型的最多行数。超过的给 total + 提示去列表页看完整结果 */
    public const MAX_ROWS = 50;

    /** @var array<string, AiTool> 按 name 排序（前缀稳定才吃得到服务商的缓存，§3.6） */
    private array $tools = [];

    /** @var list<array{path: string, query: array, label: string}> 回答里 [[link:N]] 引用的链接 */
    public array $links = [];

    /** @var list<array{label: string, rows: int, denied: bool}> 界面「查询了 N 项」 */
    public array $steps = [];

    private bool $unmask;

    public function __construct(private readonly int $runId)
    {
        $user = Ctx::user() ?? [];

        foreach ((array) config('ai.tools', []) as $class) {
            /** @var AiTool $tool */
            $tool = new $class();
            if (self::allowed($user, $tool)) {
                $this->tools[$tool->name()] = $tool;
            }
        }
        ksort($this->tools);

        $this->unmask = (bool) (int) (ParamService::value('ai.field.unmask', 0) ?? 0);
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return array_values(array_map(static fn (AiTool $t) => $t->definition(), $this->tools));
    }

    /** 提问人能用的工具名，拼欢迎语的示例问题用 */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * 执行一次调用，返回要放进 `role: tool` 消息的 content
     *
     * 任何失败都**不抛出**，而是写成 `{"error": "…"}` 回给模型——它能据此换个方式查，
     * 或者如实告诉用户。一次工具失败不该让整个问答失败。
     *
     * @param array{id: string, name: string, arguments: string} $call
     */
    public function execute(array $call): string
    {
        $started = microtime(true);
        $name    = $call['name'];
        $args    = json_decode($call['arguments'] ?: '{}', true);

        $audit = [
            'run_id'         => $this->runId,
            // 取执行这一刻的身份，不抄 run.user_id——两者不一致就是串号，专项用例断言这一列
            'acting_user_id' => Ctx::userId(),
            'tool'           => mb_substr($name, 0, 64),
            'args'           => is_array($args) ? $args : null,
        ];

        try {
            if (!is_array($args)) {
                return $this->fail($audit, $started, '参数不是合法的 JSON 对象');
            }

            // 清单里没有，可能是真没有，也可能是提问人没权限（清单是按权限过滤过的）。
            // 两种情况回给模型的话不一样，但都不执行
            $tool = $this->tools[$name] ?? null;
            if ($tool === null) {
                $class = self::classOf($name);
                if ($class !== null) {
                    /** @var AiTool $probe */
                    $probe = new $class();
                    $label = $probe->permLabel();
                    $this->steps[] = ['label' => "{$label}（无权限）", 'rows' => 0, 'denied' => true];

                    return $this->fail($audit + ['denied' => 1, 'label' => $label], $started,
                        "当前用户没有『{$label}』权限，无法查询。请如实告知用户需要该权限，不要猜测数据");
                }

                return $this->fail($audit, $started, "没有名为 {$name} 的工具");
            }

            // 执行前再判一次权限：清单是问答开始时算的，这一刻才是边界
            if (!self::allowed(Ctx::user() ?? [], $tool)) {
                $label = $tool->permLabel();
                $this->steps[] = ['label' => "{$label}（无权限）", 'rows' => 0, 'denied' => true];

                return $this->fail($audit + ['denied' => 1, 'label' => $label], $started,
                    "当前用户没有『{$label}』权限，无法查询");
            }

            try {
                $clean = Validator::make($args, $tool->rules())->validated();
            } catch (ValidationException $e) {
                return $this->fail($audit, $started, '参数不合法：' . json_encode($e->details ?? [], JSON_UNESCAPED_UNICODE));
            }

            $result = $tool->run($clean);
        } catch (\app\common\exception\NotFoundException) {
            // 范围外与不存在同一个说法：不确认记录存在（与接口的 404 伪装一致）
            return $this->fail($audit, $started, '没有找到（在当前用户的可见范围内不存在）');
        } catch (\app\common\exception\ApiException $e) {
            return $this->fail($audit, $started, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[ai] 工具执行异常', ['run' => $this->runId, 'tool' => $name, 'error' => $e->getMessage()]);

            return $this->fail($audit, $started, '查询出错');
        }

        $rows = array_slice($result->rows, 0, self::MAX_ROWS);
        $rows = $this->mask($tool, $rows);

        $out = [
            'total'    => $result->total,
            'returned' => count($rows),
        ];
        if ($result->scopeNote !== null) {
            $out['scope_note'] = $result->scopeNote;
        }
        if (count($result->rows) > count($rows)) {
            $out['truncated'] = '结果较多，只返回了前 ' . count($rows) . ' 条。完整结果请引导用户点列表链接查看';
        }
        if ($result->link !== null) {
            $this->links[] = [
                'path'  => $result->link['path'],
                'query' => $result->link['query'] ?? [],
                'label' => $result->link['label'] ?? '在列表中查看',
            ];
            $out['link'] = '[[link:' . (count($this->links) - 1) . ']]';
        }
        if ($result->summary) {
            $out['summary'] = $result->summary;
        }
        if ($rows) {
            $out['rows'] = $rows;
        }

        $this->steps[] = ['label' => $result->label, 'rows' => $result->total, 'denied' => false];

        self::audit($audit + [
            'label'        => mb_substr($result->label, 0, 255),
            'result_rows'  => count($rows),
            'result_total' => $result->total,
        ], $started);

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 权限点与路由同一套语义 */
    private static function allowed(array $user, AiTool $tool): bool
    {
        $perm = $tool->perm();

        return is_array($perm) ? PermissionService::hasAny($user, $perm) : PermissionService::has($user, $perm);
    }

    /** 按工具名找登记表里的类（判断「是没这个工具，还是没权限」用） */
    private static function classOf(string $name): ?string
    {
        foreach ((array) config('ai.tools', []) as $class) {
            if ((new $class())->name() === $name) {
                return $class;
            }
        }

        return null;
    }

    /**
     * AI 层的第二道脱敏
     *
     * presenter 已经按字段权限脱敏过一次（没权限的本来就是掩码）。这里是「不管有没有权限，
     * 默认都不把明文发给模型服务商」——个人能看明文不等于公司同意把全员手机号发出去。
     */
    private function mask(AiTool $tool, array $rows): array
    {
        $sensitive = $tool->sensitive();
        if ($this->unmask || !$sensitive) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($sensitive as $key => $kind) {
                if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                    $row[$key] = $kind === 'email' ? Arr::maskEmail($row[$key]) : Arr::mask($row[$key]);
                }
            }
        }

        return $rows;
    }

    private function fail(array $audit, float $started, string $message): string
    {
        self::audit($audit + ['error_msg' => mb_substr($message, 0, 255)], $started);

        return json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    }

    /** 审计写失败只记日志：不能因为审计表出问题就让回答失败，但必须留下痕迹 */
    private static function audit(array $row, float $started): void
    {
        try {
            AiToolCallModel::create($row + [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            Log::error('[ai] 工具调用审计写入失败', ['error' => $e->getMessage(), 'tool' => $row['tool'] ?? '']);
        }
    }
}
