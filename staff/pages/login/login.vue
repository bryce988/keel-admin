<template>
	<view class="page">
		<view class="brand">
			<text class="brand-mark">Keel</text>
			<text class="brand-sub">龙骨 · 移动工作台</text>
		</view>

		<view class="card">
			<view class="field">
				<text class="label">账号</text>
				<input class="input" placeholder="请输入账号" v-model="username" />
			</view>

			<view class="field">
				<text class="label">密码</text>
				<input class="input" password placeholder="请输入密码" v-model="password" />
			</view>

			<view class="field">
				<text class="label">验证码</text>
				<view class="captcha-row">
					<input class="input captcha-input" placeholder="四位验证码" v-model="captchaCode" />
					<!-- 点图换一张：看不清是常态，不给刷新入口只能退出重进 -->
					<image v-if="captchaImage" class="captcha-img" :src="captchaImage" mode="aspectFit" @click="loadCaptcha" />
					<view v-else class="captcha-img captcha-loading" @click="loadCaptcha">
						<text class="captcha-loading-text">点击加载</text>
					</view>
				</view>
			</view>

			<!-- 错误就地展示，不用 toast：toast 一闪而过，而登录失败的人要盯着那句话改输入 -->
			<text v-if="error" class="error">{{ error }}</text>

			<button class="submit" :disabled="loading" @click="submit">
				{{ loading ? '登录中…' : '登 录' }}
			</button>

			<text class="hint">用后台同一套账号登录 · 演示 admin / admin123</text>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onLoad } from '@dcloudio/uni-app'
	import { fetchCaptcha, login } from '@/common/api.js'

	const username = ref('admin')
	const password = ref('admin123')
	const captchaCode = ref('')
	const captchaKey = ref('')
	const captchaImage = ref('')
	const loading = ref(false)
	const error = ref('')

	async function loadCaptcha() {
		try {
			const res = await fetchCaptcha()
			captchaKey.value = res.captcha_key
			captchaImage.value = res.captcha_image
			captchaCode.value = ''
		} catch (e) {
			error.value = e.message
		}
	}

	/*
	 * ⚠️ 这里**不要**清本地令牌
	 *
	 * 冷启动时登录页是 pages.json 的第一项，即使 App.vue 的 onLaunch 已经判断有令牌、
	 * 切去了首页，登录页照样会被创建并触发 onLoad。原先这里无条件 clearAuth()，
	 * 结果就是「退出 App 再打开又要重新登录」：读到令牌 → 切首页 →
	 * 登录页把令牌清了 → 首页请求 401 → 踢回登录页。
	 *
	 * 令牌该清的地方只有两处：主动退出登录，以及刷新失败后的 onUnauthorized。
	 */
	onLoad(() => {
		loadCaptcha()
	})

	async function submit() {
		if (loading.value) return

		// 前端先挡一道空值：不为了安全（后端一样会校验），是为了省一次往返
		if (!username.value || !password.value || !captchaCode.value) {
			error.value = '账号、密码、验证码都要填'
			return
		}

		loading.value = true
		error.value = ''

		try {
			await login(username.value, password.value, captchaKey.value, captchaCode.value)
			uni.switchTab({ url: '/pages/index/index' })
		} catch (e) {
			error.value = e.message
			/*
			 * 验证码是一次性的：验过就从 Redis 删了，无论对错。
			 * 所以每次失败都必须换一张，否则用户拿着已作废的码重试，
			 * 只会一直看到「验证码错误」，明明密码已经改对了
			 */
			loadCaptcha()
		} finally {
			loading.value = false
		}
	}
</script>

<style>
	.page {
		flex: 1;
		padding: 48px 28px;
		background-color: #F5F6F8;
	}

	.brand {
		margin-top: 40px;
		margin-bottom: 36px;
	}

	.brand-mark {
		font-size: 34px;
		font-weight: bold;
		color: #1F2329;
	}

	.brand-sub {
		display: block;
		margin-top: 6px;
		font-size: 14px;
		color: #8A8F99;
	}

	.card {
		padding: 20px;
		border-radius: 12px;
		background-color: #FFFFFF;
	}

	.field {
		margin-bottom: 16px;
	}

	.label {
		display: block;
		font-size: 13px;
		color: #8A8F99;
		margin-bottom: 6px;
	}

	.input {
		height: 44px;
		padding: 0 12px;
		font-size: 16px;
		color: #1F2329;
		border-radius: 8px;
		background-color: #F5F6F8;
	}

	.captcha-row {
		display: flex;
		flex-direction: row;
		align-items: center;
	}

	.captcha-input {
		flex: 1;
		margin-right: 10px;
	}

	.captcha-img {
		width: 110px;
		height: 44px;
		border-radius: 8px;
		background-color: #F5F6F8;
	}

	.captcha-loading {
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.captcha-loading-text {
		font-size: 12px;
		color: #8A8F99;
	}

	.error {
		display: block;
		font-size: 13px;
		color: #E54545;
		margin-bottom: 12px;
	}

	.submit {
		height: 46px;
		line-height: 46px;
		border-radius: 8px;
		font-size: 16px;
		color: #FFFFFF;
		background-color: #2B6CF6;
	}

	.hint {
		display: block;
		margin-top: 14px;
		font-size: 12px;
		color: #A8ADB5;
		text-align: center;
	}
</style>
