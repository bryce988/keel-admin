/**
 * 接口定义
 *
 * 只放请求，不放业务判断——与 web/ 的 `api/*.ts` 同一条约定。
 *
 * 打的是**员工移动端自己的一套** `/staff/v1/*`（契约见 docs/api.md §13），
 * 不是后台的 `/admin/*`：身份共用，接口不共用。两个直接好处在这里就能看到——
 * 登录一次拿回令牌与身份、工作台一次拿回身份与概览，后台那边分别是两次和三次请求。
 */
import { request, upload, setToken, setRefreshToken, cacheUser, cachePermissions, clearAuth } from './request.js'

/** 图形验证码：返回 { captcha_key, captcha_image }，image 是 svg 的 data URI */
export function fetchCaptcha() {
	return request('/staff/v1/auth/captcha', 'GET', null, false)
}

/**
 * 登录
 *
 * 一次返回令牌 + 身份 + 权限点，不用再补一次「我是谁」的请求。
 * 权限点缓存下来给界面用（哪些区块显示得出来）。
 */
export async function login(username, password, captchaKey, captchaCode) {
	const res = await request('/staff/v1/auth/login', 'POST', {
		username,
		password,
		captcha_key: captchaKey,
		captcha_code: captchaCode
	}, false)

	setToken(res.access_token)
	// 存下 refresh：access 只有 2 小时，靠它才能在 7 天内免登录续期
	setRefreshToken(res.refresh_token)
	cacheUser(res.user)
	cachePermissions(res.permissions)
	return res
}

export async function logout() {
	try {
		await request('/staff/v1/auth/logout', 'POST')
	} finally {
		// 接口失败也照样清本地：留着一个已吊销的令牌没有任何好处
		clearAuth()
	}
}

/**
 * 工作台聚合：身份 + 权限点 + 概览
 *
 * `dashboard.visible` 是**服务端**算的，客户端不拿缓存的权限点自己判断——
 * 权限是登录那一刻的快照，撤权之后本地还以为有，界面上就是一块永远加载失败的区域。
 */
export async function fetchWorkbench() {
	const res = await request('/staff/v1/workbench')
	cacheUser(res.user)
	cachePermissions(res.permissions)
	return res
}

/**
 * 消息列表
 *
 * 分页体里额外带 `unread_count`——列表与角标在界面上是同一件事的两面，
 * 拆成两个接口会出现「角标 3、点进去只有 2 条未读」的错位。
 */
export function fetchNotices(pageNum = 1, pageSize = 20) {
	return request(`/staff/v1/notices?page_num=${pageNum}&page_size=${pageSize}`)
}

/** 读一条：返回正文，**同时**在服务端落已读回执，不需要再调一次标记已读 */
export function readNotice(id) {
	return request('/staff/v1/notices/' + id)
}

export function readAllNotices() {
	return request('/staff/v1/notices/read-all', 'POST')
}

/**
 * 未读角标
 *
 * tabBar 的下标：0 首页 · 1 消息 · 2 我的。
 * 0 要用 removeTabBarBadge 而不是 setTabBarBadge('0')——后者会显示一个「0」，
 * 看起来像是有一条编号为 0 的消息。
 */
export function setNoticeBadge(count) {
	if (count > 0) {
		uni.setTabBarBadge({ index: 1, text: count > 99 ? '99+' : String(count) })
	} else {
		uni.removeTabBarBadge({ index: 1 })
	}
}

export function fetchProfile() {
	return request('/staff/v1/profile')
}

export function updateProfile(data) {
	return request('/staff/v1/profile', 'PUT', data)
}

/** 换头像：一步到位，上传成功即写库，返回的 avatar 是绝对地址 */
export function uploadAvatar(filePath) {
	return upload('/staff/v1/profile/avatar', filePath)
}

// ---------------------------------------------------------------- 即时通讯
//
// 打的是 /staff/v1/chat/*，但服务端调的是**与后台同一份** ChatService
// （PROJECT.md §8.2「一个业务规则只有一份实现」）。所以手机发的消息，
// 电脑端能实时收到，两边的顺序、幂等、可见性判定完全一致。
//
// 发消息走 HTTP，长连接只负责收（见 chatSocket.js）。

/** 可发起会话的在职员工 */
export function fetchChatContacts(keyword = '') {
	return request(`/staff/v1/chat/contacts?keyword=${encodeURIComponent(keyword)}&limit=30`)
}

/** 打开与某人的单聊。已存在就返回已有的，不会重复创建 */
export function openChatConversation(userId) {
	return request('/staff/v1/chat/conversations', 'POST', { user_id: userId })
}

/**
 * 历史消息，返回永远按 seq 正序
 *
 * before_seq 向上翻历史，after_seq 断线重连后补空洞。
 * 用游标不用页码：消息表会很大，深页 offset 要扫过前面所有行。
 */
export function fetchChatMessages(convId, params = {}) {
	const qs = Object.entries(params)
		.filter(([, v]) => v !== undefined && v !== null)
		.map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
		.join('&')

	return request(`/staff/v1/chat/conversations/${convId}/messages${qs ? '?' + qs : ''}`)
}

/** 发消息。client_msg_id 用于幂等——超时重发不会产生两条 */
export function sendChatMessage(convId, payload) {
	return request(`/staff/v1/chat/conversations/${convId}/messages`, 'POST', payload)
}

export function markChatRead(convId, lastReadSeq) {
	return request(`/staff/v1/chat/conversations/${convId}/read`, 'POST', { last_read_seq: lastReadSeq })
}
