<template>
	<view class="page">
		<view class="bar">
			<text class="bar-text">{{ unread > 0 ? `${unread} 条未读` : '没有未读消息' }}</text>
			<text v-if="unread > 0" class="bar-action" @click="markAll">全部已读</text>
		</view>

		<view v-if="loading && list.length === 0" class="hint">
			<text class="hint-text">加载中…</text>
		</view>

		<!-- 空态要说清楚「是真没有」而不是「加载失败」，并且不给一个点了没反应的按钮 -->
		<view v-else-if="list.length === 0" class="empty">
			<text class="empty-title">还没有公告</text>
			<text class="empty-desc">系统发布公告后会出现在这里，并在下方标签上显示未读角标。</text>
		</view>

		<view v-else>
			<view class="item" v-for="item in list" :key="item.id" @click="open(item)">
				<view class="item-head">
					<!-- 未读用圆点而不是整条变色：整条变色在长列表里会糊成一片 -->
					<view v-if="!item.is_read" class="dot"></view>
					<text class="item-title" :class="{ unread: !item.is_read }">{{ item.title }}</text>
					<text class="tag" :class="'tag-' + item.type">{{ typeText(item.type) }}</text>
				</view>
				<text class="item-summary">{{ item.summary }}</text>
				<view class="item-foot">
					<text class="item-meta">{{ item.publisher_name }}</text>
					<text class="item-meta">{{ item.published_at }}</text>
				</view>
			</view>

			<view class="more">
				<text class="more-text">{{ finished ? '没有更多了' : '加载中…' }}</text>
			</view>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
	import { fetchNotices, readAllNotices, setNoticeBadge } from '@/common/api.js'

	const list = ref([])
	const unread = ref(0)
	const loading = ref(false)
	const finished = ref(false)
	const pageNum = ref(1)
	const PAGE_SIZE = 20

	const TYPE_TEXT = { notice: '通知', announcement: '公告', urgent: '紧急' }
	const typeText = (t) => TYPE_TEXT[t] || '通知'

	async function load(reset = false) {
		if (loading.value) return
		if (!reset && finished.value) return

		loading.value = true
		try {
			const page = reset ? 1 : pageNum.value
			const res = await fetchNotices(page, PAGE_SIZE)

			list.value = reset ? res.list : list.value.concat(res.list)
			unread.value = res.unread_count
			setNoticeBadge(res.unread_count)

			pageNum.value = page + 1
			finished.value = list.value.length >= res.total
		} catch (e) {
			if (e.code !== 401) {
				uni.showToast({ title: e.message, icon: 'none' })
			}
		} finally {
			loading.value = false
		}
	}

	function open(item) {
		uni.navigateTo({ url: '/pages/notice/detail?id=' + item.id })
	}

	async function markAll() {
		try {
			await readAllNotices()
			await load(true)
			uni.showToast({ title: '已全部标记为已读', icon: 'none' })
		} catch (e) {
			uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	/*
	 * 每次进页面都整表重载（reset）
	 *
	 * 从详情页返回时这一条已经变成已读了，只补差量的话列表上那个圆点还在，
	 * 而角标已经减了一 —— 用户看到的是自相矛盾的两处。公告一页 20 条，重载很便宜。
	 */
	onShow(() => {
		load(true)
	})

	onPullDownRefresh(async () => {
		await load(true)
		uni.stopPullDownRefresh()
	})

	onReachBottom(() => {
		load(false)
	})
</script>

<style>
	.page {
		flex: 1;
		padding: 12px 16px 24px;
		background-color: #F5F6F8;
	}

	.bar {
		display: flex;
		flex-direction: row;
		align-items: center;
		justify-content: space-between;
		padding: 4px 2px 10px;
	}

	.bar-text {
		font-size: 13px;
		color: #8A8F99;
	}

	.bar-action {
		font-size: 13px;
		color: #2B6CF6;
	}

	.item {
		padding: 14px;
		margin-bottom: 10px;
		border-radius: 10px;
		background-color: #FFFFFF;
	}

	.item-head {
		display: flex;
		flex-direction: row;
		align-items: center;
	}

	.dot {
		width: 7px;
		height: 7px;
		border-radius: 4px;
		margin-right: 6px;
		background-color: #E54545;
	}

	.item-title {
		flex: 1;
		font-size: 15px;
		color: #1F2329;
	}

	.item-title.unread {
		font-weight: bold;
	}

	.tag {
		margin-left: 8px;
		padding: 1px 6px;
		border-radius: 4px;
		font-size: 11px;
		color: #64748B;
		background-color: #EFF1F4;
	}

	.tag-urgent {
		color: #E54545;
		background-color: #FDECEC;
	}

	.tag-announcement {
		color: #2B6CF6;
		background-color: #EAF1FE;
	}

	.item-summary {
		display: block;
		margin-top: 6px;
		font-size: 13px;
		color: #8A8F99;
		line-height: 19px;
	}

	.item-foot {
		display: flex;
		flex-direction: row;
		justify-content: space-between;
		margin-top: 10px;
	}

	.item-meta {
		font-size: 11px;
		color: #A8ADB5;
	}

	.hint, .more {
		padding: 20px;
	}

	.hint-text, .more-text {
		font-size: 12px;
		color: #A8ADB5;
		text-align: center;
	}

	.empty {
		margin-top: 60px;
		padding: 0 32px;
	}

	.empty-title {
		font-size: 16px;
		color: #1F2329;
		text-align: center;
	}

	.empty-desc {
		display: block;
		margin-top: 8px;
		font-size: 12px;
		color: #8A8F99;
		line-height: 19px;
		text-align: center;
	}
</style>
