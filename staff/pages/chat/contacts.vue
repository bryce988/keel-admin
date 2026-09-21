<template>
	<!-- iOS 式大标题 + 内嵌分组列表（staff/docs/DESIGN.md） -->
	<view class="page">
		<view class="hero">
			<text class="hero-title">{{ addTo ? '添加成员' : '通讯录' }}</text>
			<text class="hero-sub">{{ addTo ? '勾选要加入群聊的同事' : '点击同事发起对话，或勾选多人建群' }}</text>
		</view>

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

		<view v-if="contacts.length" class="panel">
			<view
				v-for="(c, i) in contacts"
				:key="c.id"
				class="row"
				:class="{ 'row--last': i === contacts.length - 1 }"
				hover-class="row--hover"
				@click="onTap(c)"
			>
				<!-- 勾选进入建群模式；没勾时点行是单聊。
				     两个动作共用一行，比「先选模式再选人」少一步 -->
				<view class="check" :class="{ 'check--on': picked.includes(c.id) }" @click.stop="togglePick(c)">
					<text v-if="picked.includes(c.id)" class="check-tick">✓</text>
				</view>
				<view class="avatar">
					<image v-if="c.avatar" class="avatar-img" :src="absUrl(c.avatar)" mode="aspectFill" />
					<text v-else class="avatar-text">{{ c.real_name.slice(0, 1) }}</text>
				</view>
				<view class="row-body">
					<text class="row-name">{{ c.real_name }}</text>
					<text class="row-sub">{{ c.username }}</text>
				</view>
				<text v-if="!picked.length" class="row-arrow">›</text>
			</view>
		</view>

		<!-- 勾了人才出现，不占常驻空间。固定在底部，列表长了也够得着 -->
		<view v-if="picked.length" class="pickbar">
			<text class="pickbar-text">已选 {{ picked.length }} 人</text>
			<text class="pickbar-clear" @click="picked = []">清空</text>
			<view class="pickbar-btn" hover-class="pickbar-btn--hover" @click="submitGroup">
				<text class="pickbar-btn-text">{{ addTo ? '添加' : '建群' }}</text>
			</view>
		</view>

		<text v-else class="hint">{{ loading ? '正在加载' : (error || '没有找到同事') }}</text>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onLoad, onShow, onUnload, onPullDownRefresh } from '@dcloudio/uni-app'
	import { fetchChatContacts, openChatConversation, createChatGroup, addChatMembers } from '@/common/api.js'
	import { absUrl } from '@/common/request.js'

	const contacts = ref([])
	const keyword = ref('')
	const loading = ref(false)
	const error = ref('')
	/** 勾中的人。非空即进入建群模式 */
	const picked = ref([])
	/**
	 * 非 0 = 「给这个群加人」模式，而不是建群
	 *
	 * 复用同一页：选人这件事两边完全一样，差的只是确认后调哪个接口。
	 * 单开一页的话，搜索、部门筛选、勾选这些要维护两份
	 */
	const addTo = ref(0)

	async function load() {
		loading.value = true
		error.value = ''
		try {
			contacts.value = await fetchChatContacts(keyword.value.trim())
		} catch (e) {
			// 401 已经由 request 层统一跳登录了，这里再提示一次是重复打扰
			if (e.code !== 401) error.value = e.message
		} finally {
			loading.value = false
		}
	}

	/**
	 * 打开会话
	 *
	 * 会话 id 由服务端给（已存在就返回已有的），前端不自己拼——
	 * 「一对人只有一个会话」是数据库唯一索引保证的，不是前端约定。
	 */
	function togglePick(c) {
		const i = picked.value.indexOf(c.id)
		if (i >= 0) picked.value.splice(i, 1)
		else picked.value.push(c.id)
	}

	/** 已经勾了人就继续勾，否则点行直接开单聊 */
	function onTap(c) {
		if (picked.value.length) togglePick(c)
		else open(c)
	}

	/**
	 * 建群
	 *
	 * 至少 2 人：两个人的「群」就是单聊，而单聊有自己的去重逻辑。
	 * 群名留空让服务端拼默认名——前端再拼一遍就是两份规则。
	 */
	async function submitGroup() {
		// 加人模式：确认后回到群成员页，它的 onShow 会重新拉名单
		if (addTo.value) {
			try {
				await addChatMembers(addTo.value, picked.value)
				picked.value = []
				uni.navigateBack()
			} catch (e) {
				if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
			}
			return
		}

		if (picked.value.length < 2) {
			uni.showToast({ title: '群聊至少需要 2 位同事', icon: 'none' })
			return
		}

		try {
			const conv = await createChatGroup(picked.value)
			picked.value = []
			uni.navigateTo({
				url: `/pages/chat/room?id=${conv.id}&name=${encodeURIComponent(conv.name)}`
			})
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	async function open(contact) {
		try {
			const conv = await openChatConversation(contact.id)
			/*
			 * 必须 navigateTo，不能 redirectTo
			 *
			 * 通讯录现在是 tabBar 页，而 redirectTo 的语义是「关掉当前页再打开新的」——
			 * tab 页关不掉。用 navigateTo 压栈，返回时回到通讯录，这也符合
			 * 「我在翻通讯录，聊两句再接着翻」的实际用法。
			 */
			uni.navigateTo({
				url: `/pages/chat/room?id=${conv.id}&name=${encodeURIComponent(conv.name)}`
			})
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	/*
	 * ⚠️ 它既是 tab 页也能被 navigateTo 打开（加人模式）
	 *
	 * tab 页的 onLoad 只在**第一次**触发，之后切 tab 只走 onShow。
	 * 而 navigateTo 过来是一个新实例，onLoad 会触发。所以 addTo 在 onLoad
	 * 里读、在 onUnload 里清——只靠 onShow 的话，从加人模式退出后再切到
	 * 通讯录 tab，它还停在加人模式
	 */
	onLoad((options) => {
		addTo.value = Number((options && options.add_to) || 0)
		if (addTo.value) {
			uni.setNavigationBarTitle({ title: '添加成员' })
		}
	})

	onUnload(() => {
		addTo.value = 0
		picked.value = []
	})

	// onShow 而不是 onLoad：它是 tab 页，切回来时人员可能已经变了（有人入职/离职）
	onShow(load)
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

	.hero-title {
		display: block;
		font-size: 30px;
		font-weight: 700;
		color: #1d1d1f;
	}

	.hero-sub {
		display: block;
		margin-top: 4px;
		font-size: 14px;
		color: #86868b;
	}

	.search {
		padding: 0 20px 12px;
	}

	.search-input {
		height: 36px;
		padding: 0 12px;
		font-size: 15px;
		color: #1d1d1f;
		background: #ffffff;
		border-radius: 10px;
	}

	/* 一整块白面板 + 缩进细线，不是一张张独立卡片 */
	.panel {
		margin: 0 20px;
		background: #ffffff;
		border-radius: 12px;
		overflow: hidden;
	}

	.row {
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
		width: 40px;
		height: 40px;
		margin-right: 12px;
		border-radius: 20px;
		background: #409eff;
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
		color: #ffffff;
	}

	.row-body {
		flex: 1;
		min-width: 0;
	}

	.row-name {
		display: block;
		font-size: 16px;
		color: #1d1d1f;
	}

	.row-sub {
		display: block;
		margin-top: 2px;
		font-size: 13px;
		color: #86868b;
	}

	.check {
		width: 20px;
		height: 20px;
		margin-right: 10px;
		border: 1px solid #c7c7cc;
		border-radius: 10px;
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}

	.check--on {
		background: #409eff;
		border-color: #409eff;
	}

	.check-tick {
		font-size: 12px;
		color: #ffffff;
	}

	/* 固定在底部：列表长了也够得着，不用滚回去找 */
	.pickbar {
		position: fixed;
		left: 0;
		right: 0;
		bottom: var(--window-bottom);
		display: flex;
		align-items: center;
		padding: 10px 16px;
		padding-bottom: calc(10px + env(safe-area-inset-bottom));
		background: #ffffff;
		border-top: 1px solid #f0f0f2;
	}

	.pickbar-text {
		flex: 1;
		font-size: 14px;
		color: #6e6e73;
	}

	.pickbar-clear {
		margin-right: 14px;
		font-size: 14px;
		color: #409eff;
	}

	.pickbar-btn {
		padding: 0 18px;
		height: 34px;
		display: flex;
		align-items: center;
		background: #409eff;
		border-radius: 17px;
	}

	.pickbar-btn--hover {
		transform: scale(0.96);
	}

	.pickbar-btn-text {
		font-size: 15px;
		color: #ffffff;
	}

	.row-arrow {
		font-size: 20px;
		color: #c7c7cc;
	}

	.hint {
		display: block;
		padding: 40px 20px;
		text-align: center;
		font-size: 14px;
		color: #86868b;
	}
</style>
