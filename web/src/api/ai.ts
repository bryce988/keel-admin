import request from '@/utils/request'
import type { ChatConversation, ChatMessage } from '@/api/chat'
import type { PageResult, TableQuery } from '@/types/api'

/**
 * AI 助手「小k」
 *
 * 契约见 docs/api.md §16 与 docs/ai-tech.md §9。与聊天的区别：
 *
 * - 提问**不等回答**：202 + run_id，回答经长连接流式推送（`ai.*` 帧），
 *   结束时落一条 type=ai 的消息并推 message.new——最终以那条消息为准
 * - 历史消息复用聊天的 `getMessages()`，小k 会话就是一个 type=3 的会话
 * - 小k 没有自己的身份：能查到什么由提问人自己的权限决定，前端不做任何判断
 */

export interface AiConversation extends ChatConversation {
  /** 有进行中的问答时非 0。刷新页面后据此恢复「思考中」 */
  active_run_id: number
  /** 按提问人权限生成的示例问题 */
  suggestions: string[]
}

export interface AiLink {
  path: string
  query: Record<string, string>
  label: string
}

export interface AiStep {
  label: string
  /** 查到的总数 */
  rows: number
  /** 因为没有权限没查成 */
  denied: boolean
}

/** 小k 回答消息的 extra */
export interface AiMessageExtra {
  run_id?: number
  kind?: 'welcome'
  status?: 'done' | 'stopped' | 'failed'
  steps?: AiStep[]
  /** 回答里的 [[link:N]] 按下标对应这里 */
  links?: AiLink[]
  /** 失败（如超时）前已经输出的部分 */
  partial?: string
}

export function getAiConversation() {
  return request.get<unknown, AiConversation>('/admin/ai/conversation')
}

export function askAi(content: string, clientMsgId: string) {
  return request.post<unknown, { message: ChatMessage; run_id: number }>('/admin/ai/messages', {
    content,
    client_msg_id: clientMsgId,
  })
}

export function cancelAiRun(runId: number) {
  return request.post<unknown, void>(`/admin/ai/runs/${runId}/cancel`)
}

/** 新对话：插一条分隔，之后的提问不再带之前的上下文 */
export function resetAi() {
  return request.post<unknown, ChatMessage>('/admin/ai/reset')
}

/** 1 有用 · -1 没用 · 0 取消 */
export function feedbackAi(runId: number, rating: number, feedback = '') {
  return request.post<unknown, void>(`/admin/ai/runs/${runId}/feedback`, { rating, feedback })
}

// ---------------------------------------------------------------- 审计（ai:log:list）

export interface AiRunRow {
  id: number
  user_id: number
  user_name: string
  username: string
  /** 0 排队 1 回答中 2 完成 3 停止 4 失败 */
  status: number
  model: string
  reasoning_effort: string
  steps: number
  rounds: number
  cache_hit_tokens: number
  cache_miss_tokens: number
  output_tokens: number
  reasoning_tokens: number
  /** 估算，美元 */
  cost_usd: string
  first_token_ms: number
  duration_ms: number
  http_status: number
  error_msg: string
  rating: number
  feedback: string
  trace_id: string
  created_at: string
  finished_at: string | null
}

export interface AiToolCall {
  id: number
  tool: string
  label: string
  acting_user_id: number
  args: Record<string, unknown> | null
  result_rows: number
  result_total: number
  denied: boolean
  error_msg: string
  duration_ms: number
  created_at: string
}

export interface AiRunDetail extends AiRunRow {
  tool_calls: AiToolCall[]
}

export interface AiUsageWindow {
  runs: number
  failed: number
  cost_usd: string
  /** 缓存命中率（%），没有数据时为 null */
  cache_hit_rate: number | null
}

export interface AiUsageSummary {
  today: AiUsageWindow
  month: AiUsageWindow
  /** 月预算（美元），0 = 不限 */
  budget: number
  /** 最近一次需要管理员处理的故障（密钥错、余额不足），没有为 null */
  alert: { message: string; at: string } | null
}

export function fetchAiRuns(params: TableQuery) {
  return request.get<unknown, PageResult<AiRunRow>>('/admin/ai/runs', { params })
}

export function fetchAiRun(id: number) {
  return request.get<unknown, AiRunDetail>(`/admin/ai/runs/${id}`)
}

export function fetchAiSummary() {
  return request.get<unknown, AiUsageSummary>('/admin/ai/runs/summary')
}

export interface AiProviderTest {
  ok: boolean
  is_available: boolean
  balances: { currency: string; total_balance: string }[]
  error: string
}

/** 参数配置页「测试连接」：用已保存的配置测，不传密钥 */
export function testAiProvider() {
  return request.post<unknown, AiProviderTest>('/admin/ai/provider/test')
}
