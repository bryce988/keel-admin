<?php
/**
 * keel admin
 * DeepSeek 调用（OpenAI 兼容的 Chat Completions，SSE 流式）
 *
 * 接口细节来自 api-docs.deepseek.com，2026-09-23 核对，设计取舍见 docs/ai-tech.md §3。
 *
 * ## 为什么用 ext-curl 直接调，不引 SDK
 *
 * 容器里有 curl 扩展，vendor 里没有 guzzle。为一个接口引入 openai-php/client
 * + PSR-18 全家桶不划算，而 SSE 解析只有几十行（`SseParser`）。
 *
 * ## 配置从哪来
 *
 * 与邮件同一个模式（`MailService::conf()`）：参数表填了就用，留空回落 `.env`，粒度是单个键。
 * **接口地址只认 `.env` 的 `DEEPSEEK_BASE_URL`，不进参数表**——能在界面上改地址，
 * 就能把地址改成自己的服务器，下一次提问时密钥会放在 Authorization 头里发过去，
 * 「密钥只写不读」的保护就白做了。
 *
 * 每个 run 新建一个实例，**不做进程级单例**：配置每次从 `ParamService` 读
 * （它走 Redis 缓存、改参数时即删），在参数配置页换了密钥下一问就生效，不用 restart。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

use app\common\service\ParamService;
use support\Log;

final class DeepSeekProvider implements LlmProvider
{
    public const DEFAULT_BASE_URL = 'https://api.deepseek.com';
    public const DEFAULT_MODEL    = 'deepseek-flash';
    public const DEFAULT_EFFORT   = 'high';

    /** 思考强度的合法值。none = 关闭思考模式 */
    public const EFFORTS = ['none', 'low', 'high', 'max'];

    /** 服务商过载、网络抖动时的重试间隔（秒）。只在还没往外吐任何字的时候重试 */
    private const RETRY_DELAYS = [1, 3];

    private string $apiKey;
    private string $model;
    private string $effort;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = self::conf('ai.deepseek.apiKey', 'DEEPSEEK_API_KEY');
        $this->model   = self::conf('ai.deepseek.model', 'DEEPSEEK_MODEL', self::DEFAULT_MODEL);
        $effort        = strtolower(self::conf('ai.deepseek.reasoningEffort', 'DEEPSEEK_REASONING_EFFORT', self::DEFAULT_EFFORT));
        $this->effort  = in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
        $this->baseUrl = rtrim(self::env('DEEPSEEK_BASE_URL', self::DEFAULT_BASE_URL), '/');
    }

    /** 密钥配了没有。没配时提问接口直接拒绝，而不是排进队列再失败 */
    public static function configured(): bool
    {
        return self::conf('ai.deepseek.apiKey', 'DEEPSEEK_API_KEY') !== '';
    }

    public function name(): string
    {
        return 'deepseek';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function effort(): string
    {
        return $this->effort;
    }

    public function turn(array $messages, array $tools, RunGuard $guard, callable $onText, callable $onThinking): LlmTurn
    {
        $body = [
            'model'          => $this->model,
            'messages'       => $messages,
            'stream'         => true,
            // usage 只在最后一个 chunk 里，不开这个就拿不到 token 数
            'stream_options' => ['include_usage' => true],
        ];

        if ($tools) {
            $body['tools']       = $tools;
            $body['tool_choice'] = 'auto';
        }

        if ($this->effort === 'none') {
            $body['thinking']   = ['type' => 'disabled'];
            $body['max_tokens'] = 4096;
        } else {
            $body['thinking']         = ['type' => 'enabled'];
            $body['reasoning_effort'] = $this->effort;
            // 思考 token 也算在 max_tokens 里。回答本身很短，这个上限主要是给思考留的余量，
            // 同时防止失控——给小了会在思考阶段就被截断（finish=length），一个字都答不出来
            $body['max_tokens'] = 32768;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $attempt = 0;
        while (true) {
            $parser = new SseParser($onText, $onThinking);

            try {
                return $this->stream($payload, $parser, $guard);
            } catch (LlmException $e) {
                // 已经往外吐过字了就不能重试：前端那边已经显示了半句话，重来一遍会接着拼出两段
                $retryable = in_array($e->httpStatus, [0, 429, 500, 502, 503, 504], true)
                    && $parser->content === ''
                    && $parser->reasoning === '';

                if (!$retryable || $attempt >= count(self::RETRY_DELAYS) || $guard->shouldStop()) {
                    throw $e;
                }

                sleep(self::RETRY_DELAYS[$attempt]);
                $attempt++;
            }
        }
    }

    /**
     * 查余额（参数配置页的「测试连接」）
     *
     * 用已保存的配置测，不接受外部传入的密钥——否则这个接口就成了「拿任意密钥去试」的跳板。
     *
     * @return array{is_available: bool, balances: list<array{currency: string, total_balance: string}>}
     */
    public function balance(): array
    {
        if ($this->apiKey === '') {
            throw new LlmException('尚未配置 DeepSeek 密钥', 0, false);
        }

        $ch = curl_init($this->baseUrl . '/user/balance');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno) {
            throw new LlmException('连不上 DeepSeek：' . $error, 0);
        }
        if ($status !== 200) {
            throw self::httpError($status, (string) $raw);
        }

        $data = json_decode((string) $raw, true) ?: [];

        return [
            'is_available' => (bool) ($data['is_available'] ?? false),
            'balances'     => array_map(static fn (array $b) => [
                'currency'      => (string) ($b['currency'] ?? ''),
                'total_balance' => (string) ($b['total_balance'] ?? '0'),
            ], (array) ($data['balance_infos'] ?? [])),
        ];
    }

    private function stream(string $payload, SseParser $parser, RunGuard $guard): LlmTurn
    {
        if ($this->apiKey === '') {
            throw new LlmException('AI 服务尚未配置密钥，请联系管理员', 401, true, true);
        }

        $status    = 0;
        $errorBody = '';
        $aborted   = false;

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 总时长交给 RunGuard（参数 ai.run.timeout），curl 自己不设上限
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$status) {
                // 状态行要在正文之前拿到：非 200 时正文是一段 JSON 错误，不能当 SSE 解析
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$status, &$errorBody, &$aborted, $parser, $guard) {
                if ($guard->shouldStop()) {
                    $aborted = true;

                    // 返回值 ≠ 写入长度 → curl 立即断开连接。这就是「停止」
                    return -1;
                }

                if ($status !== 200) {
                    // 错误体不大，但也别无限攒
                    if (strlen($errorBody) < 8192) {
                        $errorBody .= $chunk;
                    }
                } else {
                    $parser->feed($chunk);
                }

                return strlen($chunk);
            },
            // 连接建立阶段、服务端思考时长时间不回字节，写回调都不会被调；
            // 进度回调大约每秒触发一次，靠它兜住「停止」与超时
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static function () use (&$aborted, $guard) {
                if ($guard->shouldStop()) {
                    $aborted = true;

                    return 1;
                }

                return 0;
            },
        ]);

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($aborted) {
            $parser->flush();

            return new LlmTurn($parser->content, $parser->reasoning, [], LlmTurn::FINISH_ABORTED, $parser->usage);
        }

        if ($ok === false || $errno) {
            Log::warning('[ai] DeepSeek 连接失败', ['errno' => $errno, 'error' => $error]);

            throw new LlmException('暂时连不上 AI 服务，请稍后再试', 0);
        }

        if ($status !== 200) {
            throw self::httpError($status, $errorBody);
        }

        $parser->flush();

        $finish = $parser->finish ?: LlmTurn::FINISH_STOP;

        return new LlmTurn($parser->content, $parser->reasoning, $parser->toolCalls(), $finish, $parser->usage);
    }

    /**
     * HTTP 错误 → 给用户看的话
     *
     * 错误码表见 api-docs.deepseek.com 的 error_codes。原始错误体只进日志：
     * 400/422 时里面可能回显了我们发过去的请求片段。
     */
    private static function httpError(int $status, string $body): LlmException
    {
        Log::warning('[ai] DeepSeek 返回错误', ['status' => $status, 'body' => mb_substr($body, 0, 1000)]);

        return match ($status) {
            401     => new LlmException('AI 服务密钥无效，请联系管理员', 401, true, true),
            402     => new LlmException('AI 服务账户余额不足，请联系管理员', 402, true, true),
            429     => new LlmException('AI 服务繁忙，请稍后再试', 429),
            500, 502, 503, 504 => new LlmException('AI 服务暂时不可用，请稍后再试', $status),
            // 400 / 422：是我们的请求拼错了（最可能是思考内容没回传，docs/ai-tech.md §3.4）
            default => new LlmException('AI 服务调用失败，请联系管理员', $status, true, false),
        };
    }

    /** 参数表优先、`.env` 兜底，与 MailService::conf() 同一个语义 */
    private static function conf(string $paramKey, string $envKey, string $default = ''): string
    {
        $value = trim((string) (ParamService::value($paramKey) ?? ''));

        return $value !== '' ? $value : self::env($envKey, $default);
    }

    /** 不走 Env::get()：它会把 "null"/"false" 这类字符串转成 PHP 值，对密钥是错的 */
    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
