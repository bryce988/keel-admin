/**
 * 「消息」tab 角标的全局同步
 *
 * 角标数字 = 服务端未读汇总的 total：聊天未读（不含免打扰）+ 未读系统公告。
 * 以服务端为准、不在客户端自己加减——公告有发布、撤回、删除、编辑四种变化，
 * 消息还有免打扰，本地算很容易和列表页对不上。
 *
 * ⚠️ 为什么要全局挂一份，而不是只在消息列表页里算：
 * 列表页 onHide 就把监听摘了，人停在工作台、我的、公告页时来了消息，
 * 角标原来是不动的，要切回消息页才对上。这里的监听挂上就不摘，
 * 因为 tabBar 本身也是全局的
 */
import { fetchChatUnread, setChatBadge } from './api.js'
import { chatSocket } from './chatSocket.js'

let bound = false
let timer = null

/** 拉一次汇总并更新角标，返回汇总本身（列表页要用里面的 notice） */
export async function refreshChatBadge() {
	const d = await fetchChatUnread()
	setChatBadge(d.total || 0)
	return d
}

/**
 * 合并短时间内的多次触发：群里一口气来十条消息，没必要打十次汇总接口
 */
function schedule() {
	if (timer) clearTimeout(timer)
	timer = setTimeout(() => {
		timer = null
		refreshChatBadge().catch(() => {
			/* 角标拉失败不打扰，下次事件或回到前台会再对一次 */
		})
	}, 300)
}

/** 幂等：多处调用只挂一次 */
export function bindChatBadge() {
	if (bound) return
	bound = true

	// ready 含每次重连：断线期间的变化靠这一下补上
	;['ready', 'message.new', 'message.recalled', 'conversation.read', 'notice.changed', 'notice.read'].forEach(
		(ev) => chatSocket.on(ev, schedule)
	)
}
