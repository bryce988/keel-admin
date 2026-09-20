<template>
	<view class="room">
		<scroll-view
			class="stream"
			scroll-y
			:scroll-top="scrollTop"
			:scroll-with-animation="false"
		>
			<view class="stream-inner" id="stream-inner">
				<view
					v-for="m in messages"
					:key="m.client_msg_id || m.id"
					class="msg"
					:class="{ 'msg--mine': isMine(m) }"
				>
					<text class="msg-name">{{ isMine(m) ? '我' : m.sender_name }}</text>
					<view class="bubble" :class="{ 'bubble--mine': isMine(m), 'bubble--failed': m._state === 'failed' }">
						<text class="bubble-text" :class="{ 'bubble-text--mine': isMine(m) }">{{ m.content }}</text>
					</view>
					<text v-if="m._state" class="msg-state">{{ m._state === 'sending' ? '发送中…' : '发送失败' }}</text>
				</view>

				<text v-if="!messages.length" class="hint">{{ loading ? '正在加载' : '还没有消息，说点什么吧' }}</text>
			</view>
		</scroll-view>

		<view class="composer">
			<input
				v-model="draft"
				class="composer-input"
				type="text"
				placeholder="说点什么"
				confirm-type="send"
				:adjust-position="true"
				@confirm="send"
			/>
			<view class="composer-btn" :class="{ 'composer-btn--off': !draft.trim() }" hover-class="composer-btn--hover" @click="send">
				<text class="composer-btn-text">发送</text>
			</view>
		</view>
	</view>
</template>

<script setup>
	import { ref, nextTick } from 'vue'
	import { onLoad, onUnload, onShow } from '@dcloudio/uni-app'
	import { fetchChatMessages, sendChatMessage, markChatRead } from '@/common/api.js'
	import { getCachedUser } from '@/common/request.js'
	import { chatSocket } from '@/common/chatSocket.js'

	const convId = ref(0)
	const messages = ref([])
	const draft = ref('')
	const loading = ref(false)
	const sending = ref(false)
	const scrollTop = ref(0)
	const myId = ref(0)

	/** 乐观上屏用的占位 seq：比任何真实 seq 都大，保证排在末尾 */
	const PENDING_SEQ = Number.MAX_SAFE_INTEGER

	let offReady = null
	let offMessage = null

	function isMine(m) {
		return m.sender_id === myId.value
	}

	async function loadHistory() {
		loading.value = true
		try {
			messages.value = await fetchChatMessages(convId.value, { limit: 30 })
			await toBottom()
			await flushRead()
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		} finally {
			loading.value = false
		}
	}

	/** 已读水位推到服务端。失败不打扰用户——下次打开或收到新消息会再推一次 */
	async function flushRead() {
		const last = messages.value[messages.value.length - 1]
		if (!last || last.seq === PENDING_SEQ) return

		try {
			await markChatRead(convId.value, last.seq)
		} catch (e) {
			/* 静默 */
		}
	}

	/**
	 * 按 seq 插入，重复的丢弃
	 *
	 * 推送与 HTTP 响应可能带来同一条消息，这里是唯一的去重点。
	 */
	function upsert(msg) {
		if (messages.value.some((m) => m.seq === msg.seq && m.id === msg.id)) return

		const idx = messages.value.findIndex((m) => m.seq > msg.seq)
		if (idx === -1) messages.value.push(msg)
		else messages.value.splice(idx, 0, msg)
	}

	/**
	 * 收到推送
	 *
	 * ⚠️ 要检测**空洞**：推送是不可靠的（Redis pub/sub 不持久化，网关重启期间会丢）。
	 * seq 不连续就用 HTTP 把中间的补回来——可靠性在数据库不在长连接。
	 */
	async function onPush(msg) {
		if (!msg || msg.conv_id !== convId.value) return

		const real = messages.value.filter((m) => m.seq !== PENDING_SEQ)
		const localMax = real.length ? real[real.length - 1].seq : 0

		if (localMax > 0 && msg.seq > localMax + 1) {
			const missing = await fetchChatMessages(convId.value, { after_seq: localMax, limit: 100 })
			missing.forEach(upsert)
		} else {
			upsert(msg)
		}

		await toBottom()
		await flushRead()
	}

	async function send() {
		const text = draft.value.trim()
		if (!text || sending.value) return

		const clientMsgId = uuid()

		// 乐观上屏：先让消息出现，再等服务端确认
		messages.value.push({
			id: 0,
			conv_id: convId.value,
			seq: PENDING_SEQ,
			sender_id: myId.value,
			sender_name: '我',
			type: 'text',
			content: text,
			client_msg_id: clientMsgId,
			status: 1,
			created_at: '',
			_state: 'sending'
		})
		draft.value = ''
		await toBottom()

		sending.value = true
		try {
			const saved = await sendChatMessage(convId.value, {
				client_msg_id: clientMsgId,
				type: 'text',
				content: text
			})

			// 不是原地替换：等响应这段时间可能已经收到了别人的消息，
			// 直接替换会让本地顺序与服务端 seq 对不上。删占位 + 按 seq 插入
			messages.value = messages.value.filter((m) => m.client_msg_id !== clientMsgId)
			upsert(saved)
		} catch (e) {
			const target = messages.value.find((m) => m.client_msg_id === clientMsgId)
			if (target) target._state = 'failed'
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		} finally {
			sending.value = false
		}
	}

	/**
	 * 滚到底
	 *
	 * scroll-view 的 scrollTop 是**受控**的：赋同样的值不会再触发滚动，
	 * 所以每次要给一个不同的大数。这是 uni-app 里的老问题，
	 * 用 +1 抖一下比查询真实高度便宜得多。
	 */
	async function toBottom() {
		await nextTick()
		scrollTop.value = scrollTop.value === 999999 ? 999998 : 999999
	}

	/** crypto.randomUUID 在部分基座上没有，自己拼一个够用的 v4 */
	function uuid() {
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
			const r = (Math.random() * 16) | 0
			return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
		})
	}

	onLoad(async (options) => {
		convId.value = Number(options.id || 0)
		myId.value = Number((getCachedUser() || {}).id || 0)

		if (options.name) {
			uni.setNavigationBarTitle({ title: decodeURIComponent(options.name) })
		}

		/*
		 * 重连后的对齐：ready 是「握手完成」的信号，每次重连都会再来一次。
		 * 收到就重拉一次——断线期间到达的消息靠这一步补齐，
		 * 而不是指望推送把断线那段重发一遍（Redis pub/sub 不持久化）。
		 */
		offReady = chatSocket.on('ready', () => {
			if (convId.value) loadHistory()
		})
		offMessage = chatSocket.on('message.new', onPush)

		chatSocket.connect()
		await loadHistory()
	})

	// 从后台切回前台时补一次：息屏期间连接可能已经被系统回收了
	onShow(() => {
		if (convId.value && !chatSocket.connected) chatSocket.connect()
	})

	onUnload(() => {
		if (offReady) offReady()
		if (offMessage) offMessage()
		// 不 close()：socket 是全局单例，回到列表页仍要靠它收消息
	})
</script>

<style scoped>
	.room {
		display: flex;
		flex-direction: column;
		height: calc(100vh - var(--window-top));
		background: #f5f5f7;
	}

	.stream {
		flex: 1;
		min-height: 0;
	}

	.stream-inner {
		padding: 16px 16px 8px;
	}

	.msg {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		margin-bottom: 14px;
	}

	/* 自己的消息靠右：整列右对齐即可，不用另写一套结构 */
	.msg--mine {
		align-items: flex-end;
	}

	.msg-name {
		margin-bottom: 4px;
		font-size: 12px;
		color: #86868b;
	}

	.bubble {
		max-width: 76%;
		padding: 9px 13px;
		background: #ffffff;
		border-radius: 14px;
	}

	.bubble--mine {
		background: #409eff;
	}

	.bubble--failed {
		border: 1px solid #f56c6c;
	}

	.bubble-text {
		font-size: 16px;
		line-height: 1.5;
		color: #1d1d1f;
		word-break: break-all;
	}

	.bubble-text--mine {
		color: #ffffff;
	}

	.msg-state {
		margin-top: 4px;
		font-size: 11px;
		color: #86868b;
	}

	.composer {
		display: flex;
		align-items: center;
		padding: 8px 12px;
		padding-bottom: calc(8px + constant(safe-area-inset-bottom));
		padding-bottom: calc(8px + env(safe-area-inset-bottom));
		background: #ffffff;
		border-top: 1px solid #f0f0f2;
	}

	.composer-input {
		flex: 1;
		height: 38px;
		padding: 0 14px;
		margin-right: 8px;
		font-size: 16px;
		color: #1d1d1f;
		background: #f5f5f7;
		border-radius: 19px;
	}

	/* 胶囊按钮 + 按下缩放，与登录页一致 */
	.composer-btn {
		padding: 0 18px;
		height: 38px;
		display: flex;
		align-items: center;
		background: #409eff;
		border-radius: 19px;
	}

	.composer-btn--off {
		background: #c7c7cc;
	}

	.composer-btn--hover {
		transform: scale(0.96);
	}

	.composer-btn-text {
		font-size: 15px;
		color: #ffffff;
	}

	.hint {
		display: block;
		padding: 60px 20px;
		text-align: center;
		font-size: 14px;
		color: #86868b;
	}
</style>
