<template>
	<!--
		选人页：发起群聊、给群加人共用

		⚠️ 必须是独立的非 tab 页。原来复用通讯录页，靠 navigateTo 带参数进「选人模式」，
		但通讯录改成 tab 页之后 navigateTo 会直接失败（can not navigateTo a tabbar page），
		表现是「添加成员」点了没反应——而 H5 上连报错都不打
	-->
	<view class="page">
		<view class="search">
			<input
				v-model="keyword"
				class="search-input"
				type="text"
				placeholder="搜索同事"
				confirm-type="search"
				@confirm="load"
			/>
		</view>

		<text class="tip">{{ addTo ? '勾选要加入群聊的同事' : '勾选至少 2 位同事' }}</text>

		<view v-if="candidates.length" class="panel">
			<view
				v-for="(c, i) in candidates"
				:key="c.id"
				class="row"
				:class="{ 'row--last': i === candidates.length - 1 }"
				hover-class="row--hover"
				@click="togglePick(c)"
			>
				<view class="check" :class="{ 'check--on': picked.includes(c.id) }">
					<text v-if="picked.includes(c.id)" class="check-tick">✓</text>
				</view>
				<view class="avatar">
					<image v-if="c.avatar" class="avatar-img" :src="absUrl(c.avatar)" mode="aspectFill" />
					<text v-else class="avatar-text">{{ c.real_name.slice(0, 1) }}</text>
				</view>
				<text class="row-name">{{ c.real_name }}</text>
			</view>
		</view>

		<text v-else class="hint">{{ loading ? '正在加载' : (error || '没有可选的同事') }}</text>

		<!-- 常驻底栏：按钮位置固定，选没选够一眼看得出（没选够时置灰） -->
		<view class="pickbar">
			<text class="pickbar-text">已选 {{ picked.length }} 人</text>
			<view
				class="pickbar-btn"
				:class="{ 'pickbar-btn--off': !enough || submitting }"
				hover-class="pickbar-btn--hover"
				@click="submit"
			>
				<text class="pickbar-btn-text">{{ addTo ? '添加' : '建群' }}</text>
			</view>
		</view>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onLoad } from '@dcloudio/uni-app'
	import { fetchChatContacts, fetchChatMembers, createChatGroup, addChatMembers } from '@/common/api.js'
	import { absUrl } from '@/common/request.js'

	const contacts = ref([])
	const keyword = ref('')
	const loading = ref(false)
	const error = ref('')
	const picked = ref([])
	const submitting = ref(false)
	/** 非 0 = 给这个群加人；0 = 发起群聊 */
	const addTo = ref(0)
	/** 加人时已在群里的人：不出现在候选里，免得勾了再被服务端 409 */
	const existing = ref([])

	const candidates = computed(() => contacts.value.filter((c) => !existing.value.includes(c.id)))

	/** 建群至少 2 人（两个人的「群」就是单聊，单聊有自己的去重）；加人 1 个就行 */
	const enough = computed(() => picked.value.length >= (addTo.value ? 1 : 2))

	async function load() {
		loading.value = true
		error.value = ''
		try {
			contacts.value = await fetchChatContacts(keyword.value.trim())
		} catch (e) {
			if (e.code !== 401) error.value = e.message
		} finally {
			loading.value = false
		}
	}

	function togglePick(c) {
		const i = picked.value.indexOf(c.id)
		if (i >= 0) picked.value.splice(i, 1)
		else picked.value.push(c.id)
	}

	async function submit() {
		if (submitting.value) return
		if (!enough.value) {
			uni.showToast({ title: addTo.value ? '请先勾选同事' : '群聊至少需要 2 位同事', icon: 'none' })
			return
		}

		submitting.value = true
		try {
			if (addTo.value) {
				await addChatMembers(addTo.value, picked.value)
				// 回到群成员页，它的 onShow 会重新拉名单
				uni.navigateBack()
				return
			}

			// 群名留空让服务端拼默认名——前端再拼一遍就是两份规则
			const conv = await createChatGroup(picked.value)
			// redirectTo：选人页用完就关掉，进了群再按返回回到消息页，而不是回到选人
			uni.redirectTo({ url: `/pages/chat/room?id=${conv.id}&name=${encodeURIComponent(conv.name)}` })
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		} finally {
			submitting.value = false
		}
	}

	onLoad(async (options) => {
		addTo.value = Number((options && options.add_to) || 0)
		uni.setNavigationBarTitle({ title: addTo.value ? '添加成员' : '发起群聊' })

		if (addTo.value) {
			try {
				existing.value = (await fetchChatMembers(addTo.value)).map((m) => m.user_id)
			} catch (e) {
				/* 拿不到就不排除，服务端仍会拦重复的 */
			}
		}
		await load()
	})
</script>

<style scoped>
	.page {
		min-height: calc(100vh - var(--window-top));
		padding: 12px 0 96px;
		box-sizing: border-box;
		background-color: var(--keel-bg-color-page);
	}

	.search {
		padding: 0 16px 8px;
	}

	.search-input {
		height: 36px;
		padding: 0 12px;
		font-size: 15px;
		color: var(--keel-text-color-primary);
		background-color: var(--keel-bg-color);
		border-radius: 10px;
	}

	.tip {
		display: block;
		padding: 4px 20px 10px;
		font-size: 13px;
		color: var(--keel-text-color-secondary);
	}

	.panel {
		margin: 0 16px;
		background-color: var(--keel-bg-color);
		border-radius: 12px;
		overflow: hidden;
	}

	.row {
		display: flex;
		flex-direction: row;
		align-items: center;
		padding: 12px 16px;
		border-bottom: 1px solid var(--keel-border-color-lighter);
	}

	.row--last {
		border-bottom: none;
	}

	.row--hover {
		background-color: var(--keel-pressed-bg);
	}

	.check {
		width: 20px;
		height: 20px;
		margin-right: 12px;
		border: 1px solid var(--keel-text-color-placeholder);
		border-radius: 10px;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.check--on {
		background-color: var(--keel-color-primary);
		border-color: var(--keel-color-primary);
	}

	.check-tick {
		font-size: 12px;
		color: #fff;
	}

	.avatar {
		width: 40px;
		height: 40px;
		margin-right: 12px;
		border-radius: 20px;
		background-color: var(--keel-color-primary);
		display: flex;
		align-items: center;
		justify-content: center;
		overflow: hidden;
		flex-shrink: 0;
	}

	.avatar-img {
		width: 40px;
		height: 40px;
	}

	.avatar-text {
		font-size: 16px;
		color: #fff;
	}

	.row-name {
		flex: 1;
		font-size: 16px;
		color: var(--keel-text-color-primary);
	}

	.hint {
		display: block;
		padding: 40px 20px;
		text-align: center;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.pickbar {
		position: fixed;
		left: 0;
		right: 0;
		bottom: 0;
		display: flex;
		flex-direction: row;
		align-items: center;
		padding: 10px 16px;
		padding-bottom: calc(10px + constant(safe-area-inset-bottom));
		padding-bottom: calc(10px + env(safe-area-inset-bottom));
		background-color: var(--keel-bg-color);
		border-top: 1px solid var(--keel-border-color-lighter);
	}

	.pickbar-text {
		flex: 1;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.pickbar-btn {
		padding: 0 20px;
		height: 36px;
		display: flex;
		align-items: center;
		background-color: var(--keel-color-primary);
		border-radius: 18px;
	}

	.pickbar-btn--off {
		opacity: 0.45;
	}

	.pickbar-btn--hover {
		transform: scale(0.96);
	}

	.pickbar-btn-text {
		font-size: 15px;
		color: #fff;
	}
</style>
