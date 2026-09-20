/**
 * 聊天长连接（移动端）
 *
 * 与 web/ 的 `utils/chatSocket.ts` 是**同一套协议的两份实现**，不是共享代码：
 * uni-app 没有 `WebSocket` 构造函数，用的是 `uni.connectSocket` / `SocketTask`，
 * 回调风格也不同（onMessage 而非 addEventListener）。
 *
 * 两个工程一个 TS 一个 JS、一个走 vite 一个走 HBuilderX，
 * 把这 80 行做成跨端共享包的成本高于重写。**要共享的是协议约定，不是代码**
 * （docs/chat-tech.md §7.2）。
 *
 * 上行只有 ping。发消息、标已读都走 HTTP。
 */
import { BASE_URL } from './config.js'
import { getToken } from './request.js'

/**
 * WebSocket 地址
 *
 * 从 BASE_URL 推导，跟着它一起走：开发时是开发机的局域网地址（8788 直连网关），
 * 生产是线上域名（走 nginx 的 /ws 反代）。
 * BASE_URL 本身已经按环境自动推断，这里不再重复那套判断。
 */
function socketUrl(token) {
	const wsBase = BASE_URL.replace(/^http/, 'ws')
	// 开发直连网关端口；线上同域走 /ws 反代
	const url = wsBase.includes(':8787') ? wsBase.replace(':8787', ':8788') + '/' : wsBase + '/ws'

	return `${url}?token=${encodeURIComponent(token)}`
}

class ChatSocket {
	constructor() {
		this.task = null
		this.handlers = {}
		this.attempt = 0
		this.heartbeatTimer = null
		this.reconnectTimer = null
		this.closedByUs = false
		this.connected = false
	}

	connect() {
		if (this.task && this.connected) return

		const token = getToken()
		if (!token) return

		this.closedByUs = false
		this.task = uni.connectSocket({ url: socketUrl(token), complete: () => {} })

		this.task.onOpen(() => {
			this.connected = true
			this.attempt = 0
			this.startHeartbeat()
		})

		this.task.onMessage((res) => {
			let frame
			try {
				frame = JSON.parse(res.data)
			} catch (e) {
				return
			}
			if (!frame.ev) return

			// 服务端的 ping 要回 pong，不回会被当成死连接踢掉
			if (frame.ev === 'ping') {
				this.send({ ev: 'pong' })
				return
			}

			;(this.handlers[frame.ev] || []).forEach((fn) => fn(frame.data))
		})

		this.task.onClose(() => {
			this.connected = false
			this.stopHeartbeat()
			if (!this.closedByUs) this.scheduleReconnect()
		})

		// onError 之后必定接着 onClose，重连只在那里做——
		// 两处都做的话一次断线会排两个重连定时器
		this.task.onError(() => {})
	}

	/**
	 * 指数退避 + 抖动
	 *
	 * ⚠️ 抖动不能省：服务端一次 reload 会同时断开所有客户端，
	 * 没有抖动的话它们会在同一秒重连，把刚起来的网关再打垮一次。
	 */
	scheduleReconnect() {
		if (this.reconnectTimer) return

		const delay = Math.min(1000 * Math.pow(2, this.attempt), 30000) * (0.5 + Math.random())
		this.attempt += 1

		this.reconnectTimer = setTimeout(() => {
			this.reconnectTimer = null
			this.connect()
		}, delay)
	}

	/** 客户端也发心跳：只靠服务端 ping 的话，中间代理超时断连要等 70 秒才发现 */
	startHeartbeat() {
		this.stopHeartbeat()
		this.heartbeatTimer = setInterval(() => this.send({ ev: 'ping' }), 25000)
	}

	stopHeartbeat() {
		if (this.heartbeatTimer) {
			clearInterval(this.heartbeatTimer)
			this.heartbeatTimer = null
		}
	}

	send(frame) {
		if (this.task && this.connected) {
			this.task.send({ data: JSON.stringify(frame), fail: () => {} })
		}
	}

	/** 返回取消订阅函数。页面卸载时必须调，否则 handlers 会一直攒着 */
	on(event, fn) {
		if (!this.handlers[event]) this.handlers[event] = []
		this.handlers[event].push(fn)

		return () => {
			this.handlers[event] = (this.handlers[event] || []).filter((f) => f !== fn)
		}
	}

	close() {
		this.closedByUs = true
		this.stopHeartbeat()
		if (this.reconnectTimer) {
			clearTimeout(this.reconnectTimer)
			this.reconnectTimer = null
		}
		if (this.task) this.task.close({ code: 1000 })
		this.task = null
		this.connected = false
		this.handlers = {}
	}
}

export const chatSocket = new ChatSocket()
