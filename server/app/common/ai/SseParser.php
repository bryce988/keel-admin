<?php
/**
 * keel admin
 * OpenAI 格式流式响应（SSE）的增量解析
 *
 * curl 的写回调给的是**任意切分的字节块**：一个事件可能被切成两半，
 * 一个中文字符的三个字节也可能落在两次回调里。所以这里只按事件边界（空行）切，
 * 没收完的尾巴留到下一次——按回调边界直接 json_decode 的写法，本地网络好时永远正常，
 * 一上公网就偶发「JSON 解析失败」。
 *
 * 事件格式：
 *
 *     data: {"choices":[{"delta":{"content":"你"}}]}
 *
 *     : keep-alive          ← 注释行，忽略
 *
 *     data: [DONE]
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

final class SseParser
{
    private string $buffer = '';

    public string $content = '';
    public string $reasoning = '';
    public string $finish = '';
    public bool $done = false;

    /** @var array<int, array{id: string, name: string, arguments: string}> 按 index 累积 */
    private array $tools = [];

    /** @var array{hit: int, miss: int, output: int, reasoning: int} */
    public array $usage = ['hit' => 0, 'miss' => 0, 'output' => 0, 'reasoning' => 0];

    /**
     * @param callable(string): void $onText
     * @param callable(): void       $onThinking
     */
    public function __construct(
        private readonly mixed $onText,
        private readonly mixed $onThinking,
    ) {
    }

    public function feed(string $bytes): void
    {
        // 统一换行：有的网关会把 \n 改成 \r\n
        $this->buffer .= str_replace("\r\n", "\n", $bytes);

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $block = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            $this->event($block);
        }
    }

    /** 连接结束时调一次：最后一个事件后面不一定有空行 */
    public function flush(): void
    {
        if (trim($this->buffer) !== '') {
            $this->event($this->buffer);
        }
        $this->buffer = '';
    }

    /** @return list<array{id: string, name: string, arguments: string}> */
    public function toolCalls(): array
    {
        ksort($this->tools);

        return array_values($this->tools);
    }

    private function event(string $block): void
    {
        $data = '';
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'data:')) {
                // 规范允许一个事件多行 data，拼起来
                $data .= ltrim(substr($line, 5));
            }
        }

        if ($data === '') {
            return;
        }
        if ($data === '[DONE]') {
            $this->done = true;

            return;
        }

        $chunk = json_decode($data, true);
        if (!is_array($chunk)) {
            return;
        }

        $choice = $chunk['choices'][0] ?? null;
        if (is_array($choice)) {
            $delta = $choice['delta'] ?? [];

            $thinking = (string) ($delta['reasoning_content'] ?? '');
            if ($thinking !== '') {
                if ($this->reasoning === '') {
                    ($this->onThinking)();
                }
                $this->reasoning .= $thinking;
            }

            $text = (string) ($delta['content'] ?? '');
            if ($text !== '') {
                $this->content .= $text;
                ($this->onText)($text);
            }

            foreach ((array) ($delta['tool_calls'] ?? []) as $call) {
                $i = (int) ($call['index'] ?? 0);
                $this->tools[$i] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                // id 与 name 只在第一片出现；arguments 是分片的 JSON 字符串，拼完才能解析
                if (!empty($call['id'])) {
                    $this->tools[$i]['id'] = (string) $call['id'];
                }
                if (!empty($call['function']['name'])) {
                    $this->tools[$i]['name'] .= (string) $call['function']['name'];
                }
                $this->tools[$i]['arguments'] .= (string) ($call['function']['arguments'] ?? '');
            }

            if (!empty($choice['finish_reason'])) {
                $this->finish = (string) $choice['finish_reason'];
            }
        }

        // include_usage 时只有最后一个 chunk 带非空 usage
        if (is_array($chunk['usage'] ?? null)) {
            $u = $chunk['usage'];
            $this->usage = [
                'hit'       => (int) ($u['prompt_cache_hit_tokens'] ?? 0),
                'miss'      => (int) ($u['prompt_cache_miss_tokens'] ?? ($u['prompt_tokens'] ?? 0)),
                'output'    => (int) ($u['completion_tokens'] ?? 0),
                'reasoning' => (int) ($u['completion_tokens_details']['reasoning_tokens'] ?? 0),
            ];
        }
    }
}
