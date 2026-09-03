<template>
	<view class="page">
		<view v-if="notice" class="card">
			<text class="title">{{ notice.title }}</text>
			<view class="meta">
				<text class="meta-text">{{ notice.publisher_name }}</text>
				<text class="meta-text">{{ notice.published_at }}</text>
			</view>

			<!--
				正文是后台富文本编辑器存的 HTML，用 rich-text 渲染。
				不用 v-html：小程序端没有 v-html，App 端也不该往页面里插任意 HTML。
				rich-text 只认白名单标签，顺带把 XSS 面收窄了。
			-->
			<rich-text class="content" :nodes="notice.content"></rich-text>
		</view>

		<view v-else class="hint">
			<text class="hint-text">{{ error || '加载中…' }}</text>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onLoad } from '@dcloudio/uni-app'
	import { readNotice } from '@/common/api.js'

	const notice = ref(null)
	const error = ref('')

	/*
	 * 打开即已读
	 *
	 * 服务端在返回正文的同一个请求里落了已读回执，前端不需要再发一次标记请求——
	 * 拆成两个请求的话，第二个失败时界面显示已读、库里还是未读。
	 * 列表页用 onShow 整表重载，返回时那条的圆点会跟着消失。
	 */
	onLoad(async (options) => {
		try {
			notice.value = await readNotice(options.id)
			// 标题栏用公告标题，比一律「公告详情」更好认——尤其从系统通知点进来时
			uni.setNavigationBarTitle({ title: notice.value.title.slice(0, 16) })
		} catch (e) {
			if (e.code !== 401) {
				error.value = e.message
			}
		}
	})
</script>

<style>
	.page {
		flex: 1;
		padding: 16px;
		background-color: #F5F6F8;
	}

	.card {
		padding: 18px 16px;
		border-radius: 12px;
		background-color: #FFFFFF;
	}

	.title {
		font-size: 19px;
		font-weight: bold;
		color: #1F2329;
		line-height: 27px;
	}

	.meta {
		display: flex;
		flex-direction: row;
		margin-top: 10px;
		padding-bottom: 14px;
		border-bottom: 1px solid #EFF1F4;
	}

	.meta-text {
		margin-right: 12px;
		font-size: 12px;
		color: #A8ADB5;
	}

	.content {
		display: block;
		margin-top: 14px;
		font-size: 15px;
		color: #1F2329;
		line-height: 25px;
	}

	.hint {
		padding: 40px 20px;
	}

	.hint-text {
		font-size: 13px;
		color: #8A8F99;
		text-align: center;
	}
</style>
