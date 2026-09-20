import request from '@/utils/request'

/**
 * 即时通讯
 *
 * 契约见 docs/api.md §15 与 docs/chat-tech.md §4。三件与别的模块不同的事：
 *
 * - **发消息走 HTTP，不走 WebSocket 上行**。长连接只负责下行推送，
 *   这样鉴权、限流、幂等全部复用现有拦截器（chat-tech.md §1.1）
 * - 历史用**游标**（`before_seq` / `after_seq`）而不是页码：消息表会很大，
 *   深页 offset 要扫过前面所有行
 * - 非会话成员一律 **404 而不是 403**，403 等于确认会话存在
 */

export interface ChatContact {
  id: number
  real_name: string
  username: string
  avatar: string
  dept_id: number
}

export interface ChatConversation {
  id: number
  /** 1 单聊 · 2 群聊 */
  type: number
  /** 单聊时是对方的姓名，服务端已经算好，前端不用再查人 */
  name: string
  avatar: string
  max_seq: number
  last_msg_at: string | null
  last_msg_text: string
  /** 单聊时是对方的 id，群聊为 0 */
  peer_id: number
}

export interface ChatMessage {
  id: number
  conv_id: number
  /** 会话内序号，从 1 连续递增。空洞检测、补拉、未读数都靠它 */
  seq: number
  sender_id: number
  sender_name: string
  type: 'text' | 'image' | 'file' | 'system'
  content: string
  extra: Record<string, unknown> | null
  /** 客户端生成的幂等 ID，本地乐观上屏的消息靠它对号入座 */
  client_msg_id: string
  /** 1 正常 · 2 已撤回 */
  status: number
  recalled_by: number
  created_at: string
}

export function getContacts(keyword = '') {
  return request.get<unknown, ChatContact[]>('/admin/chat/contacts', { params: { keyword } })
}

/** 打开与某人的单聊。已存在就返回已有的，不会重复创建 */
export function openConversation(userId: number) {
  return request.post<unknown, ChatConversation>('/admin/chat/conversations', { user_id: userId })
}

export interface ChatConversationRow extends ChatConversation {
  /** 算出来的，不是存的：会话最大序号 − 我的已读水位 */
  unread: number
  /** 有没读到的 @ */
  has_at: boolean
  is_pinned: boolean
  is_muted: boolean
  last_read_seq: number
}

/** 我的会话列表。置顶在前，其余按最后消息时间倒序 */
export function getConversations() {
  return request.get<unknown, ChatConversationRow[]>('/admin/chat/conversations')
}

export interface ChatUnread {
  /** 红点上的数字，**不含免打扰** */
  total: number
  /** 有未读的会话数，含免打扰——用于「有消息但不弹数字」的小圆点 */
  conversations: number
  has_at: boolean
}

export function getUnread() {
  return request.get<unknown, ChatUnread>('/admin/chat/unread')
}

/** 置顶 / 免打扰。两个都是每个人自己的，我置顶了不影响对方 */
export function updateSettings(convId: number, data: { is_pinned?: boolean; is_muted?: boolean }) {
  return request.put<unknown, { conv_id: number; is_pinned: boolean; is_muted: boolean }>(
    `/admin/chat/conversations/${convId}/settings`,
    data,
  )
}

/** 删除会话：只从我的列表移除，不删消息，对方不受影响 */
export function removeConversation(convId: number) {
  return request.delete<unknown, void>(`/admin/chat/conversations/${convId}`)
}

export function getConversation(id: number) {
  return request.get<unknown, ChatConversation>(`/admin/chat/conversations/${id}`)
}

/**
 * 历史消息，返回永远按 seq 正序（从旧到新）
 *
 * @param beforeSeq 向上翻历史
 * @param afterSeq  断线重连后补空洞
 */
export function getMessages(
  convId: number,
  params: { before_seq?: number; after_seq?: number; limit?: number } = {},
) {
  return request.get<unknown, ChatMessage[]>(`/admin/chat/conversations/${convId}/messages`, { params })
}

export interface SendPayload {
  client_msg_id: string
  type?: string
  content: string
  extra?: Record<string, unknown> | null
}

export function sendMessage(convId: number, payload: SendPayload) {
  return request.post<unknown, ChatMessage>(`/admin/chat/conversations/${convId}/messages`, payload)
}

export function markRead(convId: number, lastReadSeq: number) {
  return request.post<unknown, { conv_id: number; last_read_seq: number }>(
    `/admin/chat/conversations/${convId}/read`,
    { last_read_seq: lastReadSeq },
  )
}
