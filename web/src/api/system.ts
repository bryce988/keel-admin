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
  sort: number
  children?: DeptNode[]
}

export function fetchUsers(params: TableQuery) {
  return request.get<unknown, PageResult<UserRow>>('/admin/users', { params })
}

export function fetchDeptTree() {
  return request.get<unknown, DeptNode[]>('/admin/depts/tree')
}
