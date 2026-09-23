<template>
	<!--
		会话头像：有图用图；没设头像的群拼成员头像（与电脑端 web/src/views/chat/GroupAvatar.vue 同一套排法）
		1 人占满；2 人左右各半；3 人上一下二；4 人 2×2。没有头像的成员用姓名首字
	-->
	<view class="gavatar" :class="{ 'gavatar--tiled': tiled }" :style="boxStyle">
		<block v-if="tiled">
			<view
				v-for="(f, i) in list"
				:key="i"
				class="gavatar-cell"
				:style="cellStyle(f, i)"
			>
				<image
					v-if="f.avatar && !broken[i]"
					class="gavatar-img"
					:src="absUrl(f.avatar)"
					mode="aspectFill"
					@error="broken[i] = true"
				/>
				<text v-else class="gavatar-text" :style="{ fontSize: initialSize }">{{ f.real_name.slice(0, 1) }}</text>
			</view>
		</block>
		<image v-else-if="src && !srcBroken" class="gavatar-img" :src="absUrl(src)" mode="aspectFill" @error="srcBroken = true" />
		<text v-else class="gavatar-text gavatar-text--single" :style="{ fontSize: singleSize }">{{ (name || '?').slice(0, 1) }}</text>
	</view>
</template>

<script setup>
	import { computed, ref, reactive, watch } from 'vue'
	import { absUrl } from '@/common/request.js'

	const props = defineProps({
		/** 自定义头像：单聊是对方头像，群是群主设的头像 */
		src: { type: String, default: '' },
		/** 群成员（服务端 avatar_members，最多 4 个） */
		faces: { type: Array, default: () => [] },
		/** 兜底首字 */
		name: { type: String, default: '' },
		size: { type: Number, default: 44 }
	})

	const list = computed(() => (props.faces || []).slice(0, 4))
	const tiled = computed(() => !props.src && list.value.length > 0)

	/** 图片 404 时退回首字，与 web 端一致，别显示一个破图 */
	const broken = reactive({})
	const srcBroken = ref(false)
	watch(() => [props.src, props.faces], () => {
		Object.keys(broken).forEach((k) => delete broken[k])
		srcBroken.value = false
	})

	const boxStyle = computed(() => ({
		width: props.size + 'px',
		height: props.size + 'px',
		borderRadius: props.size / 2 + 'px'
	}))

	/*
	 * 首字底色按名字取固定色：同一个人在哪个群里都是同一种颜色。
	 * 取自主题色板（theme.css 的变量），不写十六进制
	 */
	const TONES = [
		'var(--keel-color-primary)',
		'var(--keel-color-success)',
		'var(--keel-color-warning)',
		'var(--keel-color-danger)',
		'var(--keel-color-info)'
	]

	function toneOf(name) {
		let h = 0
		for (const ch of name || '') h = (h * 31 + ch.codePointAt(0)) >>> 0
		return TONES[h % TONES.length]
	}

	const LAYOUT = {
		1: [[0, 0, 100, 100]],
		2: [[0, 0, 50, 100], [50, 0, 50, 100]],
		3: [[25, 0, 50, 50], [0, 50, 50, 50], [50, 50, 50, 50]],
		4: [[0, 0, 50, 50], [50, 0, 50, 50], [0, 50, 50, 50], [50, 50, 50, 50]]
	}

	function cellStyle(f, i) {
		const [x, y, w, h] = LAYOUT[list.value.length][i]
		return {
			left: x + '%',
			top: y + '%',
			width: w + '%',
			height: h + '%',
			backgroundColor: f.avatar && !broken[i] ? 'transparent' : toneOf(f.real_name)
		}
	}

	const initialSize = computed(() => Math.round(props.size * (list.value.length === 1 ? 0.42 : 0.26)) + 'px')
	const singleSize = computed(() => Math.round(props.size * 0.4) + 'px')
</script>

<style scoped>
	.gavatar {
		position: relative;
		flex-shrink: 0;
		overflow: hidden;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--keel-color-primary);
	}

	/* 拼接时外框用白底：三个人时上排两侧是空的，不能露出主色 */
	.gavatar--tiled {
		background-color: var(--keel-bg-color);
	}

	/* 格子之间留一道白缝，拼起来才看得出是几个人 */
	.gavatar-cell {
		position: absolute;
		box-sizing: border-box;
		border: 0.5px solid var(--keel-bg-color);
		overflow: hidden;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.gavatar-img {
		width: 100%;
		height: 100%;
	}

	.gavatar-text {
		line-height: 1;
		color: #fff;
	}
</style>
