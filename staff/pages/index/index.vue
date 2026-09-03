<template>
	<view class="page">
		<view class="hero">
			<text class="hello">{{ greeting }}</text>
			<text class="hero-sub">{{ deptLine }}</text>
		</view>

		<!-- 有 sys:dashboard:view 才显示工作台。没有的人看到的是下面那块说明，
		     而不是一片空白或者一个红色报错——他没权限不是出错 -->
		<block v-if="canDashboard">
			<view class="section-title">
				<text class="section-text">工作台</text>
				<text class="section-more" @click="load">刷新</text>
			</view>

			<view v-if="loading" class="placeholder">
				<text class="placeholder-text">加载中…</text>
			</view>

			<view v-else class="grid">
				<view class="tile" v-for="item in stats" :key="item.key">
					<text class="tile-label">{{ item.label }}</text>
					<view class="tile-value-row">
						<text class="tile-value" :class="'tone-' + item.tone">{{ item.value }}</text>
						<text class="tile-unit">{{ item.unit }}</text>
					</view>
					<text class="tile-hint">{{ item.hint }}</text>
				</view>
			</view>
		</block>

		<view v-else class="card">
			<text class="card-title">没有工作台权限</text>
			<text class="card-body">你的账号没有 sys:dashboard:view 权限点，所以看不到概览数据。这不是出错——权限由后台的角色授权决定，找管理员开通即可。</text>
		</view>

		<view class="card">
			<text class="card-title">这个 App 是什么</text>
			<text class="card-body">Keel 的移动工作台，给系统人员用：登的是后台同一套账号（sys_users），同一套权限点、同一份数据权限。但接口是员工移动端自己的一套 /staff/v1/*，不直接调后台接口——身份共用，接口不共用。</text>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { fetchWorkbench, setNoticeBadge } from '@/common/api.js'
	import { getCachedUser } from '@/common/request.js'

	const greeting = ref('你好')
	const deptLine = ref('')
	const stats = ref([])
	const loading = ref(false)
	const canDashboard = ref(false)

	/**
	 * 一个请求把首页要的都拿回来
	 *
	 * 身份、权限点、概览数字都在 /staff/v1/workbench 里。后台那边这是三个接口，
	 * 在宽屏上无所谓，在手机上每多一次往返就多一次转圈。
	 */
	async function load() {
		loading.value = true
		try {
			const res = await fetchWorkbench()
			const user = res.user || {}
			greeting.value = '你好，' + (user.real_name || user.username)
			deptLine.value = (user.dept_name || '未分配部门') + (user.is_super ? ' · 超级管理员' : '')

			// 能不能看概览由服务端说了算，不看本地缓存的权限点
			canDashboard.value = !!(res.dashboard && res.dashboard.visible)
			stats.value = (res.dashboard && res.dashboard.stats) || []

			// 工作台顺带把未读数带回来了，省掉一次单独的角标请求
			setNoticeBadge(res.unread_notice || 0)
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
		// 先用缓存把问候语顶上，避免请求回来之前是一片空白；随后 load() 会覆盖成最新的
		const user = getCachedUser()
		if (user) {
			greeting.value = '你好，' + (user.real_name || user.username)
			deptLine.value = (user.dept_name || '未分配部门') + (user.is_super ? ' · 超级管理员' : '')
		}
		load()
	})
</script>

<style>
	.page {
		flex: 1;
		padding: 20px;
		background-color: #F5F6F8;
	}

	.hero {
		padding: 20px;
		border-radius: 12px;
		background-color: #2B6CF6;
		margin-bottom: 18px;
	}

	.hello {
		font-size: 22px;
		font-weight: bold;
		color: #FFFFFF;
	}

	.hero-sub {
		display: block;
		margin-top: 8px;
		font-size: 13px;
		color: #DCE6FF;
	}

	.section-title {
		display: flex;
		flex-direction: row;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 10px;
	}

	.section-text {
		font-size: 15px;
		font-weight: bold;
		color: #1F2329;
	}

	.section-more {
		font-size: 13px;
		color: #2B6CF6;
	}

	.placeholder {
		padding: 28px;
		border-radius: 10px;
		background-color: #FFFFFF;
		margin-bottom: 12px;
	}

	.placeholder-text {
		font-size: 13px;
		color: #8A8F99;
		text-align: center;
	}

	/* 两列用 space-between 分，不给每块加右外边距：48% + 4% 会让一行超过 100% 直接塌成一列 */
	.grid {
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		justify-content: space-between;
	}

	.tile {
		width: 48%;
		margin-bottom: 12px;
		padding: 14px;
		border-radius: 10px;
		background-color: #FFFFFF;
		box-sizing: border-box;
	}

	.tile-label {
		font-size: 13px;
		color: #8A8F99;
	}

	.tile-value-row {
		display: flex;
		flex-direction: row;
		align-items: baseline;
		margin-top: 4px;
	}

	.tile-value {
		font-size: 26px;
		font-weight: bold;
		color: #1F2329;
	}

	.tile-unit {
		margin-left: 4px;
		font-size: 12px;
		color: #8A8F99;
	}

	.tile-hint {
		display: block;
		margin-top: 4px;
		font-size: 11px;
		color: #A8ADB5;
	}

	/* 与后台的语义色对齐，别在这里另起一套配色 */
	.tone-primary { color: #2B6CF6; }
	.tone-success { color: #16A34A; }
	.tone-warning { color: #D97706; }
	.tone-info    { color: #64748B; }
	.tone-danger  { color: #E54545; }

	.card {
		padding: 16px;
		border-radius: 10px;
		background-color: #FFFFFF;
		margin-bottom: 12px;
	}

	.card-title {
		font-size: 15px;
		font-weight: bold;
		color: #1F2329;
	}

	.card-body {
		display: block;
		margin-top: 6px;
		font-size: 12px;
		color: #8A8F99;
		line-height: 20px;
	}
</style>
