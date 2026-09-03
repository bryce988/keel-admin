import request from '@/utils/request'

/** 系统参数接口。含登录页要用的免登录白名单参数 */

export interface ParamRow {
  id: number
  group: string
  name: string
  param_key: string
  /** is_secret 为真时后端一律回 '******'，提交时原样送回即表示不修改 */
  param_value: string
  value_type: 'string' | 'int' | 'bool' | 'json'
  is_builtin: boolean
  is_secret: boolean
  remark: string
  updated_at: string
}

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type ParamPayload = Partial<ParamRow>

/** 密钥掩码，与后端 ParamService::MASK 一致 */
export const PARAM_MASK = '******'

/**
 * 登录页要用的少量参数（系统名、Logo、页脚）
 *
 * 免登录接口，白名单在后端 `ParamService::PUBLIC_KEYS` 里，加键要改那一处。
 * 键名带点，是数据库里的 `param_key` 原样下发，不做驼峰转换（全链路 snake/原样）。
 */
export interface PublicParams {
  'sys.name'?: string
  'sys.logo'?: string
  'sys.footer'?: string
  /**
   * 邮箱登录是否可用
   *
   * 不是参数表里的键，是后端「.env 里 SMTP 配没配」的投影（见 ParamService::publicParams）。
   * 部署方没配 SMTP 还把入口摆出来，用户点了发码只会拿到一个错误。
   */
  'sys.login.emailEnabled'?: boolean
}

export function fetchPublicParams() {
  return request.get<unknown, PublicParams>('/admin/params/public')
}

export function fetchParamGroups() {
  return request.get<unknown, Array<{ code: string; name: string }>>('/admin/params/groups')
}

export function fetchParams(group: string) {
  return request.get<unknown, ParamRow[]>('/admin/params', { params: { group } })
}

export function saveParams(items: Array<{ param_key: string; param_value: string }>) {
  return request.put<unknown, { saved_count: number }>('/admin/params', { items })
}

export function createParam(data: ParamPayload) {
  return request.post<unknown, ParamRow>('/admin/params', data)
}

export function updateParam(id: number, data: ParamPayload) {
  return request.put<unknown, ParamRow>(`/admin/params/${id}`, data)
}

export function deleteParam(id: number) {
  return request.delete<unknown, void>(`/admin/params/${id}`)
}
