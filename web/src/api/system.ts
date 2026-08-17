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
  realName: string
  avatar: string
  phone: string
  email: string
  deptId: number
  deptName: string
  postName: string
  status: number
  isSuper: boolean
  lastLoginAt: string | null
  createdAt: string
}

export interface DeptNode {
  id: number
  parentId: number
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
