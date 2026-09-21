<template>
	<view class="page">
		<view class="hero">
			<view class="hero-row">
				<text class="hero-title">聊天</text>
				<text class="hero-action" @click="toContacts">发起</text>
			</view>
			<text class="hero-sub">{{ unreadHint }}</text>
		</view>

		<view v-if="rows.length" class="panel">
			<view
				v-for="(c, i) in rows"
				:key="c.id"
				class="row"
				:class="{ 'row--last': i === rows.length - 1 }"
				hover-class="row--hover"
				@click="openRoom(c)"
				@longpress="onLongPress(c)"
			>
				<view class="avatar">
					<image v-if="c.avatar" class="avatar-img" :src="absUrl(c.avatar)" mode="aspectFill" />
					<text v-else class="avatar-text">{{ c.name.slice(0, 1) }}</text>
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

		<text v-else class="hint">{{ loading ? '正在加载' : (error || '还没有会话，去底部「通讯录」找个同事聊聊') }}</text>
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
	import { absUrl } from '@/common/request.js'
	import { chatSocket } from '@/common/chatSocket.js'

	const rows = ref([])
	const loading = ref(false)
	const error = ref('')

	const unreadHint = computed(() => {
		// 免打扰的不算进这句话，与角标口径一致
		const n = rows.value.filter((c) => c.unread > 0 && !c.is_muted).length
		return n ? `${n} 个会话有新消息` : '没有未读消息'
	})

	async function load() {
		loading.value = true
		error.value = ''
		try {
			rows.value = await fetchChatConversations()
		} catch (e) {
			if (e.code !== 401) error.value = e.message
		} finally {
			loading.value = false
			uni.stopPullDownRefresh()
		}
	}

	/** 通讯录已经是 tab 页，只能用 switchTab——navigateTo 打不开 tabBar 里的页面 */
	function toContacts() {
		uni.switchTab({ url: '/pages/chat/contacts' })
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

	/** 今天给时刻，昨天给「昨天」，更早给日期——列表里精确到秒没有意义 */
	function listTime(at) {
		if (!at) return ''

		const d = at.slice(0, 10)
		const pad = (n) => String(n).padStart(2, '0')
		const ymd = (x) => `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`
		const today = new Date()

		if (d === ymd(today)) return at.slice(11, 16)
		if (d === ymd(new Date(today.getTime() - 86400000))) return '昨天'
		return at.slice(5, 10)
	}

	let offMessage = null
	let offReady = null

	onShow(() => {
		load()
		chatSocket.connect()

		// 在列表页也要收推送：不然收到新消息得手动下拉才看得到
		if (!offMessage) {
			offMessage = chatSocket.on('message.new', () => load())
			// 每次重连都会再来一次 ready，断线期间的消息靠这一步补进列表
			offReady = chatSocket.on('ready', () => load())
		}
	})

	onHide(() => {
		if (offMessage) { offMessage(); offMessage = null }
		if (offReady) { offReady(); offReady = null }
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
