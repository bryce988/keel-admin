<template>
	<!-- iOS 式大标题 + 内嵌分组列表（staff/docs/DESIGN.md）。只负责浏览：点人看名片；
	     建群、加人在独立的选人页 pick.vue（tab 页没法被 navigateTo 打开） -->
	<view class="page">
		<view class="hero">
			<text class="hero-title">通讯录</text>
			<text class="hero-sub">点击同事查看资料</text>
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
				@click="toProfile(c)"
			>
				<view class="avatar">
					<image v-if="c.avatar" class="avatar-img" :src="absUrl(c.avatar)" mode="aspectFill" />
					<text v-else class="avatar-text">{{ c.real_name.slice(0, 1) }}</text>
				</view>
				<view class="row-body">
					<text class="row-name">{{ c.real_name }}</text>
					<text class="row-sub">{{ c.username }}</text>
				</view>
				<text class="row-arrow">›</text>
			</view>
		</view>

		<text v-else class="hint">{{ loading ? '正在加载' : (error || '没有找到同事') }}</text>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow, onPullDownRefresh } from '@dcloudio/uni-app'
	import { fetchChatContacts } from '@/common/api.js'
	import { absUrl } from '@/common/request.js'

	const contacts = ref([])
	const keyword = ref('')
	const loading = ref(false)
	const error = ref('')

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
			uni.stopPullDownRefresh()
		}
	}

	/**
	 * 点人看名片（钉钉同款），名片上再「发消息」
	 *
	 * 原来点人直接进单聊。但翻通讯录多半是为了「找人、看他在哪个部门、要个电话」，
	 * 直接把人拉进聊天，只想查资料的每次都得再退出来
	 */
	function toProfile(c) {
		uni.navigateTo({ url: `/pages/chat/profile?id=${c.id}&name=${encodeURIComponent(c.real_name)}` })
	}

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
