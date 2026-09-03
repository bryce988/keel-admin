<template>
	<view class="page">
		<view class="card profile">
			<!-- 点头像即换：不做「进设置页 → 点头像 → 选图」三步 -->
			<view class="avatar-wrap" @click="changeAvatar">
				<image v-if="avatar" class="avatar" :src="avatar" mode="aspectFill" />
				<view v-else class="avatar avatar-fallback">
					<text class="avatar-text">{{ initial }}</text>
				</view>
				<text class="avatar-tip">{{ uploading ? '上传中…' : '点击更换' }}</text>
			</view>

			<view class="meta">
				<text class="name">{{ profile.real_name || profile.username }}</text>
				<text class="sub">{{ profile.username }} · {{ profile.dept_name || '未分配部门' }}</text>
				<view class="tags">
					<text class="tag" v-for="role in profile.roles" :key="role">{{ role }}</text>
				</view>
			</view>
		</view>

		<view class="card">
			<view class="row">
				<text class="row-label">姓名</text>
				<input class="row-input" placeholder="填写姓名" v-model="draftName" />
			</view>
			<view class="row">
				<text class="row-label">邮箱</text>
				<input class="row-input" placeholder="填写邮箱" v-model="draftEmail" />
			</view>
			<view class="row">
				<text class="row-label">手机号</text>
				<!-- 只读：换绑手机要验当前密码，是单独的流程（api.md §11），不在这一页做 -->
				<text class="row-value">{{ profile.phone || '未绑定' }}</text>
			</view>
			<view class="row row-last">
				<text class="row-label">上次登录</text>
				<text class="row-value">{{ profile.last_login_at || '—' }}</text>
			</view>
			<button class="save" :disabled="saving" @click="save">
				{{ saving ? '保存中…' : '保存资料' }}
			</button>
		</view>

		<button class="logout" @click="doLogout">退出登录</button>

		<text class="version">Keel 移动工作台 v{{ version }}</text>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { fetchProfile, updateProfile, uploadAvatar, logout } from '@/common/api.js'
	import { absUrl } from '@/common/request.js'
	import { APP_VERSION } from '@/common/config.js'

	const profile = ref({ username: '', real_name: '', dept_name: '', phone: '', email: '', roles: [], last_login_at: '' })
	const avatar = ref('')
	const draftName = ref('')
	const draftEmail = ref('')
	const saving = ref(false)
	const uploading = ref(false)
	const version = APP_VERSION

	// 没头像时用姓名首字兜底，跟 web 端的兜底头像一个思路
	const initial = computed(() => {
		const name = profile.value.real_name || profile.value.username
		return name ? name.charAt(0) : '?'
	})

	async function load() {
		try {
			const res = await fetchProfile()
			profile.value = res
			draftName.value = res.real_name
			draftEmail.value = res.email
			avatar.value = absUrl(res.avatar)
		} catch (e) {
			// 401 已经在 request 层踢回登录页了，这里只处理别的错
			if (e.code !== 401) {
				uni.showToast({ title: e.message, icon: 'none' })
			}
		}
	}

	async function save() {
		if (saving.value) return
		if (!draftName.value) {
			uni.showToast({ title: '姓名不能为空', icon: 'none' })
			return
		}

		saving.value = true
		try {
			await updateProfile({ real_name: draftName.value, email: draftEmail.value })
			profile.value.real_name = draftName.value
			profile.value.email = draftEmail.value
			// 不用在这里刷身份缓存：首页每次 onShow 都会重新拉工作台，
			// 那一次就把最新的姓名与头像带回来了
			uni.showToast({ title: '已保存', icon: 'none' })
		} catch (e) {
			uni.showToast({ title: e.message, icon: 'none' })
		} finally {
			saving.value = false
		}
	}

	function changeAvatar() {
		if (uploading.value) return

		uni.chooseImage({
			count: 1,
			sizeType: ['compressed'],
			success: async (res) => {
				const path = res.tempFilePaths[0]
				if (!path) return

				uploading.value = true
				try {
					const data = await uploadAvatar(path)
					/*
					 * 用服务端回的地址，不用本地临时路径
					 *
					 * 临时路径下次进页面就失效了，而且那样看到的是「我以为传成功了」，
					 * 不是真的传成功了——上传失败时界面反而显示新头像，最难查
					 */
					avatar.value = absUrl(data.avatar)
					uni.showToast({ title: '头像已更新', icon: 'none' })
				} catch (e) {
					uni.showToast({ title: e.message, icon: 'none' })
				} finally {
					uploading.value = false
				}
			}
		})
	}

	function doLogout() {
		uni.showModal({
			title: '退出登录',
			content: '确定要退出当前账号吗？',
			success: async (res) => {
				if (!res.confirm) return
				await logout()
				uni.reLaunch({ url: '/pages/login/login' })
			}
		})
	}

	// tabBar 页面切回来不会重新 load，用 onShow 才能拿到最新资料
	onShow(() => {
		load()
	})
</script>

<style>
	.page {
		flex: 1;
		padding: 16px;
		background-color: #F5F6F8;
	}

	.card {
		padding: 16px;
		margin-bottom: 12px;
		border-radius: 12px;
		background-color: #FFFFFF;
	}

	.profile {
		display: flex;
		flex-direction: row;
		align-items: center;
	}

	.avatar-wrap {
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.avatar {
		width: 64px;
		height: 64px;
		border-radius: 32px;
	}

	.avatar-fallback {
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: #2B6CF6;
	}

	.avatar-text {
		font-size: 26px;
		color: #FFFFFF;
	}

	.avatar-tip {
		margin-top: 6px;
		font-size: 11px;
		color: #8A8F99;
	}

	.meta {
		margin-left: 16px;
		flex: 1;
	}

	.name {
		font-size: 18px;
		font-weight: bold;
		color: #1F2329;
	}

	.sub {
		display: block;
		margin-top: 6px;
		font-size: 13px;
		color: #8A8F99;
	}

	.tags {
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		margin-top: 8px;
	}

	.tag {
		margin-right: 6px;
		margin-bottom: 4px;
		padding: 2px 8px;
		border-radius: 4px;
		font-size: 11px;
		color: #2B6CF6;
		background-color: #EAF1FE;
	}

	.row {
		display: flex;
		flex-direction: row;
		align-items: center;
		height: 44px;
		border-bottom: 1px solid #EFF1F4;
	}

	.row-last {
		border-bottom: none;
	}

	.row-label {
		width: 76px;
		font-size: 14px;
		color: #8A8F99;
	}

	.row-input {
		flex: 1;
		font-size: 15px;
		color: #1F2329;
	}

	.row-value {
		flex: 1;
		font-size: 14px;
		color: #1F2329;
	}

	.save {
		margin-top: 14px;
		height: 42px;
		line-height: 42px;
		border-radius: 8px;
		font-size: 15px;
		color: #FFFFFF;
		background-color: #2B6CF6;
	}

	.logout {
		height: 44px;
		line-height: 44px;
		border-radius: 10px;
		font-size: 15px;
		color: #E54545;
		background-color: #FFFFFF;
	}

	.version {
		display: block;
		margin-top: 16px;
		font-size: 11px;
		color: #A8ADB5;
		text-align: center;
	}
</style>
