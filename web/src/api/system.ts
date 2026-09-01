import request from '@/utils/request'
import type { ExportAccepted } from '@/api/export'
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
export interface UserDetail extends UserRow {
  post_id: number
  remark: string
  role_ids: number[]
}

/**
 * 写接口的请求体类型
 * =================
 *
 * 原来这些函数的入参一律是 `Record<string, unknown>`，等于放弃了类型：
 * 表单里把 `real_name` 打成 `realname`，前端一路绿灯，直到接口 422 才知道。
 *
 * 一律取 `Partial<行类型>`，而不是 `Partial<Omit<行类型, 只读字段>>`：
 * 同一个 `<FormDrawer>` 既做新增/编辑也做详情（`mode: 'view'`），
 * 详情模板要读 `created_at`、`is_builtin` 这些只读字段。把它们 Omit 掉，
 * 编译期是干净了，代价是详情页取不到值——收紧类型不该把功能改窄。
 *
 * 只读字段传给后端是无害的：写接口都走 `StoreXxxRequest::validated()` 白名单，
 * 不在白名单里的键根本到不了 service 层。
 *
 * 用 `Partial<行类型>` 而不是另写一份字段清单，是为了让它跟着行类型自动同步——
 * 另写一份的话两边迟早对不上，而对不上的那天不会有任何报错。
 */
export type UserPayload = Partial<UserDetail> & {
  /** 只在新增时出现，编辑表单里没有这一项 */
  password?: string
}

export type DeptPayload = Partial<DeptNode>
export type PostPayload = Partial<PostRow>
export type MenuPayload = Partial<MenuNodeRow>
export type RolePayload = Partial<RoleRow>
export type DictTypePayload = Partial<DictTypeRow>
export type DictItemPayload = Partial<DictItemRow>
export type ParamPayload = Partial<ParamRow>

export function fetchUsers(params: TableQuery) {
  return request.get<unknown, PageResult<UserRow>>('/admin/users', { params })
}

export function fetchUser(id: number) {
  return request.get<unknown, UserDetail>(`/admin/users/${id}`)
}

/** 新建成功时返回 initial_password —— 只有这一次能拿到明文 */
export function createUser(data: UserPayload) {
  return request.post<unknown, UserDetail & { initial_password: string }>('/admin/users', data)
}

export function updateUser(id: number, data: UserPayload) {
  return request.put<unknown, UserDetail>(`/admin/users/${id}`, data)
}

export function deleteUser(id: number) {
  return request.delete<unknown, void>(`/admin/users/${id}`)
}

export function setUserStatus(id: number, status: number) {
  return request.put<unknown, void>(`/admin/users/${id}/status`, { status })
}

export function grantUserRoles(id: number, role_ids: number[]) {
  return request.put<unknown, void>(`/admin/users/${id}/roles`, { role_ids })
}

export function resetUserPassword(id: number, password = '') {
  return request.put<unknown, { password: string }>(`/admin/users/${id}/password/reset`, {
    password
  })
}

/**
 * 导入用户
 *
 * 不设 Content-Type —— 交给浏览器自己带上 multipart 的 boundary，
 * 手写会漏掉 boundary 导致后端解析不出文件。
 */
export function importUsers(file: File) {
  const form = new FormData()
  form.append('file', file)

  return request.post<unknown, BatchOutcome>('/admin/users/import', form)
}

// ---------------------------------------------------------------- 部门
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

// ---------------------------------------------------------------- 岗位
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

/**
 * 发起导出用户
 *
 * 返回**任务回执**（202 + `{task_id, message}`），不是文件：文件由队列生成，
 * 用户到「数据管理 / 数据导出」下载。原先页面直接调 `download()` 拿 blob，
 * 改异步后那样会拿到一段 JSON。
 */
export function exportUsers(params: Record<string, unknown>) {
  return request.get<unknown, ExportAccepted>('/admin/users/export', { params })
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

export interface BatchOutcome {
  success_count: number
  fail_count: number
  succeeded: number[]
  failed: Array<{ id: number; reason: string }>
}

export function batchDeletePosts(ids: number[]) {
  return request.post<unknown, BatchOutcome>('/admin/posts/batch-delete', { ids })
}

// ---------------------------------------------------------------- 数据字典
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

// ---------------------------------------------------------------- 参数配置
export interface ParamRow {
  id: number
  group: string
  name: string
  param_key: string
  /** is_secret 为真时后端一律回 '******'，提交时原样送回即表示不修改 */
  param_value: string
  value_type: 'string' | 'int' | 'bool' | 'json'
  is_builtin: boolean
  is_secret: boolean
  remark: string
  updated_at: string
}

/** 密钥掩码，与后端 ParamService::MASK 一致 */
export const PARAM_MASK = '******'

/**
 * 登录页要用的少量参数（系统名、Logo、页脚）
 *
 * 免登录接口，白名单在后端 `ParamService::PUBLIC_KEYS` 里，加键要改那一处。
 * 键名带点，是数据库里的 `param_key` 原样下发，不做驼峰转换（全链路 snake/原样）。
 */
export interface PublicParams {
  'sys.name'?: string
  'sys.logo'?: string
  'sys.footer'?: string
}

export function fetchPublicParams() {
  return request.get<unknown, PublicParams>('/admin/params/public')
}

export function fetchParamGroups() {
  return request.get<unknown, Array<{ code: string; name: string }>>('/admin/params/groups')
}

export function fetchParams(group: string) {
  return request.get<unknown, ParamRow[]>('/admin/params', { params: { group } })
}

export function saveParams(items: Array<{ param_key: string; param_value: string }>) {
  return request.put<unknown, { saved_count: number }>('/admin/params', { items })
}

export function createParam(data: ParamPayload) {
  return request.post<unknown, ParamRow>('/admin/params', data)
}

export function updateParam(id: number, data: ParamPayload) {
  return request.put<unknown, ParamRow>(`/admin/params/${id}`, data)
}

export function deleteParam(id: number) {
  return request.delete<unknown, void>(`/admin/params/${id}`)
}
