import request from '@/utils/request'
import type { PageResult, TableQuery } from '@/types/api'
import type { UserRow } from './user'

/** 角色接口。含授权（权限点 / 数据范围 / 互斥）与成员维护 */

export interface RoleRow {
  id: number
  name: string
  code: string
  parent_id: number
  data_scope: number
  is_builtin: boolean
  sort: number
  status: number
  remark: string
  member_count: number
  created_at: string
}

export interface RoleDetail extends RoleRow {
  permission_ids: number[]
  dept_ids: number[]
  mutex_ids: number[]
  /** 从父角色继承来的权限点，前端置灰不可取消 */
  inherited_ids: number[]
}

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type RolePayload = Partial<RoleRow>

export function fetchRoles(params: TableQuery) {
  return request.get<unknown, PageResult<RoleRow>>('/admin/roles', { params })
}

export function fetchRoleOptions() {
  return request.get<unknown, Array<{ id: number; name: string; code: string; data_scope: number }>>(
    '/admin/roles/options'
  )
}

export function fetchRole(id: number) {
  return request.get<unknown, RoleDetail>(`/admin/roles/${id}`)
}

export function createRole(data: RolePayload) {
  return request.post<unknown, RoleRow>('/admin/roles', data)
}

export function updateRole(id: number, data: RolePayload) {
  return request.put<unknown, RoleRow>(`/admin/roles/${id}`, data)
}

export function deleteRole(id: number) {
  return request.delete<unknown, void>(`/admin/roles/${id}`)
}

export function grantRolePermissions(id: number, permission_ids: number[]) {
  return request.put<unknown, void>(`/admin/roles/${id}/permissions`, { permission_ids })
}

export function grantRoleDataScope(id: number, data_scope: number, dept_ids: number[]) {
  return request.put<unknown, void>(`/admin/roles/${id}/data-scope`, { data_scope, dept_ids })
}

export function saveRoleMutexes(id: number, mutex_ids: number[]) {
  return request.put<unknown, void>(`/admin/roles/${id}/mutexes`, { mutex_ids })
}

export function fetchRoleMembers(id: number, params: TableQuery) {
  return request.get<unknown, PageResult<UserRow>>(`/admin/roles/${id}/members`, { params })
}

export function addRoleMembers(id: number, user_ids: number[]) {
  return request.post<unknown, void>(`/admin/roles/${id}/members`, { user_ids })
}

export function removeRoleMember(id: number, userId: number) {
  return request.delete<unknown, void>(`/admin/roles/${id}/members/${userId}`)
}
