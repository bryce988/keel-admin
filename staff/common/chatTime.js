/**
 * 聊天里的时间怎么说（钉钉同款）
 *
 * - 今天：`14:30`
 * - 今年更早：`9月20日 14:30`
 * - 往年：`2025年9月20日 14:30`
 *
 * 与 web 端 `web/src/utils/chatTime.ts` 是同一套规则，改一边要改另一边。
 */

/**
 * 服务端时间是 `Y-m-d H:i:s`
 *
 * ⚠️ iOS 的 Date 不认横杠，必须换成斜杠，否则得到 NaN——
 * 表现是 iPhone 上所有时间都不显示、撤回按钮永远不出来
 */
export function parseChatTime(at) {
	if (!at) return null
	const d = new Date(String(at).replace(/-/g, '/'))
	return isNaN(d.getTime()) ? null : d
}

function pad(n) {
	return String(n).padStart(2, '0')
}

function hm(d) {
	return `${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function sameDay(a, b) {
	return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/** 消息时间：时间分隔与发送人旁的时间都用它 */
export function formatMessageTime(d, now = new Date()) {
	if (sameDay(d, now)) return hm(d)

	const md = `${d.getMonth() + 1}月${d.getDate()}日`
	if (d.getFullYear() === now.getFullYear()) return `${md} ${hm(d)}`

	return `${d.getFullYear()}年${md} ${hm(d)}`
}

/** 会话列表时间：位置窄，今天给时刻、昨天给「昨天」、今年给月日、往年给年月日 */
export function formatListTime(d, now = new Date()) {
	if (sameDay(d, now)) return hm(d)

	const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1)
	if (sameDay(d, yesterday)) return '昨天'

	const md = `${d.getMonth() + 1}月${d.getDate()}日`
	if (d.getFullYear() === now.getFullYear()) return md

	return `${d.getFullYear()}/${d.getMonth() + 1}/${d.getDate()}`
}
