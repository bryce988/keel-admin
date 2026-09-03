import request from '@/utils/request'
import type { ExportAccepted } from '@/api/export'
import type { BatchOutcome, PageResult, TableQuery } from '@/types/api'
import type { RoleRow } from './role'

/**
 * 用户接口
 *
 * 页面不直接拼 URL：接口路径与响应类型集中在 `api/system/` 下，按模块一个文件，
 * 后端改契约时只改一处，TS 会把所有受影响的调用点标红。
 */

export interface UserRow {
  id: number
  username: string
  real_name: string
  avatar: string
  phone: string
  email: string
  dept_id: number
  dept_name: string
  post_name: string
  status: number
  is_super: boolean
  last_login_at: string | null
  created_at: string
}

export interface UserDetail extends UserRow {
  post_id: number
  remark: string
  role_ids: number[]
}

/**
 * 写接口的请求体类型
 * =================
 *
 * 原来这些函数的入参一律是 `Record<string, unknown>`，等于放弃了类型：
 * 表单里把 `real_name` 打成 `realname`，前端一路绿灯，直到接口 422 才知道。
 *
 * 一律取 `Partial<行类型>`，而不是 `Partial<Omit<行类型, 只读字段>>`：
 * 同一个 `<FormDrawer>` 既做新增/编辑也做详情（`mode: 'view'`），
 * 详情模板要读 `created_at`、`is_builtin` 这些只读字段。把它们 Omit 掉，
 * 编译期是干净了，代价是详情页取不到值——收紧类型不该把功能改窄。
 *
 * 只读字段传给后端是无害的：写接口都走 `StoreXxxRequest::validated()` 白名单，
 * 不在白名单里的键根本到不了 service 层。
 *
 * 用 `Partial<行类型>` 而不是另写一份字段清单，是为了让它跟着行类型自动同步——
 * 另写一份的话两边迟早对不上，而对不上的那天不会有任何报错。
 */
export type UserPayload = Partial<UserDetail> & {
  /** 只在新增时出现，编辑表单里没有这一项 */
  password?: string
}

export function fetchUsers(params: TableQuery) {
  return request.get<unknown, PageResult<UserRow>>('/admin/users', { params })
}

export function fetchUser(id: number) {
  return request.get<unknown, UserDetail>(`/admin/users/${id}`)
}

/** 新建成功时返回 initial_password —— 只有这一次能拿到明文 */
export function createUser(data: UserPayload) {
  return request.post<unknown, UserDetail & { initial_password: string }>('/admin/users', data)
}

export function updateUser(id: number, data: UserPayload) {
  return request.put<unknown, UserDetail>(`/admin/users/${id}`, data)
}

export function deleteUser(id: number) {
  return request.delete<unknown, void>(`/admin/users/${id}`)
}

export function setUserStatus(id: number, status: number) {
  return request.put<unknown, void>(`/admin/users/${id}/status`, { status })
}

export function grantUserRoles(id: number, role_ids: number[]) {
  return request.put<unknown, void>(`/admin/users/${id}/roles`, { role_ids })
}

export function resetUserPassword(id: number, password = '') {
  return request.put<unknown, { password: string }>(`/admin/users/${id}/password/reset`, {
    password
  })
}

/**
 * 导入用户
 *
 * 不设 Content-Type —— 交给浏览器自己带上 multipart 的 boundary，
 * 手写会漏掉 boundary 导致后端解析不出文件。
 */
export function importUsers(file: File) {
  const form = new FormData()
  form.append('file', file)

  return request.post<unknown, BatchOutcome>('/admin/users/import', form)
}

/**
 * 发起导出用户
 *
 * 返回**任务回执**（202 + `{task_id, message}`），不是文件：文件由队列生成，
 * 用户到「数据管理 / 数据导出」下载。原先页面直接调 `download()` 拿 blob，
 * 改异步后那样会拿到一段 JSON。
 */
export function exportUsers(params: Record<string, unknown>) {
  return request.get<unknown, ExportAccepted>('/admin/users/export', { params })
}
