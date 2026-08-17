import request from '@/utils/request'
import type { PageResult, TableQuery } from '@/components'

/**
 * 系统管理接口
 *
 * 页面不直接拼 URL：接口路径与响应类型集中在这里，
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

// ---------------------------------------------------------------- 用户
export function fetchUsers(params: TableQuery) {
  return request.get<unknown, PageResult<UserRow>>('/admin/users', { params })
}

// ---------------------------------------------------------------- 部门
export function fetchDeptTree(params?: Record<string, unknown>) {
  return request.get<unknown, DeptNode[]>('/admin/depts/tree', { params })
}

export function createDept(data: Record<string, unknown>) {
  return request.post<unknown, DeptNode>('/admin/depts', data)
}

export function updateDept(id: number, data: Record<string, unknown>) {
  return request.put<unknown, DeptNode>(`/admin/depts/${id}`, data)
}

export function deleteDept(id: number) {
  return request.delete<unknown, void>(`/admin/depts/${id}`)
}

// ---------------------------------------------------------------- 岗位
export function fetchPosts(params: TableQuery) {
  return request.get<unknown, PageResult<PostRow>>('/admin/posts', { params })
}

export function createPost(data: Record<string, unknown>) {
  return request.post<unknown, PostRow>('/admin/posts', data)
}

export function updatePost(id: number, data: Record<string, unknown>) {
  return request.put<unknown, PostRow>(`/admin/posts/${id}`, data)
}

export function deletePost(id: number) {
  return request.delete<unknown, void>(`/admin/posts/${id}`)
}

// ---------------------------------------------------------------- 菜单与权限点
/** 1 目录 · 2 菜单 · 3 按钮 · 4 接口 · 5 数据(字段) */
export type MenuType = 1 | 2 | 3 | 4 | 5

export interface MenuNodeRow {
  id: number
  parent_id: number
  name: string
  type: MenuType
  perm_code: string
  path: string
  component: string
  icon: string
  api_method: string
  api_path: string
  visible: boolean
  keep_alive: boolean
  sort: number
  status: number
  children?: MenuNodeRow[]
}

export interface PermissionMatrix {
  roles: Array<{ id: number; name: string; code: string; is_builtin: boolean }>
  /** permission_id → 拥有它的角色 id 列表 */
  granted: Record<string, number[]>
  tree: MenuNodeRow[]
}

export function fetchMenuTree(params?: Record<string, unknown>) {
  return request.get<unknown, MenuNodeRow[]>('/admin/menus/tree', { params })
}

export function fetchPermissionMatrix() {
  return request.get<unknown, PermissionMatrix>('/admin/menus/matrix')
}

export function createMenu(data: Record<string, unknown>) {
  return request.post<unknown, MenuNodeRow>('/admin/menus', data)
}

export function updateMenu(id: number, data: Record<string, unknown>) {
  return request.put<unknown, MenuNodeRow>(`/admin/menus/${id}`, data)
}

export function deleteMenu(id: number) {
  return request.delete<unknown, void>(`/admin/menus/${id}`)
}

// ---------------------------------------------------------------- 角色
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

export function createRole(data: Record<string, unknown>) {
  return request.post<unknown, RoleRow>('/admin/roles', data)
}

export function updateRole(id: number, data: Record<string, unknown>) {
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

export interface BatchOutcome {
  success_count: number
  fail_count: number
  succeeded: number[]
  failed: Array<{ id: number; reason: string }>
}

export function batchDeletePosts(ids: number[]) {
  return request.post<unknown, BatchOutcome>('/admin/posts/batch-delete', { ids })
}
