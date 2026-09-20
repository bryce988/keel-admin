<template>
	<view class="login">
		<view class="brand">
			<image class="brand-logo" src="/static/logo.png" mode="aspectFit" />
			<text class="brand-name">Keel</text>
			<text class="brand-sub">移动工作台</text>
		</view>

		<view class="group">
			<view class="row">
				<text class="row-label">账号</text>
				<input class="row-input" placeholder="后台登录账号" placeholder-class="row-placeholder" v-model="username" />
			</view>
			<view class="row">
				<text class="row-label">密码</text>
				<input class="row-input" password placeholder="登录密码" placeholder-class="row-placeholder" v-model="password" />
			</view>
			<view class="row">
				<text class="row-label">验证码</text>
				<input class="row-input" placeholder="右侧四位字符" placeholder-class="row-placeholder" v-model="captchaCode" />
				<!-- 点图换一张：看不清是常态，不给刷新入口只能退出重进 -->
				<image v-if="captchaImage" class="captcha" :src="captchaImage" mode="aspectFit" @click="loadCaptcha" />
				<view v-else class="captcha captcha-empty" @click="loadCaptcha">
					<text class="captcha-empty-text">点击加载</text>
				</view>
			</view>
		</view>

		<!-- 错误就地展示，不用 toast：toast 一闪而过，而登录失败的人要盯着那句话改输入 -->
		<text v-if="error" class="error">{{ error }}</text>

		<button class="btn btn-primary submit" :class="{ 'is-busy': loading }" hover-class="btn-pressed" @click="submit">
			{{ loading ? '正在登录' : '登录' }}
		</button>

		<text class="fine-print hint">使用后台的账号登录。演示账号 admin，密码 admin123，看不清验证码点图片换一张。</text>
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
	 * 切去了首页，登录页照样会被创建并触发 onLoad。在这里 clearAuth() 的后果是
	 * 「退出 App 再打开又要重新登录」：读到令牌 → 切首页 → 登录页把令牌清了 →
	 * 首页请求 401 → 踢回登录页。
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
			error.value = '请填写账号、密码和验证码'
			return
		}

		loading.value = true
		error.value = ''

		try {
			await login(username.value, password.value, captchaKey.value, captchaCode.value)
			uni.switchTab({ url: '/pages/chat/list' })
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

<style scoped>
	/* 登录页也是自定义导航（pages.json），顶部自己让出状态栏 */
	.login {
		padding: calc(var(--status-bar-height) + 72px) 20px 40px;
		background-color: var(--keel-bg-color-page);
	}

	.brand {
		margin-bottom: 36px;
		padding: 0 4px;
	}

	.brand-logo {
		display: block;
		width: 56px;
		height: 56px;
	}

	.brand-name {
		display: block;
		margin-top: 20px;
		font-size: 34px;
		font-weight: 600;
		line-height: 1.1;
		letter-spacing: -0.374px;
		color: var(--keel-text-color-primary);
	}

	.brand-sub {
		display: block;
		margin-top: 6px;
		font-size: 17px;
		color: var(--keel-text-color-secondary);
	}

	.captcha {
		width: 96px;
		height: 36px;
		margin-left: 8px;
		flex-shrink: 0;
		border-radius: var(--keel-radius-sm);
		background-color: var(--keel-fill-color-light);
	}

	.captcha-empty {
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.captcha-empty-text {
		font-size: 12px;
		color: var(--keel-text-color-secondary);
	}

	.error {
		display: block;
		margin: 12px 4px 0;
		font-size: 14px;
		line-height: 1.43;
		color: var(--keel-color-danger);
	}

	.submit {
		margin-top: 24px;
	}

	/* 与品牌区同为左对齐：整页只有一条左边线 */
	.hint {
		margin: 16px 4px 0;
	}
</style>
