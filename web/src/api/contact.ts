import request from '@/utils/request'
import type { PageResult } from '@/types/api'

/**
 * 通讯录
 *
 * **全员可见的组织架构只读视图**，与用户管理是两套接口：
 * 那边挂 `sys:user:*` 且受数据权限约束（管理能力），这边挂 `contact:view`
 * 且绕开数据权限（找人）。普通员工没有 `sys:user:list`，却必须能查到
 * 别的部门同事的分机号。
 *
 * ⚠️ 数据范围放开不等于字段放开：手机号与邮箱仍受 `sys:field:user:*` 管，
 * 没授权的拿到的是掩码（`138****8001`）而不是空——界面直接展示即可，
 * 不要再判断「是不是掩码」去做特殊处理。
 */

export interface ContactDept {
  id: number
  name: string
  children: ContactDept[]
}

export interface ContactPerson {
  id: number
  username: string
  real_name: string
  avatar: string
  /** 无字段权限时是掩码 */
  phone: string
  /** 无字段权限时是掩码 */
  email: string
  dept_id: number
  dept_name: string
  post_name: string
}

/** 全公司的部门树，不受数据权限约束 */
export function getContactDepts() {
  return request.get<unknown, ContactDept[]>('/admin/contacts/depts')
}

/**
 * 人员列表
 *
 * @param include_children 默认含子部门（平铺列表用）；树形展示传 false，
 *   否则子部门的人会在父节点下再出现一次
 */
export function getContacts(params: {
  dept_id?: number
  include_children?: boolean
  keyword?: string
  page_num?: number
  page_size?: number
}) {
  return request.get<unknown, PageResult<ContactPerson>>('/admin/contacts', { params })
}

export function getContact(id: number) {
  return request.get<unknown, ContactPerson>(`/admin/contacts/${id}`)
}
