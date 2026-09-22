<template>
	<view class="screen">
		<!--
			不再是 tab 页（公告已降级为工作台里的入口），所以用的是原生导航栏——
			导航栏已经写着「公告」，页内再放一个大标题就是同一句话说两遍。
			这里只留状态行与操作
		-->
		<view class="head">
			<text class="head-text">{{ unread > 0 ? `${unread} 条未读` : '没有未读消息' }}</text>
			<text v-if="unread > 0" class="text-link head-action" @click="markAll">全部标为已读</text>
		</view>

		<view v-if="loading && list.length === 0" class="group state">
			<text class="state-text">正在加载消息</text>
		</view>

		<!-- 空态要说清楚「是真没有」而不是「加载失败」，并且不给一个点了没反应的按钮 -->
		<view v-else-if="list.length === 0" class="empty">
			<text class="empty-title">还没有公告</text>
			<text class="empty-desc">系统发布公告后会出现在这里，工作台上会显示未读数。</text>
		</view>

		<block v-else>
			<view class="group list">
				<view
					v-for="item in list"
					:key="item.id"
					class="row notice"
					hover-class="row-pressed"
					@click="open(item)"
				>
					<!-- 圆点列常驻占位：有没有未读，标题都从同一条竖线开始 -->
					<view class="dot-col">
						<view v-if="!item.is_read" class="dot"></view>
					</view>
					<view class="notice-main">
						<view class="notice-top">
							<text class="notice-title" :class="{ unread: !item.is_read }">{{ item.title }}</text>
							<text class="notice-time">{{ shortTime(item.published_at) }}</text>
						</view>
						<text class="notice-summary">{{ item.summary }}</text>
						<view class="notice-meta">
							<text class="notice-type" :class="{ urgent: item.type === 'urgent' }">{{ typeText(item.type) }}</text>
							<text class="notice-from">{{ item.publisher_name }}</text>
						</view>
					</view>
				</view>
			</view>

			<text class="fine-print more">{{ finished ? '没有更多了' : '正在加载更多' }}</text>
		</block>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
	import { fetchNotices, readAllNotices } from '@/common/api.js'

	const list = ref([])
	const unread = ref(0)
	const loading = ref(false)
	const finished = ref(false)
	const pageNum = ref(1)
	const PAGE_SIZE = 20

	const TYPE_TEXT = { notice: '通知', announcement: '公告', urgent: '紧急' }
	const typeText = (t) => TYPE_TEXT[t] || '通知'

	/*
	 * 列表里的时间只给「够用」的精度：今天的显示时刻，今年的显示月日，更早的显示完整日期。
	 * 完整时间在详情页里
	 */
	function shortTime(value) {
		if (!value) return ''
		const [date, time = ''] = String(value).split(' ')
		const now = new Date()
		const pad = (n) => String(n).padStart(2, '0')
		const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
		if (date === todayStr) return time.slice(0, 5)
		if (date.startsWith(String(now.getFullYear()))) return date.slice(5)
		return date
	}

	async function load(reset = false) {
		if (loading.value) return
		if (!reset && finished.value) return

		loading.value = true
		try {
			const page = reset ? 1 : pageNum.value
			const res = await fetchNotices(page, PAGE_SIZE)

			list.value = reset ? res.list : list.value.concat(res.list)
			unread.value = res.unread_count

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
			uni.showToast({ title: '已全部标为已读', icon: 'none' })
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

<style scoped>
	/*
	 * ⚠️ 覆盖 ui.css 里 .screen 的上内边距
	 *
	 * 那一条是 `calc(var(--status-bar-height) + 12px)`，给**自定义导航栏**的页面留状态栏。
	 * 这一页改用原生导航栏之后，状态栏已经被导航栏占掉了，再留一次就是白白顶下去一截。
	 * 这个坑在 H5 上看不出来（H5 的 --status-bar-height 是 0），只有真机才露。
	 */
	.screen {
		padding-top: 12px;
	}

	.head {
		display: flex;
		flex-direction: row;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 16px;
	}

	.head-text {
		flex: 1;
		min-width: 0;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
	}

	.head-action {
		padding-bottom: 2px;
	}

	/* ---------- 列表行 ---------- */

	/* 多行内容顶对齐；.row 默认是居中对齐的单行 */
	.notice {
		align-items: flex-start;
		padding-top: 14px;
		padding-bottom: 14px;
		padding-left: 8px;
	}

	/* 分隔线从标题起点开始，与圆点列之后的文字对齐 */
	.list .notice + .notice::before {
		left: 24px;
	}

	.dot-col {
		width: 16px;
		flex-shrink: 0;
		padding-top: 8px;
	}

	.dot {
		width: 8px;
		height: 8px;
		border-radius: 4px;
		margin-left: 4px;
		background-color: var(--keel-color-primary);
	}

	.notice-main {
		flex: 1;
		min-width: 0;
	}

	.notice-top {
		display: flex;
		flex-direction: row;
		align-items: baseline;
	}

	.notice-title {
		flex: 1;
		min-width: 0;
		font-size: 17px;
		line-height: 1.35;
		color: var(--keel-text-color-primary);
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	.notice-title.unread {
		font-weight: 600;
	}

	.notice-time {
		margin-left: 12px;
		flex-shrink: 0;
		font-size: 14px;
		font-variant-numeric: tabular-nums;
		color: var(--keel-text-color-secondary);
	}

	.notice-summary {
		display: -webkit-box;
		margin-top: 4px;
		font-size: 15px;
		line-height: 1.4;
		color: var(--keel-text-color-secondary);
		overflow: hidden;
		-webkit-box-orient: vertical;
		-webkit-line-clamp: 2;
	}

	.notice-meta {
		display: flex;
		flex-direction: row;
		margin-top: 6px;
	}

	.notice-type,
	.notice-from {
		font-size: 12px;
		color: var(--keel-text-color-placeholder);
	}

	.notice-from {
		margin-left: 8px;
	}

	/* 紧急是唯一需要被一眼看到的类型，只有它上色 */
	.notice-type.urgent {
		font-weight: 600;
		color: var(--keel-color-danger);
	}

	/* ---------- 加载 / 空态 / 页脚 ---------- */

	.state {
		padding: 20px;
	}

	.state-text {
		display: block;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
		text-align: center;
	}

	.empty {
		margin-top: 72px;
		padding: 0 24px;
	}

	.empty-title {
		display: block;
		font-size: 21px;
		font-weight: 600;
		color: var(--keel-text-color-primary);
		text-align: center;
	}

	.empty-desc {
		display: block;
		margin-top: 8px;
		font-size: 15px;
		line-height: 1.47;
		color: var(--keel-text-color-secondary);
		text-align: center;
	}

	.more {
		margin-top: 16px;
		text-align: center;
	}
</style>
