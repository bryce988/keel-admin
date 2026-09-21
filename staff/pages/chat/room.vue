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
					<!-- 撤回：整行居中灰字，不留气泡——留着会让人以为内容还在 -->
					<text v-if="m.status === 2" class="recalled">
						{{ isMine(m) ? '你撤回了一条消息' : m.sender_name + ' 撤回了一条消息' }}
					</text>

					<block v-else>
						<text class="msg-name">{{ isMine(m) ? '我' : m.sender_name }}</text>

						<!-- 图片：不套气泡，套了会多一圈底色 -->
						<image
							v-if="m.type === 'image' && m.extra"
							class="msg-image"
							:src="absUrl(m.extra.url)"
							mode="widthFix"
							@click="previewImage(m)"
							@longpress="onLongPress(m)"
						/>

						<view
							v-else-if="m.type === 'file' && m.extra"
							class="msg-file"
							@longpress="onLongPress(m)"
						>
							<text class="msg-file-name">{{ m.extra.name }}</text>
							<text class="msg-file-size">{{ humanSize(m.extra.size) }}</text>
						</view>

						<view
							v-else
							class="bubble"
							:class="{ 'bubble--mine': isMine(m), 'bubble--failed': m._state === 'failed' }"
							@longpress="onLongPress(m)"
						>
							<text class="bubble-text" :class="{ 'bubble-text--mine': isMine(m) }">{{ m.content }}</text>
						</view>

						<view v-if="m._state || readHintOf(m)" class="msg-foot">
							<text v-if="m._state === 'sending'" class="msg-state">发送中…</text>
							<block v-else-if="m._state === 'failed'">
								<text class="msg-state msg-state--failed">发送失败</text>
								<text class="msg-resend" @click="resend(m)">重发</text>
							</block>
							<text v-else class="msg-state">{{ readHintOf(m) }}</text>
						</view>
					</block>
				</view>

				<text v-if="!messages.length" class="hint">{{ loading ? '正在加载' : '还没有消息，说点什么吧' }}</text>
			</view>
		</scroll-view>

		<view class="composer">
			<view class="composer-tool" hover-class="composer-tool--hover" @click="pickImage">
				<text class="composer-tool-text">图</text>
			</view>
			<view class="composer-tool" hover-class="composer-tool--hover" @click="pickFile">
				<text class="composer-tool-text">件</text>
			</view>
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
	import {
		fetchChatMessages,
		sendChatMessage,
		markChatRead,
		uploadChatFile,
		recallChatMessage
	} from '@/common/api.js'
	import { getCachedUser, absUrl } from '@/common/request.js'
	import { chatSocket } from '@/common/chatSocket.js'

	const convId = ref(0)
	const messages = ref([])
	const draft = ref('')
	const loading = ref(false)
	const sending = ref(false)
	const scrollTop = ref(0)
	const myId = ref(0)
	const uploading = ref(false)
	/** 对方读到哪条了，用于给我最后一条消息打「已读」 */
	const peerReadSeq = ref(0)

	/** 乐观上屏用的占位 seq：比任何真实 seq 都大，保证排在末尾 */
	const PENDING_SEQ = Number.MAX_SAFE_INTEGER

	let offReady = null
	let offMessage = null
	let offRecalled = null
	let offRead = null

	function isMine(m) {
		return m.sender_id === myId.value
	}

	async function loadHistory() {
		loading.value = true
		try {
			messages.value = await fetchChatMessages(convId.value, { limit: 30 })
			await toBottom()
			await flushRead()

			/*
			 * 初始化对方的已读水位
			 *
			 * 不做的话刷新后「已读」会消失，直到对方**再读一次**才回来——
			 * 而对方可能早就读完了，不会再触发任何事件。
			 * 保守近似：只要我不是最后一个发言的人，说明对方看过了。
			 * 在「对方发过消息之后」总是对的，而那是绝大多数情况。
			 */
			const last = messages.value[messages.value.length - 1]
			if (last && last.sender_id !== myId.value) {
				peerReadSeq.value = Math.max(peerReadSeq.value, last.seq)
			}
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
		if (!text) return

		draft.value = ''
		await deliver({ type: 'text', content: text })
	}

	/**
	 * 发一条消息：乐观上屏 → 请求 → 归位
	 *
	 * 文本、图片、文件三种走同一条路径，失败重发也复用它——
	 * 三处各写一遍的话，「失败要标红」「成功要按 seq 归位」总有一处会漏。
	 */
	async function deliver(payload, reuseClientMsgId) {
		if (sending.value) return

		const clientMsgId = reuseClientMsgId || uuid()

		// 重发复用同一个 client_msg_id：服务端靠它幂等，换一个的话
		// 「超时但其实成功了」的那条会变成两条
		if (reuseClientMsgId) {
			const existing = messages.value.find((m) => m.client_msg_id === clientMsgId)
			if (existing) existing._state = 'sending'
		} else {
			messages.value.push({
				id: 0,
				conv_id: convId.value,
				seq: PENDING_SEQ,
				sender_id: myId.value,
				sender_name: '我',
				type: payload.type,
				content: payload.content,
				extra: payload.extra || null,
				client_msg_id: clientMsgId,
				status: 1,
				created_at: '',
				_state: 'sending'
			})
			await toBottom()
		}

		sending.value = true
		try {
			const saved = await sendChatMessage(convId.value, {
				client_msg_id: clientMsgId,
				type: payload.type,
				content: payload.content,
				extra: payload.extra || null
			})

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

	/** 点击重发。用原来那条的内容与 client_msg_id，不新建一条 */
	function resend(m) {
		if (m._state !== 'failed') return
		deliver({ type: m.type, content: m.content, extra: m.extra }, m.client_msg_id)
	}

	/**
	 * 发图片
	 *
	 * 两步：先传拿 url，再发一条 image 消息。上传走的是通用接口，它不知道聊天
	 * 的存在；发消息才是聊天的事。中间失败只留一个孤儿文件，由留存清理带走。
	 */
	function pickImage() {
		uni.chooseImage({
			count: 1,
			sizeType: ['compressed'],
			success: (res) => sendAttachment(res.tempFilePaths[0], 'image')
		})
	}

	/**
	 * 发文件
	 *
	 * ⚠️ `uni.chooseFile` **只有 H5 与微信小程序有**，App 端没有这个 API。
	 * App 上退回到「从相册选」，等于只能发图——真要在 App 上发任意文件，
	 * 得接原生的文件选择插件，那是另一件事。这里先把能力差异显式说出来，
	 * 而不是让按钮点了没反应。
	 */
	function pickFile() {
		// #ifdef H5 || MP-WEIXIN
		uni.chooseFile({
			count: 1,
			success: (res) => sendAttachment(res.tempFilePaths[0], 'file')
		})
		return
		// #endif

		// #ifndef H5 || MP-WEIXIN
		uni.showToast({ title: '当前版本只支持发图片', icon: 'none' })
		// #endif
	}

	async function sendAttachment(filePath, type) {
		if (!filePath) return

		uploading.value = true
		uni.showLoading({ title: '上传中' })
		try {
			const up = await uploadChatFile(filePath)
			await deliver({
				type,
				content: up.name,
				extra: { url: up.url, name: up.name, size: up.size, ext: up.ext }
			})
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message || '上传失败', icon: 'none' })
		} finally {
			uploading.value = false
			uni.hideLoading()
		}
	}

	/** 预览图片。用 uni 的原生预览，能双指缩放、能保存到相册 */
	function previewImage(m) {
		if (!m.extra || !m.extra.url) return
		uni.previewImage({ urls: [absUrl(m.extra.url)], current: 0 })
	}

	/**
	 * 长按消息出操作菜单
	 *
	 * 移动端没有 hover，桌面那套「悬停出按钮」在这里不成立。
	 * 长按是 iOS/Android 上「对这一条做点什么」的通用手势。
	 */
	function onLongPress(m) {
		/*
		 * 菜单项按消息动态拼
		 *
		 * ⚠️ 之前这里是 `if (!canRecall(m)) return`——长按别人的消息、或超过
		 * 两分钟的消息，什么都不会发生。用户不知道是手势没识别还是没这个功能，
		 * 只会反复长按。**没有可用动作时也要让这次交互有个结果**，
		 * 所以文本一律给「复制」，撤回按条件加。
		 */
		const actions = []

		if (m.type === 'text') {
			actions.push({ label: '复制', run: () => copyText(m) })
		}
		if (canRecall(m)) {
			actions.push({ label: '撤回', run: () => doRecall(m) })
		}

		// 一个动作都没有（别人发的图片/文件）：单击已经能预览/下载，
		// 这里静默返回是可接受的，不弹一个只有「取消」的空菜单
		if (!actions.length) return

		uni.showActionSheet({
			itemList: actions.map((a) => a.label),
			success: ({ tapIndex }) => actions[tapIndex] && actions[tapIndex].run()
		})
	}

	function copyText(m) {
		uni.setClipboardData({
			data: m.content,
			// 默认会弹一句「内容已复制」的系统提示，再弹自己的就是两条
			showToast: true
		})
	}

	async function doRecall(m) {
		try {
			const updated = await recallChatMessage(m.id)
			applyRecall(updated)
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	/** 2 分钟内、自己发的、还没撤过的，才可撤回。服务端仍会再判一次 */
	function canRecall(m) {
		if (m.sender_id !== myId.value || m.status !== 1 || !m.created_at || m._state) return false

		// iOS 的 Date 解析不认 'YYYY-MM-DD HH:mm:ss'，必须把横杠换成斜杠，
		// 否则得到 NaN、判断恒为 false，表现是「iPhone 上撤不回」
		return Date.now() - new Date(m.created_at.replace(/-/g, '/')).getTime() < 120000
	}

	function applyRecall(updated) {
		const idx = messages.value.findIndex((m) => m.id === updated.id)
		if (idx >= 0) messages.value[idx] = Object.assign({}, messages.value[idx], updated)
	}

	/** 文件大小说成人话 */
	function humanSize(bytes) {
		if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB'
		if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB'
		return bytes + ' B'
	}

	/** 我发出的最后一条的 seq —— 「已读」只标这一条 */
	function isMyLast(m) {
		const mine = messages.value.filter((x) => x.sender_id === myId.value && !x._state)
		return mine.length > 0 && mine[mine.length - 1].seq === m.seq
	}

	function readHintOf(m) {
		if (m.sender_id !== myId.value || !isMyLast(m) || m.status !== 1) return ''
		return peerReadSeq.value >= m.seq ? '已读' : '未读'
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
		offRecalled = chatSocket.on('message.recalled', applyRecall)

		/*
		 * 对方已读：记下水位，用于给我最后一条消息打「已读」。
		 * 要排掉自己的回执——我在电脑上标已读时这个事件也会推给手机，
		 * 不排的话「对方已读」会在我自己读消息时亮起来
		 */
		offRead = chatSocket.on('conversation.read', (d) => {
			if (!d || d.conv_id !== convId.value || d.user_id === myId.value) return
			peerReadSeq.value = Math.max(peerReadSeq.value, d.last_read_seq)
		})

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
		if (offRecalled) offRecalled()
		if (offRead) offRead()
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
		/*
		 * ⚠️ 禁用浏览器/WebView 的原生长按行为
		 *
		 * 不加的话 H5 与 App 的 WebView 会在长按时弹自己的「复制 / 搜索」菜单，
		 * 把 uni.showActionSheet 盖掉或抢先触发，表现是「长按撤回时好时坏」。
		 * 代价是气泡里的文字不能再手选——所以菜单里必须提供「复制」，
		 * 那是用户真正想要的那个动作。
		 */
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
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

	.msg-foot {
		display: flex;
		flex-direction: row;
		align-items: center;
		margin-top: 4px;
	}

	.msg-state {
		font-size: 11px;
		color: #86868b;
	}

	.msg-state--failed {
		color: #f56c6c;
	}

	.msg-resend {
		margin-left: 8px;
		font-size: 11px;
		color: #409eff;
	}

	/* 撤回：整行居中灰字，不留气泡 */
	.recalled {
		align-self: center;
		font-size: 12px;
		color: #86868b;
	}

	/* widthFix 会按原图比例算高度，所以只给宽度上限；给死高度会把图拉变形 */
	.msg-image {
		/* 同气泡：不禁用的话长按会先弹出「存储图片」 */
		-webkit-touch-callout: none;
		width: 180px;
		border-radius: 12px;
		background: #ffffff;
	}

	.msg-file {
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
		max-width: 76%;
		padding: 10px 13px;
		background: #ffffff;
		border-radius: 12px;
	}

	.msg-file-name {
		display: block;
		font-size: 15px;
		color: #1d1d1f;
	}

	.msg-file-size {
		display: block;
		margin-top: 2px;
		font-size: 12px;
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

	/* 附件按钮：圆形，与发送按钮同高，不抢视线 */
	.composer-tool {
		width: 34px;
		height: 34px;
		margin-right: 6px;
		display: flex;
		align-items: center;
		justify-content: center;
		background: #f5f5f7;
		border-radius: 17px;
		flex-shrink: 0;
	}

	.composer-tool--hover {
		background: #e8e8ed;
	}

	.composer-tool-text {
		font-size: 14px;
		color: #6e6e73;
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
