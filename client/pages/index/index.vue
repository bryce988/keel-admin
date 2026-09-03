<template>
	<view class="page">
		<view class="hero">
			<text class="hello">{{ greeting }}</text>
			<text class="hero-sub">这是 Keel 的 C 端示例，链路是真的：登录、取资料、传头像都打到后端。</text>
		</view>

		<view class="grid">
			<view class="tile" v-for="item in tiles" :key="item.title">
				<text class="tile-title">{{ item.title }}</text>
				<text class="tile-desc">{{ item.desc }}</text>
			</view>
		</view>

		<view class="note">
			<text class="note-title">接着做什么</text>
			<text class="note-body">后端接口写在 server/app/client/controller/v1/ 下，前端接口写在 common/api.js 里，两端共用一份契约：docs/api.md §12.3。</text>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { getCachedUser } from '@/common/request.js'

	const greeting = ref('你好')

	const tiles = [
		{ title: '渠道头', desc: 'X-Channel / X-App-Version / X-Device-Id 缺一即 400' },
		{ title: '两套身份', desc: '员工令牌调 C 端接口一律 401，反过来也一样' },
		{ title: '响应裁剪', desc: 'C 端只拿到白名单字段，手机号中间四位打码' },
		{ title: '错误体', desc: '只有 code 与 message，不给 trace_id' }
	]

	/*
	 * 用 onShow 而不是 onLoad
	 *
	 * tabBar 页面切走再切回来不会重新 load。在「我的」里改了昵称，
	 * 回首页得能看到新的——只写 onLoad 的话要杀掉应用重进才会变。
	 */
	onShow(() => {
		const user = getCachedUser()
		greeting.value = user && user.nickname ? '你好，' + user.nickname : '你好'
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
		margin-bottom: 16px;
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
		line-height: 20px;
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

	.tile-title {
		font-size: 15px;
		font-weight: bold;
		color: #1F2329;
	}

	.tile-desc {
		display: block;
		margin-top: 6px;
		font-size: 12px;
		color: #8A8F99;
		line-height: 18px;
	}

	.note {
		padding: 16px;
		border-radius: 10px;
		background-color: #FFFFFF;
	}

	.note-title {
		font-size: 15px;
		font-weight: bold;
		color: #1F2329;
	}

	.note-body {
		display: block;
		margin-top: 6px;
		font-size: 12px;
		color: #8A8F99;
		line-height: 20px;
	}
</style>
