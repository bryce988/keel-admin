<?php
/**
 * keel admin
 * 一轮模型调用的结果，与服务商无关
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

final class LlmTurn
{
    /** 正常结束 */
    public const FINISH_STOP = 'stop';
    /** 要调工具 */
    public const FINISH_TOOL_CALLS = 'tool_calls';
    /** 输出被 max_tokens 截断 */
    public const FINISH_LENGTH = 'length';
    /** 服务商内容审核拦截 */
    public const FINISH_CONTENT_FILTER = 'content_filter';
    /** 服务商资源不足 */
    public const FINISH_OVERLOADED = 'insufficient_system_resource';
    /** 被我们自己中断（停止 / 超时），看 RunGuard::reason() */
    public const FINISH_ABORTED = 'aborted';

    /**
     * @param list<array{id: string, name: string, arguments: string}> $toolCalls
     *        arguments 是模型给的原始 JSON 字符串，由调用方解析（可能不合法）
     * @param array{hit: int, miss: int, output: int, reasoning: int} $usage
     */
    public function __construct(
        public readonly string $content,
        public readonly string $reasoning,
        public readonly array $toolCalls,
        public readonly string $finish,
        public readonly array $usage,
    ) {
    }

    /**
     * 这一轮在对话里的 assistant 消息
     *
     * ⚠️ `reasoning_content` 必须原样带上：DeepSeek 在请求带 tools 时要求之前每个
     * assistant 回合的思考内容都完整回传，否则 400（docs/ai-tech.md §3.4）。
     * 这条消息只活在消费进程的内存里，run 结束即丢，不落库。
     */
    public function assistantMessage(): array
    {
        $msg = [
            'role'              => 'assistant',
            'content'           => $this->content,
            'reasoning_content' => $this->reasoning,
        ];

        if ($this->toolCalls) {
            $msg['tool_calls'] = array_map(static fn (array $c) => [
                'id'       => $c['id'],
                'type'     => 'function',
                'function' => ['name' => $c['name'], 'arguments' => $c['arguments']],
            ], $this->toolCalls);
        }

        return $msg;
    }
}
