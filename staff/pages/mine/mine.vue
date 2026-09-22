<template>
	<view class="screen">
		<text class="large-title">我的</text>

		<view class="group profile">
			<!-- 点头像即换：不做「进设置页 → 点头像 → 选图」三步 -->
			<view class="avatar-wrap" hover-class="avatar-pressed" @click="changeAvatar">
				<image v-if="avatar" class="avatar" :src="avatar" mode="aspectFill" />
				<view v-else class="avatar avatar-fallback">
					<text class="avatar-text">{{ initial }}</text>
				</view>
				<text class="avatar-action">{{ uploading ? '上传中' : '更换' }}</text>
			</view>

			<view class="meta">
				<text class="name">{{ profile.real_name || profile.username }}</text>
				<text class="sub">{{ profile.username }}</text>
				<text class="sub">{{ profile.dept_name || '未分配部门' }}</text>
				<view v-if="profile.roles.length" class="roles">
					<text class="role" v-for="role in profile.roles" :key="role">{{ role }}</text>
				</view>
			</view>
		</view>

		<view class="group form">
			<view class="row">
				<text class="row-label">姓名</text>
				<input class="row-input" placeholder="填写姓名" placeholder-class="row-placeholder" v-model="draftName" />
			</view>
			<view class="row">
				<text class="row-label">邮箱</text>
				<input class="row-input" placeholder="填写邮箱" placeholder-class="row-placeholder" v-model="draftEmail" />
			</view>
			<view class="row">
				<text class="row-label">手机号</text>
				<!-- 只读：换绑手机要验当前密码，是单独的流程（api.md §11），不在这一页做 -->
				<text class="row-value">{{ profile.phone || '未绑定' }}</text>
			</view>
			<view class="row">
				<text class="row-label">上次登录</text>
				<text class="row-value">{{ profile.last_login_at || '—' }}</text>
			</view>
		</view>

		<button class="btn btn-primary save" :class="{ 'is-busy': saving }" hover-class="btn-pressed" @click="save">
			{{ saving ? '正在保存' : '保存资料' }}
		</button>

		<!-- 退出单独成组、红字居中：iOS 设置里破坏性操作的位置和写法 -->
		<view class="group logout-group">
			<view class="row logout" hover-class="row-pressed" @click="doLogout">
				<text class="logout-text">退出登录</text>
			</view>
		</view>

		<text class="fine-print version">Keel 移动工作台 v{{ version }}</text>
	</view>
</template>

<script setup>
	import { ref, computed } from 'vue'
	import { onShow } from '@dcloudio/uni-app'
	import { fetchProfile, updateProfile, uploadAvatar, logout } from '@/common/api.js'
	import { absUrl, cacheUser, getCachedUser } from '@/common/request.js'
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
					// 登录缓存里的头像也要换：聊天室里「我」的头像取的是它，不换就要重新登录才变
					cacheUser(Object.assign({}, getCachedUser() || {}, { avatar: data.avatar }))
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

<style scoped>
	/* ---------- 头像与身份 ---------- */

	.profile {
		display: flex;
		flex-direction: row;
		align-items: flex-start;
		margin-top: 20px;
		padding: 20px 16px;
	}

	.avatar-wrap {
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.avatar-pressed {
		opacity: 0.6;
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
		background-color: var(--keel-color-primary);
	}

	.avatar-text {
		font-size: 26px;
		font-weight: 600;
		color: #fff;
	}

	/* 可点的地方用品牌色写明动作，而不是一行灰色提示 */
	.avatar-action {
		margin-top: 8px;
		font-size: 14px;
		color: var(--keel-color-primary);
	}

	.meta {
		flex: 1;
		min-width: 0;
		margin-left: 16px;
	}

	.name {
		display: block;
		font-size: 21px;
		font-weight: 600;
		line-height: 1.19;
		color: var(--keel-text-color-primary);
	}

	.sub {
		display: block;
		margin-top: 4px;
		font-size: 15px;
		color: var(--keel-text-color-secondary);
	}

	.roles {
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		margin-top: 10px;
	}

	/* 角色是信息不是动作：中性描边胶囊，不用品牌色 */
	.role {
		margin: 0 6px 6px 0;
		padding: 2px 10px;
		border: 1px solid var(--keel-border-color-lighter);
		border-radius: var(--keel-radius-pill);
		font-size: 12px;
		line-height: 18px;
		color: var(--keel-text-color-regular);
	}

	/* ---------- 资料与操作 ---------- */

	.form {
		margin-top: 20px;
	}

	.save {
		margin-top: 20px;
	}

	.logout-group {
		margin-top: 32px;
	}

	.logout {
		justify-content: center;
	}

	.logout-text {
		font-size: 17px;
		color: var(--keel-color-danger);
	}

	.version {
		margin-top: 20px;
		text-align: center;
	}
</style>
