<template>
	<view class="room">
		<!-- 群信息条：只在群聊出现。room 用的是原生导航栏，加不了右上角按钮，
		     所以群入口放在内容区顶部 -->
		<view v-if="isGroup" class="groupbar" hover-class="groupbar--hover" @click="toMembers">
			<text class="groupbar-text">{{ memberCount }} 人</text>
			<text class="groupbar-more">群成员 ›</text>
		</view>

		<scroll-view
			class="stream"
			scroll-y
			:scroll-top="scrollTop"
			:scroll-into-view="anchorId"
			:scroll-with-animation="false"
			:upper-threshold="60"
			@scrolltoupper="loadOlder"
			@click="closePanels"
		>
			<view class="stream-inner">
				<!-- 只在真的翻过页时说「没有更多」，短会话里挂这句是噪音 -->
				<text v-if="loadingOlder" class="more">加载中…</text>
				<text v-else-if="!hasMore && messages.length > PAGE_SIZE" class="more">没有更早的消息了</text>

				<!-- id 给 scroll-into-view 用：翻页后把视口钉回原来那条。不能以数字开头 -->
				<view v-for="r in rows" :key="r.m.client_msg_id || r.m.id" :id="'m' + r.m.seq">
					<!-- 时间分隔：与上一条隔了 5 分钟以上才出现，按今天 / 今年 / 往年分档 -->
					<text v-if="r.divider" class="divider">{{ r.divider }}</text>

					<!-- 系统消息（入群、改群名、解散）：与撤回同一种居中灰字 -->
					<text v-if="r.m.type === 'system'" class="recalled">{{ r.m.content }}</text>

					<!-- 撤回：整行居中灰字，不留气泡——留着会让人以为内容还在 -->
					<text v-else-if="r.m.status === 2" class="recalled">
						{{
							r.m.recalled_by !== r.m.sender_id
								? '一条消息已被群主撤回'
								: isMine(r.m)
									? '你撤回了一条消息'
									: r.m.sender_name + ' 撤回了一条消息'
						}}
					</text>

					<view
						v-else
						class="msg"
						:class="{ 'msg--mine': isMine(r.m), 'msg--continued': r.continued }"
					>
						<!-- 连发的消息不再重复头像，但留出同样的宽度，气泡才对得齐 -->
						<view v-if="r.continued" class="msg-avatar msg-avatar--gap" />
						<view v-else class="msg-avatar" @click.stop="toProfile(r.m)">
							<image v-if="avatarOf(r.m)" class="msg-avatar-img" :src="avatarOf(r.m)" mode="aspectFill" />
							<text v-else class="msg-avatar-text">{{ initialOf(r.m) }}</text>
						</view>

						<view class="msg-body">
							<!-- 名字只在群聊里给别人的消息标：单聊就两个人，自己的消息也不用写「我」 -->
							<text v-if="!r.continued && isGroup && !isMine(r.m)" class="msg-name">{{ r.m.sender_name }}</text>

							<!-- 图片：不套气泡，套了会多一圈底色 -->
							<image
								v-if="r.m.type === 'image' && r.m.extra"
								class="msg-image"
								:src="absUrl(r.m.extra.url)"
								mode="widthFix"
								@click.stop="previewImage(r.m)"
								@longpress="onLongPress(r.m)"
							/>

							<view
								v-else-if="r.m.type === 'file' && r.m.extra"
								class="msg-file"
								@longpress="onLongPress(r.m)"
							>
								<text class="msg-file-name">{{ r.m.extra.name }}</text>
								<text class="msg-file-size">{{ humanSize(r.m.extra.size) }}</text>
							</view>

							<view
								v-else
								class="bubble"
								:class="{ 'bubble--mine': isMine(r.m), 'bubble--failed': r.m._state === 'failed' }"
								@longpress="onLongPress(r.m)"
							>
								<text class="bubble-text">{{ r.m.content }}</text>
							</view>

							<view v-if="r.m._state || readHintOf(r.m)" class="msg-foot">
								<text v-if="r.m._state === 'sending'" class="msg-state">发送中…</text>
								<block v-else-if="r.m._state === 'failed'">
									<text class="msg-state msg-state--failed">发送失败</text>
									<text class="msg-resend" @click.stop="resend(r.m)">重发</text>
								</block>
								<text v-else class="msg-state">{{ readHintOf(r.m) }}</text>
							</view>
						</view>
					</view>
				</view>

				<text v-if="!messages.length" class="hint">
					{{ loading ? '正在加载' : canSend ? '还没有消息，说点什么吧' : '没有聊天记录' }}
				</text>
			</view>
		</scroll-view>

		<!-- @ 面板。room 不是 tabBar 页，所以自定义遮罩盖得住；
		     换成 tab 页的话只能用 uni.showActionSheet，而它最多 6 项、装不下群成员 -->
		<view v-if="atVisible" class="atmask" @click="atVisible = false">
			<view class="atpanel" @click.stop>
				<view v-if="isOwner" class="atrow" hover-class="atrow--hover" @click="pickAtAll">
					<text class="atrow-all">@所有人</text>
				</view>
				<scroll-view class="atlist" scroll-y>
					<view
						v-for="m in atCandidates"
						:key="m.user_id"
						class="atrow"
						hover-class="atrow--hover"
						@click="pickMention(m)"
					>
						<text class="atrow-name">{{ m.real_name }}</text>
					</view>
				</scroll-view>
				<text v-if="!atCandidates.length" class="atempty">{{ membersLoaded ? '群里没有其他成员' : '正在加载成员' }}</text>
			</view>
		</view>

		<!-- 单聊对方离职：历史照看，输入区换成一句说明。服务端同样会拦（400 + 21205） -->
		<view v-if="!canSend" class="readonly">
			<text class="readonly-text">对方已离职，无法再发送消息</text>
		</view>

		<view v-else class="dock">
			<view class="composer">
				<input
					v-model="draft"
					class="composer-input"
					type="text"
					placeholder="说点什么"
					confirm-type="send"
					:maxlength="MAX_LEN"
					:cursor="cursor"
					:adjust-position="true"
					@input="onInput"
					@blur="onInput"
					@focus="closePanels"
					@confirm="send"
				/>
				<view class="composer-tool" hover-class="composer-tool--hover" @click="togglePanel('emoji')">
					<text class="composer-tool-text">{{ panel === 'emoji' ? '⌨' : '☺' }}</text>
				</view>
				<!-- 有字时「+」换成「发送」（微信同款）：一行里放不下五个按钮，
				     而有字的时候最想按的就是发送 -->
				<view
					v-if="draft.trim()"
					class="composer-btn"
					hover-class="composer-btn--hover"
					@click="send"
				>
					<text class="composer-btn-text">发送</text>
				</view>
				<view v-else class="composer-tool" hover-class="composer-tool--hover" @click="togglePanel('more')">
					<text class="composer-tool-text composer-tool-text--plus">+</text>
				</view>
			</view>

			<!-- 表情：点一个插在光标处，不收起面板，方便连着点 -->
			<scroll-view v-if="panel === 'emoji'" class="emoji" scroll-y>
				<view class="emoji-grid">
					<text v-for="e in EMOJIS" :key="e" class="emoji-item" @click="insertText(e)">{{ e }}</text>
				</view>
			</scroll-view>

			<view v-if="panel === 'more'" class="more-panel">
				<view class="more-item" hover-class="more-item--hover" @click="pickImage">
					<view class="more-icon"><text class="more-icon-text">图</text></view>
					<text class="more-label">图片</text>
				</view>
				<view class="more-item" hover-class="more-item--hover" @click="pickFile">
					<view class="more-icon"><text class="more-icon-text">件</text></view>
					<text class="more-label">文件</text>
				</view>
				<view v-if="isGroup" class="more-item" hover-class="more-item--hover" @click="openAt">
					<view class="more-icon"><text class="more-icon-text">@</text></view>
					<text class="more-label">提醒</text>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup>
	import { ref, computed, nextTick } from 'vue'
	import { onLoad, onUnload, onShow } from '@dcloudio/uni-app'
	import {
		fetchChatMessages,
		sendChatMessage,
		markChatRead,
		uploadChatFile,
		recallChatMessage,
		fetchChatConversation,
		fetchChatMembers
	} from '@/common/api.js'
	import { getCachedUser, absUrl } from '@/common/request.js'
	import { chatSocket } from '@/common/chatSocket.js'
	import { formatMessageTime, parseChatTime } from '@/common/chatTime.js'
	import { EMOJIS } from '@/common/emoji.js'

	/** 一页多少条，也是「还有没有更早的」的判据：取回来不满一页就是到头了 */
	const PAGE_SIZE = 30
	/** 与服务端 chat.message.maxLength 默认值一致。uni 的 input 不设的话默认只能输 140 字 */
	const MAX_LEN = 5000
	/** 连续消息合并、插时间分隔的间隔 */
	const GAP_MS = 5 * 60 * 1000
	/** 乐观上屏用的占位 seq：比任何真实 seq 都大，保证排在末尾 */
	const PENDING_SEQ = Number.MAX_SAFE_INTEGER
	/** 「不能与已停用的员工发起会话」，发消息时对方刚离职也回这个码 */
	const CODE_PEER_DISABLED = 21205

	const convId = ref(0)
	const messages = ref([])
	const draft = ref('')
	const loading = ref(false)
	const sending = ref(false)
	const scrollTop = ref(0)
	const anchorId = ref('')
	const myId = ref(0)
	const myAvatar = ref('')
	const uploading = ref(false)
	/** 对方读到哪条了，用于给我最后一条消息打「已读」 */
	const peerReadSeq = ref(0)

	/** 会话信息（loadConversation 填） */
	const isGroup = ref(false)
	const memberCount = ref(0)
	const isOwner = ref(false)
	const peerActive = ref(true)
	const peerId = ref(0)
	const peerAvatar = ref('')
	const members = ref([])
	const membersLoaded = ref(false)

	/** 向上翻页 */
	const hasMore = ref(false)
	const loadingOlder = ref(false)

	/** 输入区下方的面板：表情 / 更多（图片、文件、@），同一时刻只开一个 */
	const panel = ref('')
	/** input 的光标位置。插表情、插 @ 都插在这里，而不是一律追加到末尾 */
	const cursor = ref(-1)

	/** @ 面板。mentioned 记点选过谁，发送时以**最终文本**为准反查 id */
	const atVisible = ref(false)
	const mentioned = ref({})

	let offReady = null
	let offMessage = null
	let offRecalled = null
	let offRead = null

	const canSend = computed(() => isGroup.value || peerActive.value)

	const atCandidates = computed(() => members.value.filter((m) => m.user_id !== myId.value))

	/** 群成员 id → 头像 */
	const memberAvatars = computed(() => {
		const map = {}
		members.value.forEach((m) => {
			map[m.user_id] = m.avatar
		})
		return map
	})

	function isMine(m) {
		return m.sender_id === myId.value
	}

	/**
	 * 消息头像
	 *
	 * 不存进消息、按发送人实时查：存进去的话换了头像，历史消息还是旧的。
	 * 我自己取登录缓存；单聊对方是会话头像；群聊查成员表，查不到（已退群）退回首字
	 */
	function avatarOf(m) {
		if (isMine(m)) return myAvatar.value
		if (!isGroup.value) return peerAvatar.value ? absUrl(peerAvatar.value) : ''
		const a = memberAvatars.value[m.sender_id]
		return a ? absUrl(a) : ''
	}

	function initialOf(m) {
		const name = isMine(m) ? ((getCachedUser() || {}).real_name || '我') : m.sender_name
		return (name || '?').slice(0, 1)
	}

	// ------------------------------------------------------------ 排版

	/** 发送中的消息还没有服务端时间，按「现在」算——它确实就是现在发的 */
	function tsOf(m) {
		const d = parseChatTime(m.created_at)
		return d ? d.getTime() : Date.now()
	}

	function isPlain(m) {
		return m.type !== 'system' && m.status !== 2
	}

	/**
	 * 每条消息的排版信息：要不要插时间分隔、是不是同一个人的连发
	 *
	 * 时间差取绝对值：顺序以 seq 为准，时间只是展示。服务器时钟回拨、导入的老数据
	 * 都可能让后一条的时间更早，这时同样该插分隔，而不是被当成连发合并掉
	 */
	const rows = computed(() =>
		messages.value.map((m, i) => {
			const prev = i > 0 ? messages.value[i - 1] : null
			const gap = prev ? Math.abs(tsOf(m) - tsOf(prev)) : Infinity
			const divider = gap > GAP_MS ? formatMessageTime(new Date(tsOf(m))) : ''
			const continued = !!prev && !divider && isPlain(m) && isPlain(prev) && prev.sender_id === m.sender_id

			return { m, divider, continued }
		})
	)

	// ------------------------------------------------------------ 会话与成员

	/**
	 * 会话信息：群还是单聊、人数、我是不是群主、对方在不在职、对方头像
	 *
	 * 进页面与从群成员页返回时各拉一次（人数、群名可能变了）
	 */
	async function loadConversation() {
		try {
			const c = await fetchChatConversation(convId.value)
			isGroup.value = c.type === 2
			memberCount.value = c.member_count
			isOwner.value = c.owner_id === myId.value
			peerId.value = c.peer_id
			peerAvatar.value = c.avatar
			// 老接口没有这个字段时按「在职」处理：宁可让服务端拦一次，也不要误关输入框
			peerActive.value = c.peer_active !== false
			if (c.name) uni.setNavigationBarTitle({ title: c.name })

			if (isGroup.value) await loadMembers()
			else members.value = []
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	/** 群成员：消息头像与 @ 面板都用它 */
	async function loadMembers() {
		try {
			members.value = await fetchChatMembers(convId.value)
		} catch (e) {
			/* 取不到就让头像退回首字、@ 面板空着，不影响聊天 */
		} finally {
			membersLoaded.value = true
		}
	}

	function toMembers() {
		uni.navigateTo({ url: `/pages/chat/members?id=${convId.value}` })
	}

	/** 点头像看资料。单聊带上会话 id：名片上点「发消息」就直接回来，不再压一层 */
	function toProfile(m) {
		const id = isMine(m) ? myId.value : m.sender_id
		const name = isMine(m) ? (getCachedUser() || {}).real_name || '' : m.sender_name
		const conv = isGroup.value ? 0 : convId.value
		uni.navigateTo({ url: `/pages/chat/profile?id=${id}&name=${encodeURIComponent(name)}&conv=${conv}` })
	}

	// ------------------------------------------------------------ 历史

	async function loadHistory() {
		loading.value = true
		try {
			messages.value = await fetchChatMessages(convId.value, { limit: PAGE_SIZE })
			hasMore.value = messages.value.length === PAGE_SIZE
			await toBottom()
			await flushRead()

			/*
			 * 初始化对方的已读水位
			 *
			 * 不做的话刷新后「已读」会消失，直到对方**再读一次**才回来——
			 * 而对方可能早就读完了，不会再触发任何事件。
			 * 保守近似：只要我不是最后一个发言的人，说明对方看过了。
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

	/**
	 * 滚到顶取更早的一页
	 *
	 * ⚠️ 插到前面之后要把视口钉回原来那条：内容在上方长高了，不管的话
	 * 用户正在看的那条会被顶出屏幕。scroll-view 拿不到可靠的 scrollHeight，
	 * 所以用 scroll-into-view 定位到插入前的第一条，用完立刻清掉——
	 * 留着的话下次它还会把视口拽回去
	 */
	async function loadOlder() {
		if (!hasMore.value || loadingOlder.value || loading.value) return

		const first = messages.value.find((m) => m.seq !== PENDING_SEQ)
		if (!first) return

		loadingOlder.value = true
		try {
			const older = await fetchChatMessages(convId.value, { before_seq: first.seq, limit: PAGE_SIZE })
			hasMore.value = older.length === PAGE_SIZE
			if (!older.length) return

			messages.value = older.concat(messages.value)
			await nextTick()
			anchorId.value = 'm' + first.seq
			await nextTick()
			anchorId.value = ''
		} catch (e) {
			/* 失败就停在原地，下次滚到顶再试 */
		} finally {
			loadingOlder.value = false
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

		// 入群、退群、改群名都会落一条系统消息：人数、成员头像、标题跟着刷新
		if (msg.type === 'system' && isGroup.value) loadConversation()

		await toBottom()
		await flushRead()
	}

	// ------------------------------------------------------------ 发送

	async function send() {
		const text = draft.value.trim()
		if (!text || !canSend.value) return

		const mentions = collectMentions(text)

		draft.value = ''
		cursor.value = -1
		atVisible.value = false
		mentioned.value = {}

		await deliver({ type: 'text', content: text, extra: mentions })
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
			// 发的时候对方刚好离职：立刻切到只读，不用等下次进来
			if (e.code === CODE_PEER_DISABLED) peerActive.value = false
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

	// ------------------------------------------------------------ 输入

	function onInput(e) {
		if (e && e.detail && typeof e.detail.cursor === 'number') cursor.value = e.detail.cursor
	}

	/**
	 * 在光标处插入一段文字（表情、@姓名）
	 *
	 * 光标位置来自 input / blur 事件；没拿到过（从没点过输入框）就追加到末尾。
	 * 插完把光标设到插入内容之后，否则连点几个表情会倒着排
	 */
	function insertText(piece) {
		const text = draft.value
		const at = cursor.value >= 0 && cursor.value <= text.length ? cursor.value : text.length

		const next = text.slice(0, at) + piece + text.slice(at)
		if ([...next].length > MAX_LEN) {
			uni.showToast({ title: `消息不能超过 ${MAX_LEN} 字`, icon: 'none' })
			return
		}

		draft.value = next
		cursor.value = at + piece.length
	}

	function togglePanel(name) {
		panel.value = panel.value === name ? '' : name
		// 面板和软键盘不能同时出现，不收键盘的话面板会被顶到屏幕外
		if (panel.value) uni.hideKeyboard()
		toBottom()
	}

	function closePanels() {
		panel.value = ''
	}

	// ------------------------------------------------------------ @

	/** 打开 @ 面板。成员随会话信息一起拉过了；没拉到就补一次 */
	function openAt() {
		panel.value = ''
		atVisible.value = true
		if (!membersLoaded.value) loadMembers()
	}

	/** 选中某人：插入 `@姓名 ` 并记下 id */
	function pickMention(m) {
		insertText(`@${m.real_name} `)
		mentioned.value = Object.assign({}, mentioned.value, { [m.real_name]: m.user_id })
		atVisible.value = false
	}

	/** @所有人：只有群主能用，服务端也会再判一次 */
	function pickAtAll() {
		insertText('@所有人 ')
		atVisible.value = false
	}

	/**
	 * 从最终文本里解出 @ 了谁
	 *
	 * 以文本为准而不是以点选记录为准：点了 `@张明` 又把它删掉，记录里还在但文本里没有——
	 * 按记录发就会给一个没被 @ 的人种一个他自己清不掉的红点
	 */
	function collectMentions(text) {
		if (!isGroup.value) return null

		const atAll = isOwner.value && text.indexOf('@所有人') >= 0
		const ids = Object.keys(mentioned.value)
			.filter((name) => text.indexOf(`@${name}`) >= 0)
			.map((name) => mentioned.value[name])

		if (!atAll && !ids.length) return null

		return { at_all: atAll, at_user_ids: ids }
	}

	// ------------------------------------------------------------ 附件

	/**
	 * 发图片
	 *
	 * 两步：先传拿 url，再发一条 image 消息。上传走的是通用接口，它不知道聊天
	 * 的存在；发消息才是聊天的事。中间失败只留一个孤儿文件，由留存清理带走。
	 */
	function pickImage() {
		panel.value = ''
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
	 * App 上把能力差异显式说出来，而不是让按钮点了没反应。
	 */
	function pickFile() {
		panel.value = ''
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

	// ------------------------------------------------------------ 长按

	/**
	 * 长按消息出操作菜单
	 *
	 * 移动端没有 hover，长按是「对这一条做点什么」的通用手势。
	 * 没有可用动作时也要让这次交互有个结果，所以文本一律给「复制」，撤回按条件加
	 */
	function onLongPress(m) {
		const actions = []

		if (m.type === 'text') {
			actions.push({ label: '复制', run: () => copyText(m) })
		}
		if (canRecall(m)) {
			actions.push({ label: '撤回', run: () => doRecall(m) })
		}

		// 一个动作都没有（别人发的图片/文件）：单击已经能预览/下载，不弹只有「取消」的空菜单
		if (!actions.length) return

		uni.showActionSheet({
			itemList: actions.map((a) => a.label),
			success: ({ tapIndex }) => actions[tapIndex] && actions[tapIndex].run()
		})
	}

	function copyText(m) {
		uni.setClipboardData({ data: m.content, showToast: true })
	}

	async function doRecall(m) {
		try {
			const updated = await recallChatMessage(m.id)
			applyRecall(updated)
		} catch (e) {
			if (e.code !== 401) uni.showToast({ title: e.message, icon: 'none' })
		}
	}

	/** 2 分钟内、自己发的、还没撤过的，才可撤回；群主不受限。服务端仍会再判一次 */
	function canRecall(m) {
		if (m.status !== 1 || !m.created_at || m._state) return false
		if (isGroup.value && isOwner.value) return true
		if (m.sender_id !== myId.value) return false

		const d = parseChatTime(m.created_at)
		return !!d && Date.now() - d.getTime() < 120000
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

	/** 我发出的最后一条的 seq —— 「已读」只标这一条，且只在单聊 */
	const myLastSeq = computed(() => {
		const mine = messages.value.filter((x) => x.sender_id === myId.value && !x._state)
		return mine.length ? mine[mine.length - 1].seq : 0
	})

	function readHintOf(m) {
		if (isGroup.value || m.sender_id !== myId.value || m.seq !== myLastSeq.value || m.status !== 1) return ''
		return peerReadSeq.value >= m.seq ? '已读' : '未读'
	}

	/**
	 * 滚到底
	 *
	 * scroll-view 的 scrollTop 是**受控**的：赋同样的值不会再触发滚动，
	 * 所以每次要给一个不同的大数。
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
		const me = getCachedUser() || {}
		myId.value = Number(me.id || 0)
		myAvatar.value = me.avatar ? absUrl(me.avatar) : ''

		if (options.name) {
			uni.setNavigationBarTitle({ title: decodeURIComponent(options.name) })
		}

		/*
		 * 重连后的对齐：ready 是「握手完成」的信号，每次重连都会再来一次。
		 * 收到就重拉一次——断线期间到达的消息靠这一步补齐。
		 */
		offReady = chatSocket.on('ready', () => {
			if (convId.value) loadHistory()
		})
		offMessage = chatSocket.on('message.new', onPush)
		offRecalled = chatSocket.on('message.recalled', applyRecall)

		/*
		 * 对方已读：记下水位，用于给我最后一条消息打「已读」。
		 * 要排掉自己的回执——我在电脑上标已读时这个事件也会推给手机
		 */
		offRead = chatSocket.on('conversation.read', (d) => {
			if (!d || d.conv_id !== convId.value || d.user_id === myId.value) return
			peerReadSeq.value = Math.max(peerReadSeq.value, d.last_read_seq)
		})

		chatSocket.connect()
		await Promise.all([loadHistory(), loadConversation()])
	})

	// 从后台切回前台时补一次：息屏期间连接可能已经被系统回收了
	onShow(() => {
		if (!convId.value) return
		if (!chatSocket.connected) chatSocket.connect()
		// 从群成员页返回时人数可能变了（加了人 / 踢了人）；从「我的」换了头像回来也要更新
		const me = getCachedUser() || {}
		myAvatar.value = me.avatar ? absUrl(me.avatar) : ''
		if (isGroup.value) loadConversation()
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
		background-color: var(--keel-bg-color-page);
	}

	.groupbar {
		display: flex;
		flex-direction: row;
		align-items: center;
		justify-content: space-between;
		padding: 8px 16px;
		background-color: var(--keel-bg-color);
		border-bottom: 1px solid var(--keel-border-color-lighter);
	}

	.groupbar--hover {
		background-color: var(--keel-pressed-bg);
	}

	.groupbar-text {
		font-size: 13px;
		color: var(--keel-text-color-secondary);
	}

	.groupbar-more {
		font-size: 13px;
		color: var(--keel-color-primary);
	}

	.stream {
		flex: 1;
		min-height: 0;
	}

	.stream-inner {
		padding: 12px 12px 8px;
	}

	.more,
	.divider {
		display: block;
		padding: 4px 0 12px;
		text-align: center;
		font-size: 12px;
		color: var(--keel-text-color-placeholder);
	}

	.msg {
		display: flex;
		flex-direction: row;
		align-items: flex-start;
		margin-bottom: 14px;
	}

	.msg--mine {
		flex-direction: row-reverse;
	}

	/* 连发贴近上一条：14px 的常规间距减到 4px，看得出是「一口气说的」 */
	.msg--continued {
		margin-top: -10px;
	}

	.msg-avatar {
		flex-shrink: 0;
		width: 38px;
		height: 38px;
		border-radius: 19px;
		overflow: hidden;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--keel-color-primary);
	}

	.msg-avatar--gap {
		background-color: transparent;
	}

	.msg-avatar-img {
		width: 38px;
		height: 38px;
	}

	.msg-avatar-text {
		font-size: 16px;
		color: #fff;
	}

	.msg-body {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		max-width: 72%;
		margin: 0 10px;
	}

	.msg--mine .msg-body {
		align-items: flex-end;
	}

	.msg-name {
		margin-bottom: 4px;
		font-size: 12px;
		color: var(--keel-text-color-secondary);
	}

	.bubble {
		/*
		 * ⚠️ 禁用浏览器/WebView 的原生长按行为
		 *
		 * 不加的话 H5 与 App 的 WebView 会在长按时弹自己的「复制 / 搜索」菜单，
		 * 把 uni.showActionSheet 盖掉或抢先触发。代价是气泡里的文字不能再手选——
		 * 所以菜单里必须提供「复制」
		 */
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
		padding: 9px 13px;
		background-color: var(--keel-bg-color);
		border-radius: 14px;
		border: 1px solid transparent;
	}

	/* 自己的气泡浅蓝底深字，与电脑端一致。满版品牌蓝配白字对比度只有 2.78:1 */
	.bubble--mine {
		background-color: var(--keel-color-primary-light-9);
	}

	.bubble--failed {
		border-color: var(--keel-color-danger);
	}

	.bubble-text {
		font-size: 16px;
		line-height: 1.5;
		color: var(--keel-text-color-primary);
		word-break: break-all;
	}

	.msg-foot {
		display: flex;
		flex-direction: row;
		align-items: center;
		margin-top: 4px;
	}

	.msg-state {
		font-size: 11px;
		color: var(--keel-text-color-placeholder);
	}

	.msg-state--failed {
		color: var(--keel-color-danger);
	}

	.msg-resend {
		margin-left: 8px;
		font-size: 11px;
		color: var(--keel-color-primary);
	}

	/* 撤回与系统消息：整行居中灰字，不留气泡 */
	.recalled {
		display: block;
		margin-bottom: 14px;
		text-align: center;
		font-size: 12px;
		color: var(--keel-text-color-placeholder);
	}

	/* widthFix 会按原图比例算高度，所以只给宽度上限；给死高度会把图拉变形 */
	.msg-image {
		-webkit-touch-callout: none;
		width: 180px;
		border-radius: 12px;
		background-color: var(--keel-bg-color);
	}

	.msg-file {
		-webkit-touch-callout: none;
		-webkit-user-select: none;
		user-select: none;
		padding: 10px 13px;
		background-color: var(--keel-bg-color);
		border-radius: 12px;
	}

	.msg-file-name {
		display: block;
		font-size: 15px;
		color: var(--keel-text-color-primary);
	}

	.msg-file-size {
		display: block;
		margin-top: 2px;
		font-size: 12px;
		color: var(--keel-text-color-secondary);
	}

	.hint {
		display: block;
		padding: 60px 20px;
		text-align: center;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	/* 遮罩铺满可视区，点空白处收起 */
	.atmask {
		position: fixed;
		left: 0;
		right: 0;
		top: var(--window-top);
		bottom: 0;
		background-color: rgba(0, 0, 0, 0.25);
		z-index: 10;
		display: flex;
		align-items: flex-end;
	}

	.atpanel {
		width: 100%;
		max-height: 50vh;
		padding-bottom: env(safe-area-inset-bottom);
		background-color: var(--keel-bg-color);
		border-radius: 14px 14px 0 0;
	}

	.atlist {
		max-height: 40vh;
	}

	.atrow {
		padding: 14px 20px;
		border-bottom: 1px solid var(--keel-border-color-lighter);
	}

	.atrow--hover {
		background-color: var(--keel-pressed-bg);
	}

	.atrow-name {
		font-size: 16px;
		color: var(--keel-text-color-primary);
	}

	.atrow-all {
		font-size: 16px;
		color: var(--keel-color-primary);
	}

	.atempty {
		display: block;
		padding: 20px;
		text-align: center;
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.readonly {
		padding: 14px 16px;
		padding-bottom: calc(14px + constant(safe-area-inset-bottom));
		padding-bottom: calc(14px + env(safe-area-inset-bottom));
		background-color: var(--keel-bg-color);
		border-top: 1px solid var(--keel-border-color-lighter);
		text-align: center;
	}

	.readonly-text {
		font-size: 14px;
		color: var(--keel-text-color-secondary);
	}

	.dock {
		padding-bottom: constant(safe-area-inset-bottom);
		padding-bottom: env(safe-area-inset-bottom);
		background-color: var(--keel-bg-color);
		border-top: 1px solid var(--keel-border-color-lighter);
	}

	.composer {
		display: flex;
		flex-direction: row;
		align-items: center;
		padding: 8px 12px;
	}

	.composer-input {
		flex: 1;
		height: 38px;
		padding: 0 14px;
		font-size: 16px;
		color: var(--keel-text-color-primary);
		background-color: var(--keel-bg-color-page);
		border-radius: 19px;
	}

	/* 圆形工具按钮，与输入框同高 */
	.composer-tool {
		width: 34px;
		height: 34px;
		margin-left: 8px;
		display: flex;
		align-items: center;
		justify-content: center;
		border: 1px solid var(--keel-text-color-secondary);
		border-radius: 17px;
		flex-shrink: 0;
		box-sizing: border-box;
	}

	.composer-tool--hover {
		background-color: var(--keel-pressed-bg);
	}

	.composer-tool-text {
		font-size: 18px;
		line-height: 1;
		color: var(--keel-text-color-secondary);
	}

	.composer-tool-text--plus {
		font-size: 22px;
	}

	/* 胶囊按钮 + 按下缩放，与登录页一致 */
	.composer-btn {
		margin-left: 8px;
		padding: 0 16px;
		height: 34px;
		display: flex;
		align-items: center;
		background-color: var(--keel-color-primary);
		border-radius: 17px;
		flex-shrink: 0;
	}

	.composer-btn--hover {
		transform: scale(0.96);
	}

	.composer-btn-text {
		font-size: 15px;
		color: #fff;
	}

	.emoji {
		height: 220px;
		border-top: 1px solid var(--keel-border-color-lighter);
	}

	.emoji-grid {
		display: flex;
		flex-direction: row;
		flex-wrap: wrap;
		padding: 8px 6px;
	}

	/* 8 列：375 宽的屏上每格约 45px，手指点得准 */
	.emoji-item {
		width: 12.5%;
		height: 44px;
		line-height: 44px;
		text-align: center;
		font-size: 26px;
	}

	.more-panel {
		display: flex;
		flex-direction: row;
		padding: 16px 12px 20px;
		border-top: 1px solid var(--keel-border-color-lighter);
	}

	.more-item {
		width: 25%;
		display: flex;
		flex-direction: column;
		align-items: center;
	}

	.more-item--hover {
		opacity: 0.6;
	}

	.more-icon {
		width: 56px;
		height: 56px;
		border-radius: 14px;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--keel-bg-color-page);
	}

	.more-icon-text {
		font-size: 22px;
		color: var(--keel-text-color-regular);
	}

	.more-label {
		margin-top: 6px;
		font-size: 12px;
		color: var(--keel-text-color-secondary);
	}
</style>
