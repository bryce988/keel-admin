<template>
	<view class="screen">
		<text class="large-title">工作台</text>
		<text class="large-title-sub">{{ today }}</text>

		<!-- 全 App 唯一的深色面：Apple 式的明暗区块交替，这里只用一次，让「这是谁的工作台」最先被看到 -->
		<view class="welcome">
			<text class="welcome-name">{{ greeting }}</text>
			<view class="welcome-meta">
				<text class="welcome-dept">{{ deptName }}</text>
				<text v-if="isSuper" class="welcome-badge">超级管理员</text>
			</view>
		</view>

		<!--
			工作台是入口聚合页：聊天与通讯录已经是 tabBar 项，不在这里重复。

			公告入口只给没有聊天权限的人：有聊天权限的人在「消息」列表最上面就能看到「系统公告」，
			这里再放一个就是同一件事两个入口、两个红点。没有 chat:use 的人进不了消息列表，
			这里是他们唯一的公告入口，所以对他们保留
		-->
		<view v-if="showNoticeEntry" class="section-head">
			<text class="section-title">常用</text>
		</view>
		<view v-if="showNoticeEntry" class="group">
			<view class="entry" hover-class="entry--hover" @click="toNotice">
				<text class="entry-name">公告</text>
				<view class="entry-right">
					<text v-if="noticeUnread > 0" class="entry-badge">{{ noticeUnread > 99 ? '99+' : noticeUnread }}</text>
					<text class="entry-arrow">›</text>
				</view>
			</view>
		</view>

		<!-- 有 sys:dashboard:view 才显示工作台。没有的人看到的是下面那块说明，
		     而不是一片空白或者一个红色报错——他没权限不是出错 -->
		<block v-if="canDashboard">
			<view class="section-head">
				<text class="section-title">概览</text>
				<text class="text-link" @click="load">刷新</text>
			</view>

			<view v-if="loading && stats.length === 0" class="group state">
				<text class="state-text">正在加载概览</text>
			</view>

			<!-- 四格同在一块面板里，用细线分隔，而不是四张独立卡片 -->
			<view v-else class="group stats">
				<view
					v-for="(item, i) in stats"
					:key="item.key"
					class="stat"
					:class="{ 'stat-right': i % 2 === 1, 'stat-lower': i >= 2 }"
				>
					<text class="stat-label">{{ item.label }}</text>
					<view class="stat-value-row">
						<text class="stat-value">{{ item.value }}</text>
						<text class="stat-unit">{{ item.unit }}</text>
					</view>
					<text class="stat-hint" :class="{ 'is-alert': item.tone === 'danger' }">{{ item.hint }}</text>
				</view>
			</view>
		</block>

		<view v-else class="group state">
			<text class="state-title">没有工作台权限</text>
			<text class="state-body">概览需要 sys:dashboard:view 权限点。这不是出错——权限由后台的角色授权决定，找管理员开通即可。</text>
		</view>

		<text class="fine-print about">Keel 移动工作台与后台共用账号、权限点和数据权限，接口是移动端独立的一套 /staff/v1/*。</text>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { fetchWorkbench } from '@/common/api.js'
	import { can, getCachedUser } from '@/common/request.js'

	const greeting = ref('你好')
	const deptName = ref('')
	const isSuper = ref(false)
	const stats = ref([])
	const loading = ref(false)
	const canDashboard = ref(false)
	/** 公告未读数，同时用于入口右侧的角标与 tabBar 角标 */
	const noticeUnread = ref(0)
	/** 权限点是登录时缓存的快照；每次进页面重算，换号登录后不会沿用上一个人的 */
	const showNoticeEntry = ref(true)

	const WEEKDAYS = '日一二三四五六'
	const now = new Date()
	const today = `${now.getMonth() + 1}月${now.getDate()}日 星期${WEEKDAYS[now.getDay()]}`

	function applyUser(user) {
		greeting.value = '你好，' + (user.real_name || user.username)
		deptName.value = user.dept_name || '未分配部门'
		isSuper.value = !!user.is_super
	}

	/**
	 * 一个请求把首页要的都拿回来
	 *
	 * 身份、权限点、概览数字都在 /staff/v1/workbench 里。后台那边这是三个接口，
	 * 在宽屏上无所谓，在手机上每多一次往返就多一次转圈。
	 */
	/** 公告不是 tab 页，用 navigateTo 压栈，返回时回到工作台 */
	function toNotice() {
		uni.navigateTo({ url: '/pages/notice/list' })
	}

	async function load() {
		loading.value = true
		try {
			const res = await fetchWorkbench()
			applyUser(res.user || {})

			// 能不能看概览由服务端说了算，不看本地缓存的权限点
			canDashboard.value = !!(res.dashboard && res.dashboard.visible)
			stats.value = (res.dashboard && res.dashboard.stats) || []

			// 工作台顺带把未读数带回来了，只标在页面里的「公告」入口上。
			// 不再标到工作台 tab：未读公告已经计入「消息」tab 的总数，两个 tab 都标就是一条算两次
			noticeUnread.value = res.unread_notice || 0
		} catch (e) {
			// 401 已经在 request 层踢回登录页了，这里只处理别的错
			if (e.code !== 401) {
				uni.showToast({ title: e.message, icon: 'none' })
			}
		} finally {
			loading.value = false
		}
	}

	/*
	 * 用 onShow 而不是 onLoad
	 *
	 * tabBar 页面切走再切回来不会重新 load。在「我的」里改了姓名，
	 * 回首页得能看到新的——只写 onLoad 的话要杀掉应用重进才会变。
	 */
	onShow(() => {
		showNoticeEntry.value = !can('chat:use')
		// 先用缓存把问候语顶上，避免请求回来之前是一片空白；随后 load() 会覆盖成最新的
		const user = getCachedUser()
		if (user) {
			applyUser(user)
		}
		load()
	})
</script>

<style scoped>
	/* ---------- 问候区：深色面 ---------- */

	.welcome {
		margin-top: 20px;
		padding: 24px 20px;
		border-radius: var(--keel-radius-lg);
		background-color: var(--keel-surface-dark);
	}

	.welcome-name {
		display: block;
		font-size: 28px;
		font-weight: 600;
		line-height: 1.14;
		letter-spacing: -0.28px;
		color: #fff;
	}

	.welcome-meta {
		display: flex;
		flex-direction: row;
		align-items: center;
		flex-wrap: wrap;
		margin-top: 10px;
	}

	.welcome-dept {
		font-size: 15px;
		color: var(--keel-text-on-dark-muted);
	}

	/* 深色面上的胶囊：白色低透明度，不引入第二种颜色 */
	.welcome-badge {
		margin-left: 10px;
		padding: 2px 10px;
		border-radius: var(--keel-radius-pill);
		font-size: 12px;
		line-height: 18px;
		color: #fff;
		background-color: rgba(255, 255, 255, 0.14);
	}

	/* ---------- 概览：一块面板里的 2×2 ---------- */

	.stats {
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
	}

	.stat {
		position: relative;
		width: 50%;
		padding: 16px;
		box-sizing: border-box;
	}

	/* 分隔线用伪元素画 0.5px：直接 border 在高分屏上是 2~3 个物理像素，显粗 */
	.stat-right::before,
	.stat-lower::after {
		content: '';
		position: absolute;
		background-color: var(--keel-border-color-lighter);
	}

	.stat-right::before {
		top: 16px;
		bottom: 16px;
		left: 0;
		width: 1px;
		transform: scaleX(0.5);
	}

	/* 横线两格拼成一条：左格从 16px 缩进画到右边界，右格从左边界画到 16px 缩进 */
	.stat-lower::after {
		top: 0;
		left: 16px;
		right: 0;
		height: 1px;
		transform: scaleY(0.5);
	}

	.stat-lower.stat-right::after {
		left: 0;
		right: 16px;
	}

	.stat-label {
		display: block;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.stat-value-row {
		display: flex;
		flex-direction: row;
		align-items: baseline;
		margin-top: 6px;
	}

	.stat-value {
		font-size: 34px;
		font-weight: 600;
		line-height: 1.1;
		letter-spacing: -0.374px;
		font-variant-numeric: tabular-nums;
		color: var(--keel-text-color-primary);
	}

	.stat-unit {
		margin-left: 4px;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.stat-hint {
		display: block;
		margin-top: 6px;
		font-size: 12px;
		line-height: 1.4;
		color: var(--keel-text-color-placeholder);
	}

	/*
	 * 只有告警上色：后端的 tone 前三张是固定的卡片配色（primary/success/warning），
	 * 不代表状态；真正的信号只有「今日登录失败 > 0」时的 danger。
	 * 数字本身一律用墨色——单一强调色的规则下，彩色数字只是装饰
	 */
	.stat-hint.is-alert {
		color: var(--keel-color-danger);
	}

	/* ---------- 加载 / 无权限 ---------- */

	.state {
		padding: 20px;
	}

	.state-text {
		display: block;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
		text-align: center;
	}

	.state-title {
		display: block;
		font-size: 17px;
		font-weight: 600;
		color: var(--keel-text-color-primary);
	}

	.state-body {
		display: block;
		margin-top: 6px;
		font-size: 14px;
		line-height: 1.47;
		color: var(--keel-text-color-secondary);
	}

	/* 没权限时这块紧跟在问候区下面，要和上面拉开距离 */
	.welcome + .state {
		margin-top: 20px;
	}

	.about {
		margin: 28px 4px 0;
	}

	.entry {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 14px 16px;
	}

	.entry--hover {
		background: #f5f5f7;
	}

	.entry-name {
		font-size: 16px;
		color: #1d1d1f;
	}

	.entry-right {
		display: flex;
		align-items: center;
	}

	.entry-badge {
		margin-right: 8px;
		min-width: 18px;
		height: 18px;
		padding: 0 5px;
		border-radius: 9px;
		background: #f56c6c;
		color: #ffffff;
		font-size: 11px;
		line-height: 18px;
		text-align: center;
	}

	.entry-arrow {
		font-size: 20px;
		color: #c7c7cc;
	}
</style>
