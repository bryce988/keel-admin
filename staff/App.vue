<script setup>
	import { onLaunch } from '@dcloudio/uni-app'
	import { getToken } from '@/common/request.js'

	/*
	 * 启动时决定进哪一页
	 *
	 * pages.json 第一项是登录页，所以默认落在登录页；已登录的直接切到首页。
	 * 这里只看「本地有没有令牌」，不去校验它还有效没有——校验要发一次请求，
	 * 启动阶段多等一个网络往返，用户看到的就是一段白屏。
	 * 令牌真过期了，第一个业务接口会 401，request.js 里统一踢回登录页。
	 */
	onLaunch(() => {
		if (getToken()) {
			uni.switchTab({ url: '/pages/index/index' })
		}
	})
</script>

<style>
	/* 每个页面公共 css */
	page {
		background-color: #F5F6F8;
	}
</style>
