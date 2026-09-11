<template>
	<!-- 阅读页整页白底：从灰底的列表点进来，底色切换本身就说明「进到正文里了」 -->
	<view class="article">
		<block v-if="notice">
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
		</block>

		<text v-else class="hint">{{ error || '正在加载公告' }}</text>
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

<style scoped>
	/*
	 * 撑满一屏：body 是全局的 parchment 底，正文短时下面会露出一截灰。
	 * 不在这里写 page { background }——H5 下页面级的 page 样式会留在文档里，
	 * 返回列表后整个 App 都变白。--window-top / --window-bottom 是 uni 内置变量（导航栏、tabBar 高度）
	 */
	.article {
		min-height: calc(100vh - var(--window-top) - var(--window-bottom));
		padding: 24px 20px 48px;
		box-sizing: border-box;
		background-color: var(--keel-bg-color);
	}

	.title {
		display: block;
		font-size: 28px;
		font-weight: 600;
		line-height: 1.2;
		letter-spacing: -0.28px;
		color: var(--keel-text-color-primary);
	}

	.meta {
		position: relative;
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		margin-top: 12px;
		padding-bottom: 20px;
	}

	.meta::after {
		content: '';
		position: absolute;
		left: 0;
		right: 0;
		bottom: 0;
		height: 1px;
		background-color: var(--keel-border-color-lighter);
		transform: scaleY(0.5);
	}

	.meta-text {
		margin-right: 12px;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.content {
		display: block;
		margin-top: 20px;
		font-size: 17px;
		line-height: 1.47;
		color: var(--keel-text-color-primary);
	}

	.hint {
		display: block;
		padding: 48px 0;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
		text-align: center;
	}
</style>
