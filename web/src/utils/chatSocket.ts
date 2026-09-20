/**
 * 聊天长连接客户端
 *
 * **只管连接，不碰业务**：收到帧就派发给订阅者，消息怎么归并、未读怎么算
 * 全在页面/store 里。混在一起的话，重连逻辑和消息归并逻辑会互相纠缠，
 * 是 IM 前端最常见的烂泥（docs/chat-tech.md §6.1）。
 *
 * 上行只有 ping。发消息、标已读都走 HTTP。
 */

/** 下行事件，与网关的帧格式一一对应（chat-tech.md §5.2） */
export type ChatEvent = 'ready' | 'ping' | 'pong' | 'message.new' | 'message.recalled' | 'conversation.read'

type Handler = (data: any) => void

/**
 * WebSocket 地址
 *
 * 开发时 vite dev server 与后端不同端口，直连网关的 8788；
 * 生产是同域 nginx，走 `/ws` 反代（chat-tech.md §8.1）。
 * `wss` 跟着页面协议走——https 页面里连 ws:// 会被浏览器直接拦掉。
 */
function socketUrl(token: string): string {
  const proto = location.protocol === 'https:' ? 'wss:' : 'ws:'
  const base = import.meta.env.DEV
    ? `${proto}//${location.hostname}:8788`
    : `${proto}//${location.host}/ws`

  return `${base}/?token=${encodeURIComponent(token)}`
}

export class ChatSocket {
  private ws: WebSocket | null = null
  private handlers = new Map<string, Set<Handler>>()
  private attempt = 0
  private heartbeatTimer: number | null = null
  private reconnectTimer: number | null = null
  /** 主动关闭时不要再重连——退出登录、组件卸载都走这条 */
  private closedByUs = false

  constructor(private getToken: () => string) {}

  connect(): void {
    if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) {
      return
    }

    const token = this.getToken()
    if (!token) return

    this.closedByUs = false
    this.ws = new WebSocket(socketUrl(token))

    this.ws.onopen = () => {
      this.attempt = 0
      this.startHeartbeat()
    }

    this.ws.onmessage = (e) => {
      let frame: { ev?: string; data?: unknown }
      try {
        frame = JSON.parse(e.data)
      } catch {
        return
      }
      if (!frame.ev) return

      // 服务端的 ping 要回 pong，否则会被当成死连接踢掉
      if (frame.ev === 'ping') {
        this.send({ ev: 'pong' })
        return
      }

      this.handlers.get(frame.ev)?.forEach((fn) => fn(frame.data))
    }

    this.ws.onclose = () => {
      this.stopHeartbeat()
      if (!this.closedByUs) this.scheduleReconnect()
    }

    // onerror 之后浏览器必定接着触发 onclose，重连只在那里做，
    // 两处都做的话一次断线会排两个重连定时器
    this.ws.onerror = () => {}
  }

  /**
   * 指数退避 + 抖动
   *
   * ⚠️ **抖动不能省**：服务端一次 reload 会同时断开所有客户端，
   * 没有抖动的话它们会在同一秒重连，把刚起来的网关再打垮一次。
   */
  private scheduleReconnect(): void {
    if (this.reconnectTimer) return
    // 断网时不要空转重连，等浏览器报 online
    if (!navigator.onLine) {
      window.addEventListener('online', () => this.connect(), { once: true })
      return
    }

    const delay = Math.min(1000 * 2 ** this.attempt, 30_000) * (0.5 + Math.random())
    this.attempt += 1

    this.reconnectTimer = window.setTimeout(() => {
      this.reconnectTimer = null
      this.connect()
    }, delay)
  }

  /** 客户端也发心跳：只靠服务端 ping 的话，中间的代理超时断连我们要等 70 秒才发现 */
  private startHeartbeat(): void {
    this.stopHeartbeat()
    this.heartbeatTimer = window.setInterval(() => this.send({ ev: 'ping' }), 25_000)
  }

  private stopHeartbeat(): void {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }

  private send(frame: Record<string, unknown>): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(frame))
    }
  }

  on(event: ChatEvent, fn: Handler): () => void {
    if (!this.handlers.has(event)) this.handlers.set(event, new Set())
    this.handlers.get(event)!.add(fn)

    // 返回取消订阅函数：组件卸载时必须调，否则 handlers 会一直攒着
    return () => this.handlers.get(event)?.delete(fn)
  }

  get connected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN
  }

  close(): void {
    this.closedByUs = true
    this.stopHeartbeat()
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
    this.ws?.close()
    this.ws = null
    this.handlers.clear()
  }
}

export const chatSocket = new ChatSocket(() => localStorage.getItem('keel_token') || '')
