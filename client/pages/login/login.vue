<template>
	<view class="page">
		<view class="brand">
			<text class="brand-mark">Keel</text>
			<text class="brand-sub">龙骨 · C 端示例</text>
		</view>

		<view class="card">
			<view class="field">
				<text class="label">手机号</text>
				<input class="input" type="number" placeholder="请输入手机号" v-model="phone" />
			</view>

			<view class="field">
				<text class="label">密码</text>
				<input class="input" password placeholder="请输入密码" v-model="password" />
			</view>

			<!-- 错误就地展示，不用 toast：toast 一闪而过，而登录失败的人需要盯着那句话改输入 -->
			<text v-if="error" class="error">{{ error }}</text>

			<button class="submit" :disabled="loading" @click="submit">
				{{ loading ? '登录中…' : '登 录' }}
			</button>

			<text class="hint">演示账号 13900139001 / app123456</text>
		</view>
	</view>
</template>

<script setup>
	import { ref } from 'vue'
	import { onLoad } from '@dcloudio/uni-app'
	import { login } from '@/common/api.js'
	import { clearAuth } from '@/common/request.js'

	const phone = ref('13900139001')
	const password = ref('app123456')
	const loading = ref(false)
	const error = ref('')

	// 能走到登录页就说明当前没有可用身份，把残留清掉，
	// 免得下面登录失败、用户退出应用后再进来又被旧令牌带进首页
	onLoad(() => {
		clearAuth()
	})

	async function submit() {
		if (loading.value) return

		// 前端先挡一道空值：不为了安全（后端一样会校验），是为了省一次往返
		if (!phone.value || !password.value) {
			error.value = '请填写手机号与密码'
			return
		}

		loading.value = true
		error.value = ''

		try {
			await login(phone.value, password.value)
			// switchTab 而不是 navigateTo：首页是 tabBar 页面，
			// 而且登录后不该还能按返回键退回登录页
			uni.switchTab({ url: '/pages/index/index' })
		} catch (e) {
			error.value = e.message
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
