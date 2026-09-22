/**
 * 聊天里的时间怎么说（钉钉同款）
 *
 * - 今天：`14:30`
 * - 今年更早：`9月20日 14:30`
 * - 往年：`2025年9月20日 14:30`
 *
 * 越近越省字：今天的消息日期是废话，而往年的消息不带年份会被当成今年的。
 * 移动端 `staff/common/chatTime.js` 是同一套规则，改一边要改另一边。
 */

/** 服务端时间是 `Y-m-d H:i:s`（本地时区），Safari 不认中间的空格和横杠，换成斜杠再解析 */
export function parseChatTime(at: string | null | undefined): Date | null {
  if (!at) return null
  const d = new Date(at.replace(/-/g, '/'))
  return Number.isNaN(d.getTime()) ? null : d
}

function hm(d: Date): string {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

function sameDay(a: Date, b: Date): boolean {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/** 消息时间：用于消息上方的时间分隔与发送人旁的时间 */
export function formatMessageTime(d: Date, now = new Date()): string {
  if (sameDay(d, now)) return hm(d)

  const md = `${d.getMonth() + 1}月${d.getDate()}日`
  if (d.getFullYear() === now.getFullYear()) return `${md} ${hm(d)}`

  return `${d.getFullYear()}年${md} ${hm(d)}`
}

/**
 * 会话列表时间：一行里位置很窄，只给「最该知道的那一段」
 *
 * 今天给时刻，昨天给「昨天」，今年给月日，往年给年月日——
 * 与消息区同一套分档，只是不带时刻
 */
export function formatListTime(d: Date, now = new Date()): string {
  if (sameDay(d, now)) return hm(d)

  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1)
  if (sameDay(d, yesterday)) return '昨天'

  const md = `${d.getMonth() + 1}月${d.getDate()}日`
  if (d.getFullYear() === now.getFullYear()) return md

  return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}
