/**
 * 请求层
 *
 * 页面不直接调 uni.request：地址、令牌、401 处理集中在这里。
 * 这是 web/ 那边 `utils/request.ts` 的同款约定，两点不同：
 * - 没有 axios，用 uni.request 包一层 Promise
 * - 令牌存 uni.getStorageSync 而不是 localStorage，App 上没有后者
 */
import { BASE_URL, CHANNEL, APP_VERSION } from './config.js'

const TOKEN_KEY = 'keel_admin_token'
const USER_KEY = 'keel_admin_user'
const PERM_KEY = 'keel_admin_perms'

export function getToken() {
	return uni.getStorageSync(TOKEN_KEY) || ''
}

export function setToken(token) {
	uni.setStorageSync(TOKEN_KEY, token)
}

export function clearAuth() {
	uni.removeStorageSync(TOKEN_KEY)
	uni.removeStorageSync(USER_KEY)
	uni.removeStorageSync(PERM_KEY)
}

export function getCachedUser() {
	return uni.getStorageSync(USER_KEY) || null
}

export function cacheUser(user) {
	uni.setStorageSync(USER_KEY, user)
}

export function cachePermissions(list) {
	uni.setStorageSync(PERM_KEY, list || [])
}

/**
 * 有没有某个权限点
 *
 * 与 web 端 store 的 `can()` 同义：超管的权限列表是 `['*']`。
 * ⚠️ 和 `v-permission` 一样，这**只是界面收敛，不是安全边界**——
 * 真正的拦截在后端路由的 perm 声明上。少判一处顶多是多显示一个按钮，
 * 点下去照样 403；但如果指望它当权限用，那就是把门锁挂在了门帘上。
 */
export function can(perm) {
	if (!perm) return true
	const list = uni.getStorageSync(PERM_KEY) || []
	return list.indexOf('*') !== -1 || list.indexOf(perm) !== -1
}

/**
 * 设备号
 *
 * 首次取到就存下来：deviceId 在应用重装后会变，但同一次安装内稳定，
 * 够用来做「一台设备」的口径。后端的限流按它算而不是按 IP——
 * 移动网络下大量用户共用出口 IP，按 IP 限流会误伤一整片人。
 */
function deviceId() {
	let id = uni.getStorageSync('keel_device')
	if (!id) {
		const info = uni.getSystemInfoSync()
		id = info.deviceId || ('dev-' + Date.now())
		uni.setStorageSync('keel_device', id)
	}
	return id
}

/** 每个请求都要带的头。渠道三件套是 staff 端的硬性要求，缺一个就是 400 */
function headers(withToken) {
	const h = {
		'Content-Type': 'application/json',
		'X-Channel': CHANNEL,
		'X-App-Version': APP_VERSION,
		'X-Device-Id': deviceId()
	}
	if (withToken) {
		h['Authorization'] = 'Bearer ' + getToken()
	}
	return h
}

/**
 * 相对路径补成绝对地址
 *
 * `/staff/v1/*` 下发的头像已经是绝对地址（服务端用 APP_URL 拼的），
 * 这里是**兜底**：部署方没配 APP_URL 时后端会原样下发相对路径，
 * 补一次总比显示不出来强。已经是 http 开头的原样返回，重复调用无害。
 */
export function absUrl(path) {
	if (!path) return ''
	if (path.indexOf('http') === 0) return path
	return BASE_URL + path
}

/**
 * 令牌失效的统一出口
 *
 * 401 有三种来源：令牌过期、被改密顶掉、账号被停用。三种都只有一条出路——
 * 回登录页，所以不区分。用 reLaunch 清页面栈，否则按返回键又回到要登录才能看的页面。
 */
function onUnauthorized(message) {
	clearAuth()
	uni.showToast({ title: message || '登录已失效', icon: 'none' })
	uni.reLaunch({ url: '/pages/login/login' })
}

/**
 * 发一个请求
 *
 * **成功回调里也要判状态码**：uni.request 的 fail 只在网络层出错时触发，
 * 后端返回 400/401/403/500 一律走 success，statusCode 才是真正的成败依据。
 * 这与 web 端「先按 HTTP 状态码分派，再按业务码细化」是同一条约定。
 *
 * reject 出去的永远是 `{ code, message }`，与后端管理端错误体同构
 * （那边还有个 trace_id，排查时能对上服务端日志，这里按需再取）。
 */
export function request(path, method = 'GET', data = null, withToken = true) {
	return new Promise((resolve, reject) => {
		uni.request({
			url: BASE_URL + path,
			method,
			data: data || {},
			header: headers(withToken),
			success: (res) => {
				const status = res.statusCode
				if (status >= 200 && status < 300) {
					resolve(res.data)
					return
				}

				const body = res.data || {}
				const err = {
					code: body.code || status,
					message: body.message || `请求失败（${status}）`,
					trace_id: body.trace_id || '',
					details: body.details || null
				}

				if (status === 401) {
					onUnauthorized(err.message)
				}
				reject(err)
			},
			fail: (e) => {
				// 到不了服务器：地址写错、手机没网、真机连的是 localhost
				reject({ code: -1, message: '网络异常，请检查网络或后端地址（' + (e.errMsg || '') + '）' })
			}
		})
	})
}

/**
 * 上传文件
 *
 * 走 uni.uploadFile 而不是 request：multipart 要由框架拼 boundary，
 * 手写 Content-Type 会漏掉它，后端解析不出文件。
 * 另一个坑：uploadFile 回来的 data 是**字符串**，要自己 JSON.parse。
 */
export function upload(path, filePath, name = 'file') {
	return new Promise((resolve, reject) => {
		uni.uploadFile({
			url: BASE_URL + path,
			filePath,
			name,
			// 不设 Content-Type：交给框架自己带 multipart 的 boundary，手写会漏掉它
			header: {
				'X-Channel': CHANNEL,
				'X-App-Version': APP_VERSION,
				'X-Device-Id': deviceId(),
				Authorization: 'Bearer ' + getToken()
			},
			success: (res) => {
				let body = {}
				try {
					body = JSON.parse(res.data || '{}')
				} catch (e) {
					reject({ code: -1, message: '返回内容解析失败' })
					return
				}

				if (res.statusCode >= 200 && res.statusCode < 300) {
					resolve(body)
					return
				}
				if (res.statusCode === 401) {
					onUnauthorized(body.message)
				}
				reject({ code: body.code || res.statusCode, message: body.message || '上传失败' })
			},
			fail: (e) => {
				reject({ code: -1, message: '上传失败：' + (e.errMsg || '') })
			}
		})
	})
}
