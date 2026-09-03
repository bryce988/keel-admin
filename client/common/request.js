/**
 * 请求层
 *
 * 页面不直接调 uni.request：地址、渠道头、令牌、401 处理集中在这里，
 * 后端改契约时只改一处。这是 web/ 那边 `utils/request.ts` 的同款约定。
 *
 * 与 web 端的两点不同：
 * - 没有 axios，用 uni.request 包一层 Promise（uni 的 API 在无回调时也返回 Promise，
 *   但错误分支的语义不一样，自己包更好控制）
 * - 令牌存 uni.getStorageSync 而不是 localStorage，App 上没有后者
 */
import { BASE_URL, CHANNEL, APP_VERSION } from './config.js'

const TOKEN_KEY = 'keel_token'
const USER_KEY = 'keel_user'
const DEVICE_KEY = 'keel_device'

export function getToken() {
	return uni.getStorageSync(TOKEN_KEY) || ''
}

export function setToken(token) {
	uni.setStorageSync(TOKEN_KEY, token)
}

export function clearAuth() {
	uni.removeStorageSync(TOKEN_KEY)
	uni.removeStorageSync(USER_KEY)
}

export function getCachedUser() {
	return uni.getStorageSync(USER_KEY) || null
}

export function cacheUser(user) {
	uni.setStorageSync(USER_KEY, user)
}

/**
 * 设备号
 *
 * 首次取到就存下来：deviceId 在应用重装后会变，但同一次安装内稳定，
 * 够用来做「一台设备」的口径。后端的限流按它算而不是按 IP——
 * 移动网络下大量用户共用出口 IP，按 IP 限流会误伤一整片人。
 */
function deviceId() {
	let id = uni.getStorageSync(DEVICE_KEY)
	if (!id) {
		const info = uni.getSystemInfoSync()
		id = info.deviceId || ('dev-' + Date.now())
		uni.setStorageSync(DEVICE_KEY, id)
	}
	return id
}

function headers(withToken) {
	const h = {
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
 * 令牌失效的统一出口
 *
 * 401 有两种来源：令牌过期，或者账号被封禁/改了密码（后端每个请求都查库）。
 * 两种都只有一条出路——回登录页，所以不区分。
 * 用 reLaunch 而不是 navigateTo：把页面栈清掉，否则按返回键又回到了要登录才能看的页面。
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
 * 后端返回 400/401/500 一律走 success，statusCode 才是真正的成败依据。
 * 这和 web 端「先按 HTTP 状态码分派，再按业务码细化」是同一条约定。
 *
 * reject 出去的永远是 `{ code, message }`——与后端 C 端错误体同构，
 * 页面拿到什么形状是确定的，不用每处都判一遍 undefined。
 */
export function request(path, method = 'GET', data = null, withToken = true) {
	return new Promise((resolve, reject) => {
		uni.request({
			url: BASE_URL + path,
			method,
			data: data || {},
			header: {
				'Content-Type': 'application/json',
				...headers(withToken)
			},
			success: (res) => {
				const status = res.statusCode
				if (status >= 200 && status < 300) {
					resolve(res.data)
					return
				}

				const body = res.data || {}
				const err = {
					code: body.code || status,
					message: body.message || `请求失败（${status}）`
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
 * 手写 Content-Type 会漏掉 boundary，后端解析不出文件
 * （web 端的用户导入踩过同一个坑）。所以这里**不设 Content-Type**。
 *
 * 另一个坑：uploadFile 回来的 data 是**字符串**，要自己 JSON.parse。
 */
export function upload(path, filePath, name = 'file') {
	return new Promise((resolve, reject) => {
		uni.uploadFile({
			url: BASE_URL + path,
			filePath,
			name,
			header: headers(true),
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
