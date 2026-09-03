<template>
	<view class="page">
		<view class="card profile">
			<!-- 点头像即换：不做「进设置页 → 点头像 → 选图」三步 -->
			<view class="avatar-wrap" @click="changeAvatar">
				<image v-if="profile.avatar" class="avatar" :src="profile.avatar" mode="aspectFill" />
				<view v-else class="avatar avatar-fallback">
					<text class="avatar-text">{{ initial }}</text>
				</view>
				<text class="avatar-tip">{{ uploading ? '上传中…' : '点击更换' }}</text>
			</view>

			<view class="meta">
				<text class="nickname">{{ profile.nickname || '未设置昵称' }}</text>
				<text class="phone">{{ profile.phone }}</text>
			</view>
		</view>

		<view class="card">
			<view class="row">
				<text class="row-label">昵称</text>
				<input class="row-input" placeholder="给自己起个名字" v-model="draftName" />
			</view>
			<view class="row row-last">
				<text class="row-label">上次登录</text>
				<text class="row-value">{{ profile.last_login_at || '—' }}</text>
			</view>
			<button class="save" :disabled="saving" @click="save">
				{{ saving ? '保存中…' : '保存昵称' }}
			</button>
		</view>

		<button class="logout" @click="doLogout">退出登录</button>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { fetchProfile, updateNickname, uploadAvatar, logout } from '@/common/api.js'

	const profile = ref({ nickname: '', phone: '', avatar: '', last_login_at: '' })
	const draftName = ref('')
	const saving = ref(false)
	const uploading = ref(false)

	// 没头像时用昵称首字兜底，跟 web 端的兜底头像一个思路
	const initial = computed(() => (profile.value.nickname ? profile.value.nickname.charAt(0) : '?'))

	async function load() {
		try {
			profile.value = await fetchProfile()
			draftName.value = profile.value.nickname
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
			uni.showToast({ title: '昵称不能为空', icon: 'none' })
			return
		}

		saving.value = true
		try {
			const user = await updateNickname(draftName.value)
			profile.value.nickname = user.nickname
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
					const user = await uploadAvatar(path)
					/*
					 * 用服务端回的地址，不用本地临时路径
					 *
					 * 临时路径下次进页面就失效了，而且那样看到的是「我以为传成功了」，
					 * 不是真的传成功了——上传失败时界面反而显示新头像，最难查。
					 */
					profile.value.avatar = user.avatar
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

	.nickname {
		font-size: 18px;
		font-weight: bold;
		color: #1F2329;
	}

	.phone {
		display: block;
		margin-top: 6px;
		font-size: 13px;
		color: #8A8F99;
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
</style>
