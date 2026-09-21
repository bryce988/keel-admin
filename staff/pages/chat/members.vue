<template>
	<view class="page">
		<view v-if="rows.length" class="panel">
			<view
				v-for="(m, i) in rows"
				:key="m.user_id"
				class="row"
				:class="{ 'row--last': i === rows.length - 1 }"
				hover-class="row--hover"
				@longpress="onLongPress(m)"
			>
				<view class="avatar">
					<image v-if="m.avatar" class="avatar-img" :src="absUrl(m.avatar)" mode="aspectFill" />
					<text v-else class="avatar-text">{{ m.real_name.slice(0, 1) }}</text>
				</view>
				<text class="row-name">{{ m.real_name }}</text>
				<text v-if="m.role === 1" class="tag-owner">群主</text>
			</view>
		</view>

		<text v-else class="hint">{{ loading ? '正在加载' : (error || '暂无成员') }}</text>

		<view class="ops">
			<view v-if="isOwner" class="op" hover-class="op--hover" @click="toAdd">
				<text class="op-text">添加成员</text>
			</view>
			<view v-if="isOwner" class="op" hover-class="op--hover" @click="rename">
				<text class="op-text">修改群名</text>
			</view>
			<view class="op op--danger" hover-class="op--hover" @click="leave">
				<text class="op-text op-text--danger">{{ isOwner ? '解散群聊' : '退出群聊' }}</text>
			</view>
		</view>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onLoad, onShow } from '@dcloudio/uni-app'
	import {
		fetchChatMembers,
		fetchChatConversation,
		removeChatMember,
		updateChatGroup,
		dissolveChatGroup
	} from '@/common/api.js'
	import { absUrl, getCachedUser } from '@/common/request.js'

	const convId = ref(0)
	const rows = ref([])
	const conv = ref(null)
	const loading = ref(false)
	const error = ref('')
	const myId = ref(0)

	const isOwner = computed(() => conv.value && conv.value.owner_id === myId.value)

	async function load() {
		loading.value = true
		error.value = ''
		try {
			// 两个请求一起发：成员列表给名单，会话详情给「我是不是群主」。
			// 串行的话进页面要等两个往返
			const [m, c] = await Promise.all([
				fetchChatMembers(convId.value),
				fetchChatConversation(convId.value)
			])
			rows.value = m
			conv.value = c
			uni.setNavigationBarTitle({ title: `群成员（${m.length}）` })
		} catch (e) {
			if (e.code !== 401) error.value = e.message
		} finally {
			loading.value = false
		}
	}

	/**
	 * 长按移出成员
	 *
	 * 只有群主能移出，且不能移出自己——移出群主等于让群没人管，服务端也会拒。
	 * 不满足条件时静默返回：这一行没有可做的事，弹一个只有「取消」的菜单更糟。
	 */
	function onLongPress(m) {
		if (!isOwner.value || m.user_id === myId.value) return

		uni.showActionSheet({
			itemList: [`将「${m.real_name}」移出群聊`],
			success: async () => {
				try {
					await removeChatMember(convId.value, m.user_id)
					await load()
				} catch (e) {
					if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
				}
			}
		})
	}

	function toAdd() {
		// 复用通讯录页，带上 conv 参数让它进入「加人」模式而不是建群
		uni.navigateTo({ url: `/pages/chat/contacts?add_to=${convId.value}` })
	}

	function rename() {
		uni.showModal({
			title: '修改群名',
			editable: true,
			placeholderText: conv.value ? conv.value.name : '',
			success: async ({ confirm, content }) => {
				if (!confirm || !content || !content.trim()) return
				try {
					await updateChatGroup(convId.value, { name: content.trim() })
					await load()
					uni.showToast({ title: '已修改', icon: 'none' })
				} catch (e) {
					if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
				}
			}
		})
	}

	function leave() {
		const owner = isOwner.value

		uni.showModal({
			title: owner ? '解散群聊' : '退出群聊',
			content: owner
				? '解散后所有成员的会话都会消失。消息会保留在服务器上（审计需要），但没有人能再看到。'
				: '退出后将不再接收该群消息，历史记录也会从列表移除。',
			confirmText: owner ? '解散' : '退出',
			confirmColor: '#f56c6c',
			success: async ({ confirm }) => {
				if (!confirm) return
				try {
					if (owner) await dissolveChatGroup(convId.value)
					else await removeChatMember(convId.value, myId.value)

					// 群没了，聊天室那一页也该关掉——回到会话列表。
					// 只 navigateBack 的话会退回一个已经 404 的聊天室
					uni.switchTab({ url: '/pages/chat/list' })
				} catch (e) {
					if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
				}
			}
		})
	}

	onLoad((options) => {
		convId.value = Number(options.id || 0)
		myId.value = Number((getCachedUser() || {}).id || 0)
	})

	// onShow 而不是 onLoad：从「添加成员」返回时名单变了
	onShow(() => {
		if (convId.value) load()
	})
</script>

<style scoped>
	.page {
		min-height: calc(100vh - var(--window-top));
		padding: 12px 0 32px;
		box-sizing: border-box;
		background: #f5f5f7;
	}

	.panel {
		margin: 0 16px;
		background: #ffffff;
		border-radius: 12px;
		overflow: hidden;
	}

	.row {
		display: flex;
		align-items: center;
		padding: 12px 16px;
		border-bottom: 1px solid #f0f0f2;
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
	}

	.row--last {
		border-bottom: none;
	}

	.row--hover {
		background: #f5f5f7;
	}

	.avatar {
		width: 38px;
		height: 38px;
		margin-right: 12px;
		border-radius: 19px;
		background: #409eff;
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
		flex-shrink: 0;
	}

	.avatar-img {
		width: 38px;
		height: 38px;
	}

	.avatar-text {
		font-size: 15px;
		color: #ffffff;
	}

	.row-name {
		flex: 1;
		font-size: 16px;
		color: #1d1d1f;
	}

	.tag-owner {
		padding: 2px 8px;
		font-size: 11px;
		color: #e6a23c;
		background: #fdf6ec;
		border-radius: 8px;
	}

	.ops {
		margin: 16px;
		background: #ffffff;
		border-radius: 12px;
		overflow: hidden;
	}

	.op {
		padding: 14px 16px;
		border-bottom: 1px solid #f0f0f2;
		display: flex;
		justify-content: center;
	}

	.op:last-child {
		border-bottom: none;
	}

	.op--hover {
		background: #f5f5f7;
	}

	.op-text {
		font-size: 16px;
		color: #1d1d1f;
	}

	.op-text--danger {
		color: #f56c6c;
	}

	.hint {
		display: block;
		padding: 40px 20px;
		text-align: center;
		font-size: 14px;
		color: #86868b;
	}
</style>
