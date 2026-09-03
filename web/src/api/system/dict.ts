import request from '@/utils/request'
import type { BatchOutcome, PageResult, TableQuery } from '@/types/api'

/** 数据字典接口。前台取选项用 `stores/dict.ts`，这里是维护界面用的 */

export interface DictTypeRow {
  id: number
  name: string
  code: string
  status: number
  remark: string
  item_count: number
  created_at: string
}

/**
 * 字典项的标签色
 *
 * 取值就是 Element Plus 的 tag type 加一个空串（空 = 默认灰）。
 * 原来这里是 `string`，`<el-tag :type="row.tag_type">` 一直没报错只是因为
 * el-tag 当时没类型；接了按需导入之后 TS 立刻指出 string 不能赋给这个联合。
 * 写死成 string 的问题不只是类型松：后端存进一个拼错的 "sucess"，
 * 界面上只是标签变成默认灰，没有任何地方会告诉你写错了。
 */
export type DictTagType = '' | 'primary' | 'success' | 'warning' | 'info' | 'danger'

export interface DictItemRow {
  id: number
  type_code: string
  label: string
  value: string
  tag_type: DictTagType
  sort: number
  status: number
  remark: string
  /** 被业务数据引用的行数，大于 0 时「值」不可改也不可删 */
  ref_count: number
  created_at: string
}

/** 写接口请求体，取法同 `UserPayload`（说明见 user.ts） */
export type DictTypePayload = Partial<DictTypeRow>
export type DictItemPayload = Partial<DictItemRow>

export function fetchDictTypes(params: TableQuery) {
  return request.get<unknown, PageResult<DictTypeRow>>('/admin/dicts', { params })
}

export function createDictType(data: DictTypePayload) {
  return request.post<unknown, DictTypeRow>('/admin/dicts', data)
}

export function updateDictType(id: number, data: DictTypePayload) {
  return request.put<unknown, DictTypeRow>(`/admin/dicts/${id}`, data)
}

export function deleteDictType(id: number) {
  return request.delete<unknown, void>(`/admin/dicts/${id}`)
}

/** 维护界面用：含停用项，带 ref_count。前台取选项用 stores/dict.ts */
export function fetchDictItems(code: string, params: TableQuery) {
  return request.get<unknown, PageResult<DictItemRow>>(`/admin/dicts/${code}/items/all`, { params })
}

export function createDictItem(data: DictItemPayload) {
  return request.post<unknown, DictItemRow>('/admin/dict-items', data)
}

export function updateDictItem(id: number, data: DictItemPayload) {
  return request.put<unknown, DictItemRow>(`/admin/dict-items/${id}`, data)
}

export function deleteDictItem(id: number) {
  return request.delete<unknown, void>(`/admin/dict-items/${id}`)
}

export function batchDeleteDictItems(ids: number[]) {
  return request.post<unknown, BatchOutcome>('/admin/dict-items/batch-delete', { ids })
}
