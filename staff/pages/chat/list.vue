<template>
	<view class="page">
		<view class="hero">
			<view class="hero-row">
				<text class="hero-title">聊天</text>
				<text class="hero-action" @click="toGroupPicker">发起群聊</text>
			</view>
			<text class="hero-sub">{{ unreadHint }}</text>
		</view>

		<view class="panel">
			<!-- 系统公告：固定在最上面，不参与置顶排序，没有长按菜单（删不掉，也没有免打扰）。
			     数据直接读公告与已读回执，不是一个真的会话 -->
			<view
				class="row"
				:class="{ 'row--last': !rows.length }"
				hover-class="row--hover"
				@click="toNotices"
			>
				<view class="avatar avatar--notice">
					<text class="avatar-notice-text">告</text>
				</view>
				<view class="row-body">
					<view class="row-line">
						<text class="row-name">系统公告</text>
						<text class="row-time">{{ listTime(notice.latest_at) }}</text>
					</view>
					<view class="row-line">
						<text class="row-brief">{{ notice.latest_title || '暂无公告' }}</text>
						<view v-if="notice.unread > 0" class="badge">
							<text class="badge-text">{{ notice.unread > 99 ? '99+' : notice.unread }}</text>
						</view>
					</view>
				</view>
			</view>

			<view
				v-for="(c, i) in rows"
				:key="c.id"
				class="row"
				:class="{ 'row--last': i === rows.length - 1 }"
				hover-class="row--hover"
				@click="openRoom(c)"
				@longpress="onLongPress(c)"
			>
				<!-- 单聊是对方头像；群没设头像时拼成员头像 -->
				<view class="avatar-slot">
					<group-avatar :src="c.avatar" :faces="c.avatar_members" :name="c.name" :size="44" />
				</view>

				<view class="row-body">
					<view class="row-line">
						<text class="row-name">{{ c.name }}</text>
						<text class="row-time">{{ listTime(c.last_msg_at) }}</text>
					</view>
					<view class="row-line">
						<text class="row-brief">
							<text v-if="c.has_at" class="row-at">[有人@我] </text>{{ c.last_msg_text || '暂无消息' }}
						</text>
						<!-- 免打扰只显示小圆点不显示数字：用户设它就是为了红点别跳 -->
						<view v-if="c.unread > 0" class="badge" :class="{ 'badge--dot': c.is_muted }">
							<text v-if="!c.is_muted" class="badge-text">{{ c.unread > 99 ? '99+' : c.unread }}</text>
						</view>
					</view>
				</view>

				<text v-if="c.is_pinned" class="pin">📌</text>
			</view>
		</view>

		<text v-if="!rows.length" class="hint">{{ loading ? '正在加载' : (error || '还没有会话，去底部「通讯录」找个同事聊聊') }}</text>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onShow, onHide, onPullDownRefresh } from '@dcloudio/uni-app'
	import {
		fetchChatConversations,
		updateChatSettings,
		removeChatConversation
	} from '@/common/api.js'
	import { absUrl, getCachedUser } from '@/common/request.js'
	import { chatSocket } from '@/common/chatSocket.js'
	import { bindChatBadge, refreshChatBadge } from '@/common/chatBadge.js'
	import { formatListTime, parseChatTime } from '@/common/chatTime.js'
	import GroupAvatar from '@/components/group-avatar/group-avatar.vue'

	const rows = ref([])
	/** 「系统公告」那一行：未读数与最新一条，来自未读汇总 */
	const notice = ref({ unread: 0, latest_id: 0, latest_title: '', latest_at: null })
	const loading = ref(false)
	const error = ref('')

	function myUserId() {
		return Number((getCachedUser() || {}).id || 0)
	}

	const unreadHint = computed(() => {
		// 免打扰的不算进这句话，与角标口径一致
		const n = rows.value.filter((c) => c.unread > 0 && !c.is_muted).length
		const parts = []
		if (n) parts.push(`${n} 个会话有新消息`)
		if (notice.value.unread) parts.push(`${notice.value.unread} 条未读公告`)
		return parts.length ? parts.join('，') : '没有未读消息'
	})

	async function load() {
		loading.value = true
		error.value = ''
		try {
			// 两个请求一起发：会话列表给行，未读汇总给「系统公告」那一行与 tab 角标
			const [list, summary] = await Promise.all([fetchChatConversations(), refreshChatBadge()])
			rows.value = list
			notice.value = summary.notice || notice.value
		} catch (e) {
			if (e.code !== 401) error.value = e.message
		} finally {
			loading.value = false
			uni.stopPullDownRefresh()
		}
	}

	/** 公告页不是 tab 页，navigateTo 压栈；读完返回时 onShow 会重拉，数字自然对上 */
	function toNotices() {
		uni.navigateTo({ url: '/pages/notice/list' })
	}

	/**
	 * 发起群聊：打开选人页
	 *
	 * 建群放在消息页而不是通讯录：「拉个群聊一下」是从聊天里冒出来的念头（与电脑端一致）
	 */
	function toGroupPicker() {
		uni.navigateTo({ url: '/pages/chat/pick' })
	}

	function openRoom(c) {
		// 本地先清零，不等服务端往返——点进去角标要立刻消失
		c.unread = 0
		c.has_at = false
		uni.navigateTo({ url: `/pages/chat/room?id=${c.id}&name=${encodeURIComponent(c.name)}` })
	}

	/**
	 * 长按出操作菜单
	 *
	 * 移动端没有 hover，右键菜单那一套在这里不成立。长按是 iOS/Android 上
	 * 「对这一行做点什么」的通用手势，用户不用学。
	 */
	function onLongPress(c) {
		uni.showActionSheet({
			itemList: [c.is_pinned ? '取消置顶' : '置顶', c.is_muted ? '取消免打扰' : '免打扰', '删除会话'],
			success: async ({ tapIndex }) => {
				try {
					if (tapIndex === 0) {
						const r = await updateChatSettings(c.id, { is_pinned: !c.is_pinned })
						c.is_pinned = r.is_pinned
						await load()
					} else if (tapIndex === 1) {
						const r = await updateChatSettings(c.id, { is_muted: !c.is_muted })
						c.is_muted = r.is_muted
					} else {
						const ok = await confirmRemove(c.name)
						if (!ok) return
						await removeChatConversation(c.id)
						rows.value = rows.value.filter((x) => x.id !== c.id)
					}
				} catch (e) {
					if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
				}
			}
		})
	}

	function confirmRemove(name) {
		return new Promise((resolve) => {
			uni.showModal({
				title: `删除与「${name}」的会话`,
				content: '只会从你的列表移除，不删除消息，对方也不受影响。对方再发消息时会话会重新出现。',
				confirmText: '删除',
				confirmColor: '#f56c6c',
				success: (r) => resolve(r.confirm),
				fail: () => resolve(false)
			})
		})
	}

	/** 分档规则见 common/chatTime.js：今天给时刻、昨天、今年给月日、往年带年份 */
	function listTime(at) {
		const d = parseChatTime(at)
		return d ? formatListTime(d) : ''
	}

	let offMessage = null
	let offReady = null
	let offNotice = null
	let offNoticeRead = null

	onShow(() => {
		load()
		chatSocket.connect()
		// 全局角标同步：挂一次就不摘，人在别的 tab 时角标也会跟着变（见 common/chatBadge.js）
		bindChatBadge()

		// 在列表页也要收推送：不然收到新消息得手动下拉才看得到
		if (!offMessage) {
			offMessage = chatSocket.on('message.new', (msg) => {
				/*
				 * 震动提醒
				 *
				 * 移动端**不做系统通知**——那要接厂商推送通道，与「脚手架不绑死
				 * 服务商」冲突（chat-prd.md §5.7）。App 在前台时震一下是不依赖
				 * 任何第三方就能做到的最强提醒。
				 *
				 * 自己发的不震：多设备登录时，我在电脑上发消息手机不该抖。
				 */
				if (msg && msg.sender_id !== myUserId()) {
					// #ifndef H5
					uni.vibrateShort({ fail: () => {} })
					// #endif
				}
				load()
			})
			// 每次重连都会再来一次 ready，断线期间的消息靠这一步补进列表
			offReady = chatSocket.on('ready', () => load())

			// 公告有变化（发布 / 撤回 / 删除 / 编辑）或在别处读了：「系统公告」那一行跟着变。
			// 新发布的震一下，与新消息同一种提醒
			offNotice = chatSocket.on('notice.changed', (d) => {
				if (d && d.action === 'published') {
					// #ifndef H5
					uni.vibrateShort({ fail: () => {} })
					// #endif
				}
				load()
			})
			offNoticeRead = chatSocket.on('notice.read', () => load())
		}
	})

	onHide(() => {
		if (offMessage) { offMessage(); offMessage = null }
		if (offReady) { offReady(); offReady = null }
		if (offNotice) { offNotice(); offNotice = null }
		if (offNoticeRead) { offNoticeRead(); offNoticeRead = null }
	})

	onPullDownRefresh(load)
</script>

<style scoped>
	.page {
		min-height: calc(100vh - var(--window-top) - var(--window-bottom));
		padding-bottom: 24px;
		box-sizing: border-box;
	}

	.hero {
		padding: 24px 20px 12px;
	}

	.hero-row {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
	}

	.hero-title {
		font-size: 30px;
		font-weight: 700;
		color: #1d1d1f;
	}

	.hero-action {
		font-size: 16px;
		color: #409eff;
	}

	.hero-sub {
		display: block;
		margin-top: 4px;
		font-size: 14px;
		color: #86868b;
	}

	.panel {
		margin: 0 20px;
		background: #ffffff;
		border-radius: 12px;
		overflow: hidden;
	}

	.row {
		/* 同聊天室：不禁用的话长按会先弹出 WebView 自带的「复制 / 搜索」，
		   把 uni.showActionSheet 盖掉，表现是「长按菜单时好时坏」 */
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
		position: relative;
		display: flex;
		align-items: center;
		padding: 12px 16px;
		border-bottom: 1px solid #f0f0f2;
	}

	.row--last {
		border-bottom: none;
	}

	.row--hover {
		background: #f5f5f7;
	}

	.avatar {
		width: 44px;
		height: 44px;
		margin-right: 12px;
		border-radius: 22px;
		background: #409eff;
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
		flex-shrink: 0;
	}

	.avatar-slot {
		margin-right: 12px;
		flex-shrink: 0;
	}

	/* 系统公告的图标头像：暖色底，一眼看出它不是一个人 */
	.avatar--notice {
		background-color: var(--keel-color-warning);
	}

	.avatar-notice-text {
		font-size: 17px;
		font-weight: 600;
		color: #fff;
	}

	.avatar-img {
		width: 44px;
		height: 44px;
	}

	.avatar-text {
		font-size: 17px;
		color: #ffffff;
	}

	.row-body {
		flex: 1;
		min-width: 0;
	}

	.row-line {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	.row-line + .row-line {
		margin-top: 3px;
	}

	.row-name {
		font-size: 16px;
		color: #1d1d1f;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	.row-time {
		flex-shrink: 0;
		margin-left: 8px;
		font-size: 12px;
		color: #b0b0b5;
	}

	.row-brief {
		flex: 1;
		min-width: 0;
		font-size: 13px;
		color: #86868b;
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	.row-at {
		color: #f56c6c;
	}

	.badge {
		flex-shrink: 0;
		margin-left: 8px;
		min-width: 18px;
		height: 18px;
		padding: 0 5px;
		border-radius: 9px;
		background: #f56c6c;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	/* 免打扰：只留一个灰点 */
	.badge--dot {
		min-width: 8px;
		width: 8px;
		height: 8px;
		padding: 0;
		border-radius: 4px;
		background: #c7c7cc;
	}

	.badge-text {
		font-size: 11px;
		color: #ffffff;
	}

	.pin {
		position: absolute;
		right: 6px;
		top: 6px;
		font-size: 10px;
	}

	.hint {
		display: block;
		padding: 40px 20px;
		text-align: center;
		font-size: 14px;
		color: #86868b;
		line-height: 1.6;
	}
</style>
