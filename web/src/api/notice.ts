import request from '@/utils/request'
import type { PageResult, TableQuery } from '@/components'

/**
 * 系统公告
 *
 * 两组接口，权限口径完全不同，别接错（后端 `NoticeController` 顶部有对照表）：
 *
 * - `/admin/notices*`   管理端，要 `sys:notice:*`，看得到草稿
 * - `/admin/my/notices*` 接收端，登录即可，只看得到已发布的
 *
 * 铃铛用的是后者——它必须对**每个**登录用户都可用，
 * 挂权限点等于「没被授权的人收不到全员通知」。
 */

// ---------------------------------------------------------------- 管理端

export interface NoticeRow {
  id: number
  title: string
  /** 60 字摘要，正文要看详情 */
  summary: string
  type: string
  /** 0 草稿 · 1 已发布 */
  status: number
  published_at: string | null
  publisher_name: string
  read_count: number
  created_at: string
}

export interface NoticeDetail extends Omit<NoticeRow, 'summary' | 'read_count'> {
  content: string
}

export interface NoticePayload {
  title: string
  content: string
  type: string
  status: number
}

export function fetchNotices(params: TableQuery) {
  return request.get<unknown, PageResult<NoticeRow>>('/admin/notices', { params })
}

export function fetchNotice(id: number) {
  return request.get<unknown, NoticeDetail>(`/admin/notices/${id}`)
}

export function createNotice(data: NoticePayload) {
  return request.post<unknown, NoticeDetail>('/admin/notices', data)
}

export function updateNotice(id: number, data: NoticePayload) {
  return request.put<unknown, NoticeDetail>(`/admin/notices/${id}`, data)
}

/** 发布：幂等，已发布的再点一次不会刷新发布时间 */
export function publishNotice(id: number) {
  return request.post<unknown, NoticeDetail>(`/admin/notices/${id}/publish`)
}

/** 撤回到草稿，已读回执保留 */
export function revokeNotice(id: number) {
  return request.post<unknown, NoticeDetail>(`/admin/notices/${id}/revoke`)
}

export function deleteNotice(id: number) {
  return request.delete<unknown, void>(`/admin/notices/${id}`)
}

export function batchDeleteNotices(ids: number[]) {
  return request.post<unknown, { total: number; success_count: number; fail_count: number; failed: { id: number; reason: string }[] }>(
    '/admin/notices/batch-delete',
    { ids }
  )
}

// ---------------------------------------------------------------- 接收端（铃铛）

export interface BellNotice {
  id: number
  title: string
  summary: string
  type: string
  published_at: string | null
  publisher_name: string
  is_read: boolean
}

export interface BellPayload {
  unread_count: number
  /**
   * 最新一条未读的 id，0 表示没有未读
   *
   * 判断「有没有新消息」要用它而不是数量：读掉一条、同时又发来一条时数量不变，
   * 只看数量就不会弹提示。
   */
  latest_id: number
  latest_title: string
  list: BellNotice[]
}

export function fetchBell() {
  return request.get<unknown, BellPayload>('/admin/my/notices')
}

/** 读一条：返回正文，服务端顺带落已读回执（读和标已读是同一个动作） */
export function readNotice(id: number) {
  return request.get<unknown, NoticeDetail>(`/admin/my/notices/${id}`)
}

export function readAllNotices() {
  return request.post<unknown, { count: number }>('/admin/my/notices/read-all')
}
