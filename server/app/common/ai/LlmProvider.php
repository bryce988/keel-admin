<?php
/**
 * keel admin
 * 模型调用层接口
 *
 * 实现目前只有 `DeepSeekProvider` 一个。仍然留一层接口，理由见 docs/ai-tech.md §3.1：
 * 它把「模型调用」与「工具循环、身份、落库」彻底分开，权限矩阵脚本可以换一个假的
 * provider 跑——不花钱、结果可重复。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\common\ai;

interface LlmProvider
{
    /** 服务商标识，落进 ai_runs.provider */
    public function name(): string;

    /** 本次使用的模型名，落进 ai_runs.model */
    public function model(): string;

    /** 本次使用的思考强度，落进 ai_runs.reasoning_effort */
    public function effort(): string;

    /**
     * 一轮模型调用（可能以 tool_calls 结束）
     *
     * @param list<array<string, mixed>> $messages   OpenAI 格式的消息数组
     * @param list<array<string, mixed>> $tools      工具定义，已按提问人权限过滤、按 name 排序
     * @param callable(string): void     $onText     正文增量
     * @param callable(): void           $onThinking 进入思考阶段（每轮最多调一次，只用来推「思考中」）
     *
     * @throws LlmException 服务商返回错误或网络不通（已按可重试的错误重试过）
     */
    public function turn(array $messages, array $tools, RunGuard $guard, callable $onText, callable $onThinking): LlmTurn;
}
