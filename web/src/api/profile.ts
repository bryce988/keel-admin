import request from '@/utils/request'
import type { PageResult } from '@/types/api'

/**
 * 个人中心
 *
 * 这些接口全部只作用于当前登录用户，没有一个接受 id 参数——
 * 服务端从令牌取身份（docs/api.md §11）。要改别人走 `api/system.ts` 的用户管理，
 * 那边每个入口都要过权限点。两套东西分文件放，就是为了不让人接错。
 */

export interface ProfileInfo {
  id: number
  username: string
  real_name: string
  avatar: string
  /** 已脱敏，形如 138****8000；未填写时是空串 */
  phone: string
  email: string
  dept_name: string
  post_name: string
  roles: string[]
  status: number
  is_super: boolean
  pwd_updated_at: string | null
  last_login_at: string | null
  last_login_ip: string
  created_at: string | null
}

export interface MyLoginRow {
  id: number
  ip: string
  location: string
  browser: string
  os: string
  type: number
  status: number
  msg: string
  created_at: string | null
}

export interface ChangePasswordPayload {
  old_password: string
  new_password: string
}

export function fetchProfile() {
  return request.get<unknown, ProfileInfo>('/admin/profile')
}

/** 只能改这三项；后端也是白名单，前端多传的字段会被忽略而不是报错 */
export function updateProfile(payload: { real_name: string; email: string; avatar?: string }) {
  return request.put<unknown, ProfileInfo>('/admin/profile', payload)
}

/**
 * 换头像
 *
 * 一步到位：上传成功后端就已经写库了，返回的 avatar 就是最终地址，
 * **不需要再调 updateProfile**（docs/api.md §11.1）。
 *
 * 不设 Content-Type：交给浏览器自己带 multipart 的 boundary，手写会漏掉它，
 * 后端解析不出文件，表现为「明明选了图却提示请选择图片」。
 */
export function uploadAvatar(file: File) {
  const form = new FormData()
  form.append('file', file)

  return request.post<unknown, { avatar: string }>('/admin/profile/avatar', form)
}

/** 换绑手机：用当前密码验证身份，不走短信（脚手架不绑死短信服务商） */
export function changePhone(payload: { phone: string; password: string }) {
  return request.put<unknown, void>('/admin/profile/phone', payload)
}

export function fetchMyLogins(params: Record<string, unknown>) {
  return request.get<unknown, PageResult<MyLoginRow>>('/admin/profile/logins', { params })
}

/**
 * 修改密码
 *
 * ⚠️ 服务端在成功后会把当前 token 拉黑（`JwtService::revoke`），
 * 所以调用方必须紧接着清理登录态并跳登录页，否则下一个请求才 401、体验很怪。
 */
export function changePassword(payload: ChangePasswordPayload) {
  return request.put<unknown, void>('/admin/profile/password', payload)
}
