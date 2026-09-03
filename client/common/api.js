/**
 * 接口定义
 *
 * 只放请求，不放业务判断——与 web/ 的 `api/*.ts` 同一条约定。
 * 契约见 docs/api.md §12.3。
 */
import { request, upload, setToken, cacheUser, clearAuth } from './request.js'

/** 登录成功即写入令牌与用户缓存，页面不用各自记得存 */
export async function login(phone, password) {
	const res = await request('/client/v1/auth/login', 'POST', { phone, password }, false)
	setToken(res.access_token)
	cacheUser(res.user)
	return res
}

/**
 * 退出登录
 *
 * 接口失败也照样清本地：本地留着一个已经吊销的令牌没有任何好处，
 * 下一个接口一样是 401。
 */
export async function logout() {
	try {
		await request('/client/v1/auth/logout', 'POST')
	} finally {
		clearAuth()
	}
}

export function fetchProfile() {
	return request('/client/v1/profile')
}

export async function updateNickname(nickname) {
	const user = await request('/client/v1/profile', 'PUT', { nickname })
	cacheUser(user)
	return user
}

export async function uploadAvatar(filePath) {
	const user = await upload('/client/v1/profile/avatar', filePath)
	cacheUser(user)
	return user
}
