<?php
/**
 * keel admin
 * AI 助手「小k」
 *
 * 业务全在 `app\common\service\AiService`，回答在 AI 消费进程里生成（`AiRunner`），
 * 这里只做编排。所有「我的」接口的 user_id 都取自令牌。
 *
 * **不记操作日志**：与聊天同理，一次对话几十个提问会把真正要审计的动作淹掉；
 * 而且操作日志会把提问原文存第二份。AI 有自己的审计（ai_runs / ai_tool_calls，
 * 「日志审计 / AI 调用记录」）。
 *
 * @author 火火
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\validation\Ai\RunListRequest;
use app\common\service\AiService;
use app\common\support\Ctx;
use app\common\support\Paginator;
use app\common\support\Result;
use support\Request;
use support\Response;

class AiController
{
    /**
     * 我的小k 会话
     * @url GET /admin/ai/conversation
     * @perm ai:use
     * @description 不存在就创建并写一条欢迎语（按 `uk_peer` 幂等）。返回会话详情 +
     * `active_run_id`（有进行中的问答时非 0，刷新页面后据此恢复「思考中」）+ `suggestions`（按权限生成的示例问题）。
     * 历史消息复用 `GET /admin/chat/conversations/{id}/messages`。
     * @error 400 AI 助手未启用（21301）
     */
    public function conversation(Request $request): Response
    {
        return Result::ok(AiService::conversation(Ctx::userId()));
    }

    /**
     * 提问
     * @url POST /admin/ai/messages
     * @perm ai:use
     * @description `{content, client_msg_id?}`。**不等回答**：落提问消息、投递队列后立刻 202 +
     * `{message, run_id}`。回答经长连接流式推送（`ai.*` 帧），结束时落一条 `type=ai` 的消息并推 `message.new`。
     * @error 400 未启用（21301）/ 未配置密钥（21302）/ 超长（21306）/ 月预算用尽（21305）
     * @error 409 上一个问题还没回答完（21303）
     * @error 429 今日提问次数已用完（21304），带 Retry-After 到次日零点
     */
    public function ask(Request $request): Response
    {
        return Result::accepted(AiService::ask(
            Ctx::userId(),
            (string) $request->post('content', ''),
            (string) $request->post('client_msg_id', ''),
        ));
    }

    /**
     * 停止生成
     * @url POST /admin/ai/runs/{id}/cancel
     * @perm ai:use
     * @description 已输出的部分会保留并标注「已停止」。已经结束的幂等返回。
     * @error 404 不是自己的问答
     */
    public function cancel(Request $request, int $id): Response
    {
        AiService::cancel(Ctx::userId(), $id);

        return Result::noContent();
    }

    /**
     * 新对话
     * @url POST /admin/ai/reset
     * @perm ai:use
     * @description 在时间线上插一条「新对话」分隔，之后的提问不再带之前的上下文。历史仍在。
     * @error 409 上一个问题还没回答完
     */
    public function reset(Request $request): Response
    {
        return Result::ok(AiService::reset(Ctx::userId()));
    }

    /**
     * 反馈 👍 / 👎
     * @url POST /admin/ai/runs/{id}/feedback
     * @perm ai:use
     * @description `{rating: 1|-1|0, feedback?}`，0 = 取消评价。用于调提示词，不做自动学习。
     */
    public function feedback(Request $request, int $id): Response
    {
        AiService::feedback(
            Ctx::userId(),
            $id,
            (int) $request->post('rating', 0),
            (string) $request->post('feedback', ''),
        );

        return Result::noContent();
    }

    /**
     * AI 调用记录（审计）
     * @url GET /admin/ai/runs
     * @perm ai:log:list
     * @description 全公司的问答元数据：谁、何时、状态、token、估算费用、耗时。**不含提问与回答原文**。
     */
    public function runs(RunListRequest $request): Response
    {
        return Paginator::response(
            AiService::runQuery($request->validated()),
            $request->request(),
            sortable: ['id', 'cost_usd', 'duration_ms'],
            defaultField: 'id',
            defaultOrder: 'desc',
            mapPage: static fn ($rows) => AiService::runMapPage($rows->all()),
        );
    }

    /**
     * 用量汇总
     * @url GET /admin/ai/runs/summary
     * @perm ai:log:list
     * @description 今天与本月的次数、失败数、估算费用（美元）、缓存命中率，月预算，
     * 以及最近一次需要管理员处理的故障（密钥错、余额不足）。
     */
    public function summary(Request $request): Response
    {
        return Result::ok(AiService::usageSummary());
    }

    /**
     * AI 调用详情
     * @url GET /admin/ai/runs/{id}
     * @perm ai:log:detail
     * @description 含每一次工具调用：以谁的身份、查了什么、参数、返回行数、是否因权限被拒。**不含对话内容**。
     */
    public function run(Request $request, int $id): Response
    {
        return Result::ok(AiService::runDetail($id));
    }

    /**
     * 测试 DeepSeek 连接
     * @url POST /admin/ai/provider/test
     * @perm sys:param:update
     * @description 用**已保存**的配置查一次余额，不接受前端传密钥（否则会变成拿任意密钥去试的跳板）。
     * 失败也是 200，`ok=false` + `error`：「测试不通过」是这个接口的正常结果，不是接口出错。
     */
    public function testProvider(Request $request): Response
    {
        return Result::ok(AiService::providerTest());
    }
}
