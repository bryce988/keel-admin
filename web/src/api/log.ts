import request from '@/utils/request'
import type { ExportAccepted } from '@/api/export'
import type { PageResult, TableQuery } from '@/components'

/**
 * 日志审计接口（只读）
 *
 * 日志没有写接口：操作日志由后端中间件落库，登录日志由登录流程落库。
 * 单独成文件而不是塞进 system.ts —— 它是另一个菜单大类，
 * 而 system.ts 已经装了七个模块。
 */

/** 字段级变更，日志最有价值的部分 */
export interface LogChange {
  field: string
  old: unknown
  new: unknown
}

export interface OperationLogRow {
  id: number
  trace_id: string
  username: string
  module: string
  /** 1新增 2修改 3删除 4导出 5授权 6其他，走 log_action 字典 */
  action: number
  title: string
  target: string
  api_method: string
  api_path: string
  ip: string
  status: number
  error_msg: string
  duration: number
  /** 变更字段数，列表上据此提示哪几行值得点开 */
  change_count: number
  created_at: string
}

export interface OperationLogDetail extends OperationLogRow {
  user_id: number
  dept_id: number
  user_agent: string
  /** 请求参数，密码/密钥等已由后端脱敏 */
  params: Record<string, unknown>
  changes: LogChange[]
}

export interface LoginLogRow {
  id: number
  user_id: number
  username: string
  ip: string
  location: string
  browser: string
  os: string
  /** 1登录 2登出 */
  type: number
  status: number
  msg: string
  created_at: string
}

/**
 * 把页面上的 `date_range` 拆成接口要的 `start_time` / `end_time`
 *
 * `<SearchForm type="daterange">` 给的是一个两元数组，而接口收两个独立字段。
 * 两个日志页都要这一步，放在这里而不是各写一遍——不然迟早一边改了另一边没改。
 */
export function splitDateRange<T extends Record<string, unknown>>(params: T): T {
  const { date_range: range, ...rest } = params
  const [start, end] = (range as string[] | undefined) ?? []

  return { ...rest, start_time: start ?? '', end_time: end ?? '' } as unknown as T
}

export function fetchOperationLogs(params: TableQuery) {
  return request.get<unknown, PageResult<OperationLogRow>>('/admin/logs/operation', { params })
}

export function fetchOperationLog(id: number) {
  return request.get<unknown, OperationLogDetail>(`/admin/logs/operation/${id}`)
}

/**
 * 发起导出操作日志
 *
 * 返回的是**任务回执**（202 + `{task_id, message}`），不是文件——
 * 文件由队列生成，用户到「数据管理 / 数据导出」下载。
 * 原来这里走的是 `download()`（直接拿 blob），改异步后再用它会拿到一段 JSON。
 */
export function exportOperationLogs(params: Record<string, unknown>) {
  return request.get<unknown, ExportAccepted>('/admin/logs/operation/export', { params })
}

export function fetchLoginLogs(params: TableQuery) {
  return request.get<unknown, PageResult<LoginLogRow>>('/admin/logs/login', { params })
}

/** 发起导出登录日志，同样返回任务回执而不是文件 */
export function exportLoginLogs(params: Record<string, unknown>) {
  return request.get<unknown, ExportAccepted>('/admin/logs/login/export', { params })
}
