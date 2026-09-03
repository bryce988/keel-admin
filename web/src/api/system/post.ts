import request from '@/utils/request'
import type { BatchOutcome, PageResult, TableQuery } from '@/types/api'

/** 岗位接口 */

export interface PostRow {
  id: number
  name: string
  code: string
  dept_id: number
  dept_name: string
  default_role_id: number
  sort: number
  status: number
  remark: string
  created_at: string
}

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type PostPayload = Partial<PostRow>

/** 岗位下拉选项，带 default_role_id 供用户表单预填角色 */
export interface PostOption {
  id: number
  name: string
  code: string
  dept_id: number
  default_role_id: number
}

export function fetchPosts(params: TableQuery) {
  return request.get<unknown, PageResult<PostRow>>('/admin/posts', { params })
}

export function fetchPostOptions() {
  return request.get<unknown, PostOption[]>('/admin/posts/options')
}

export function createPost(data: PostPayload) {
  return request.post<unknown, PostRow>('/admin/posts', data)
}

export function updatePost(id: number, data: PostPayload) {
  return request.put<unknown, PostRow>(`/admin/posts/${id}`, data)
}

export function deletePost(id: number) {
  return request.delete<unknown, void>(`/admin/posts/${id}`)
}

export function batchDeletePosts(ids: number[]) {
  return request.post<unknown, BatchOutcome>('/admin/posts/batch-delete', { ids })
}
