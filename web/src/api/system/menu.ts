import request from '@/utils/request'

/** 菜单与权限点接口。菜单树同时也是权限树，type 区分二者 */

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

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type MenuPayload = Partial<MenuNodeRow>

export function fetchMenuTree(params?: Record<string, unknown>) {
  return request.get<unknown, MenuNodeRow[]>('/admin/menus/tree', { params })
}

export function createMenu(data: MenuPayload) {
  return request.post<unknown, MenuNodeRow>('/admin/menus', data)
}

export function updateMenu(id: number, data: MenuPayload) {
  return request.put<unknown, MenuNodeRow>(`/admin/menus/${id}`, data)
}

export function deleteMenu(id: number) {
  return request.delete<unknown, void>(`/admin/menus/${id}`)
}
