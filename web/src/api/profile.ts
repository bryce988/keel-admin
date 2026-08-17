import request from '@/utils/request'

/** 个人中心。完整的资料/安全/通知偏好在 M2 的个人中心模块补齐 */

export interface ChangePasswordPayload {
  old_password: string
  new_password: string
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
