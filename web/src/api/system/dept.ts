import request from '@/utils/request'

/** 部门接口。树形结构，没有分页 */

export interface DeptNode {
  id: number
  parent_id: number
  name: string
  code: string
  leader_id: number
  sort: number
  status: number
  user_count: number
  created_at: string
  children?: DeptNode[]
}

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type DeptPayload = Partial<DeptNode>

export function fetchDeptTree(params?: Record<string, unknown>) {
  return request.get<unknown, DeptNode[]>('/admin/depts/tree', { params })
}

export function createDept(data: DeptPayload) {
  return request.post<unknown, DeptNode>('/admin/depts', data)
}

export function updateDept(id: number, data: DeptPayload) {
  return request.put<unknown, DeptNode>(`/admin/depts/${id}`, data)
}

export function deleteDept(id: number) {
  return request.delete<unknown, void>(`/admin/depts/${id}`)
}
