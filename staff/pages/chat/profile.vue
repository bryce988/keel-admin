<template>
	<!-- 同事名片：点消息头像、点通讯录里的人都到这里（钉钉同款），再由「发消息」进单聊 -->
	<view class="page">
		<view class="head">
			<view class="avatar">
				<image v-if="avatar" class="avatar-img" :src="avatar" mode="aspectFill" />
				<text v-else class="avatar-text">{{ name.slice(0, 1) }}</text>
			</view>
			<text class="name">{{ name }}</text>
			<text v-if="person && person.post_name" class="sub">{{ person.post_name }}</text>
		</view>

		<view v-if="person" class="group">
			<view v-if="person.dept_name" class="row">
				<text class="row-label">部门</text>
				<text class="row-value">{{ person.dept_name }}</text>
			</view>
			<!-- 手机号是明文才能点拨号；没有字段权限时是掩码，拨不出去就不给点 -->
			<view
				v-if="person.phone"
				class="row"
				:hover-class="canCall ? 'row-pressed' : 'none'"
				@click="call"
			>
				<text class="row-label">手机</text>
				<text class="row-value" :class="{ 'row-value--link': canCall }">{{ person.phone }}</text>
			</view>
			<view v-if="person.email" class="row">
				<text class="row-label">邮箱</text>
				<text class="row-value">{{ person.email }}</text>
			</view>
		</view>

		<text v-else class="hint">{{ loading ? '正在加载' : '查看不到该同事的资料' }}</text>

		<!-- 自己的名片不给「发消息」：服务端也不允许和自己开单聊 -->
		<button
			v-if="person && person.id !== myId"
			class="btn btn-primary send"
			hover-class="btn-pressed"
			@click="chat"
		>
			发消息
		</button>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onLoad } from '@dcloudio/uni-app'
	import { fetchChatContact, openChatConversation } from '@/common/api.js'
	import { absUrl, getCachedUser } from '@/common/request.js'

	const person = ref(null)
	const loading = ref(false)
	/** 取不到资料（已离职、无权限）时退回上一页带过来的名字 */
	const fallbackName = ref('')
	/** 从哪个单聊点进来的：已经在和他聊了，「发消息」就直接返回，不再压一层聊天室 */
	const fromConv = ref(0)
	const myId = ref(0)

	const name = computed(() => (person.value && person.value.real_name) || fallbackName.value || '同事')
	const avatar = computed(() => (person.value && person.value.avatar ? absUrl(person.value.avatar) : ''))
	const canCall = computed(() => !!person.value && /^\d{5,}$/.test(person.value.phone || ''))

	function call() {
		if (!canCall.value) return
		uni.makePhoneCall({ phoneNumber: person.value.phone, fail: () => {} })
	}

	async function chat() {
		try {
			const conv = await openChatConversation(person.value.id)
			if (conv.id === fromConv.value) {
				uni.navigateBack()
				return
			}
			/*
			 * redirectTo 而不是 navigateTo：名片是「去聊天」路上的一站，
			 * 进了聊天室再按返回应该回到来的地方（通讯录或群聊），而不是回到名片
			 */
			uni.redirectTo({ url: `/pages/chat/room?id=${conv.id}&name=${encodeURIComponent(conv.name)}` })
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	onLoad(async (options) => {
		myId.value = Number((getCachedUser() || {}).id || 0)
		fallbackName.value = options.name ? decodeURIComponent(options.name) : ''
		fromConv.value = Number(options.conv || 0)

		loading.value = true
		try {
			person.value = await fetchChatContact(Number(options.id || 0))
		} catch (e) {
			// 404 = 已离职或不存在：页面上已经写了「查看不到」，不再弹一次
			if (e.code !== 401 && e.code !== 10404) uni.showToast({ title: e.message, icon: 'none' })
		} finally {
			loading.value = false
		}
	})
</script>

<style scoped>
	.page {
		min-height: calc(100vh - var(--window-top));
		padding: 0 16px 32px;
		box-sizing: border-box;
		background-color: var(--keel-bg-color-page);
	}

	.head {
		display: flex;
		flex-direction: column;
		align-items: center;
		padding: 32px 0 24px;
	}

	.avatar {
		width: 84px;
		height: 84px;
		border-radius: 42px;
		overflow: hidden;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--keel-color-primary);
	}

	.avatar-img {
		width: 84px;
		height: 84px;
	}

	.avatar-text {
		font-size: 34px;
		color: #fff;
	}

	.name {
		margin-top: 14px;
		font-size: 24px;
		font-weight: 600;
		color: var(--keel-text-color-primary);
	}

	.sub {
		margin-top: 4px;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
	}

	.row-label {
		width: 64px;
	}

	.row-value {
		word-break: break-all;
	}

	.row-value--link {
		color: var(--keel-color-primary);
	}

	.hint {
		display: block;
		padding: 24px 0;
		text-align: center;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
	}

	.send {
		margin-top: 28px;
	}
</style>
