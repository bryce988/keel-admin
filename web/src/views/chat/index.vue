<script setup lang="ts">
/**
 * 聊天（第 ② 批：单聊最小可用版）
 *
 * 这一版**故意不做会话列表**：左栏是通讯录，点人直接进单聊。
 * 会话列表、未读红点、置顶免打扰在第 ③ 批（docs/chat-tech.md §10）。
 *
 * 三件与全站其他页面不同的事，都是有意的：
 *
 * - **不用 `ProTable`**：它是分页表格的抽象，聊天要的是倒序无限滚动 + 粘底，
 *   两者的滚动语义完全相反
 * - **消息顺序以服务端 seq 为准**：本地乐观上屏的消息拿到响应后按 seq 归位，
 *   不是简单替换——期间可能已经收到别人的消息，直接替换会让顺序错乱
 * - **发消息走 HTTP**，长连接只负责收
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Promotion } from '@element-plus/icons-vue'
import EmptyState from '@/components/EmptyState.vue'
import { chatSocket } from '@/utils/chatSocket'
import { useUserStore } from '@/stores/user'
import {
  getContacts,
  getMessages,
  markRead,
  openConversation,
  sendMessage,
  type ChatContact,
  type ChatConversation,
  type ChatMessage,
} from '@/api/chat'

/** 本地乐观上屏的消息多带两个字段，服务端不认识它们 */
interface LocalMessage extends ChatMessage {
  _state?: 'sending' | 'failed'
}

const userStore = useUserStore()
const myId = computed(() => Number(userStore.profile?.user.id ?? 0))

const contacts = ref<ChatContact[]>([])
const keyword = ref('')
const loadingContacts = ref(false)

const conversation = ref<ChatConversation | null>(null)
const messages = ref<LocalMessage[]>([])
const loadingMessages = ref(false)
const draft = ref('')
const sending = ref(false)
const connected = ref(false)

const listRef = ref<HTMLElement>()

// ---------------------------------------------------------------- 通讯录

async function loadContacts() {
  loadingContacts.value = true
  try {
    contacts.value = await getContacts(keyword.value.trim())
  } finally {
    loadingContacts.value = false
  }
}

let searchTimer: number | undefined
watch(keyword, () => {
  clearTimeout(searchTimer)
  searchTimer = window.setTimeout(loadContacts, 300)
})

// ---------------------------------------------------------------- 会话

async function openWith(contact: ChatContact) {
  if (conversation.value?.peer_id === contact.id) return

  conversation.value = await openConversation(contact.id)
  await loadHistory()
}

async function loadHistory() {
  if (!conversation.value) return

  loadingMessages.value = true
  try {
    messages.value = await getMessages(conversation.value.id, { limit: 30 })
    await scrollToBottom()
    await flushRead()
  } finally {
    loadingMessages.value = false
  }
}

/**
 * 把已读水位推到服务端
 *
 * 只在窗口可见时推。切到别的标签页时收到的消息**不算已读**——
 * 用户没看见，红点不该消失。
 */
async function flushRead() {
  const conv = conversation.value
  const last = messages.value[messages.value.length - 1]
  if (!conv || !last || document.hidden) return

  try {
    await markRead(conv.id, last.seq)
  } catch {
    // 已读失败不打扰用户：下次打开会话或收到新消息时会再推一次
  }
}

// ---------------------------------------------------------------- 收发

async function send() {
  const text = draft.value.trim()
  const conv = conversation.value
  if (!text || !conv || sending.value) return

  const clientMsgId = crypto.randomUUID()

  // 乐观上屏：先让消息出现，再等服务端确认。seq 暂时用一个比任何真实 seq
  // 都大的数，保证它排在末尾；拿到响应后会被换成真实 seq
  const optimistic: LocalMessage = {
    id: 0,
    conv_id: conv.id,
    seq: Number.MAX_SAFE_INTEGER,
    sender_id: myId.value,
    sender_name: '我',
    type: 'text',
    content: text,
    extra: null,
    client_msg_id: clientMsgId,
    status: 1,
    recalled_by: 0,
    created_at: '',
    _state: 'sending',
  }

  messages.value.push(optimistic)
  draft.value = ''
  await scrollToBottom()

  sending.value = true
  try {
    const saved = await sendMessage(conv.id, { client_msg_id: clientMsgId, type: 'text', content: text })
    replaceOptimistic(clientMsgId, saved)
  } catch (e) {
    const idx = messages.value.findIndex((m) => m.client_msg_id === clientMsgId)
    if (idx >= 0) messages.value[idx]._state = 'failed'
    ElMessage.error(e instanceof Error ? e.message : '发送失败')
  } finally {
    sending.value = false
  }
}

/**
 * 乐观消息归位
 *
 * 不是原地替换：等待响应的这段时间里可能已经收到了别人的消息，
 * 直接替换会让本地顺序与服务端 seq 对不上。做法是删掉占位、按 seq 插入。
 */
function replaceOptimistic(clientMsgId: string, saved: ChatMessage) {
  messages.value = messages.value.filter((m) => m.client_msg_id !== clientMsgId)
  upsert(saved)
}

/** 按 seq 插入，重复的丢弃。推送与 HTTP 响应可能带来同一条消息，这里是唯一的去重点 */
function upsert(msg: ChatMessage) {
  if (messages.value.some((m) => m.seq === msg.seq && m.id === msg.id)) return

  const idx = messages.value.findIndex((m) => m.seq > msg.seq)
  if (idx === -1) messages.value.push(msg)
  else messages.value.splice(idx, 0, msg)
}

/**
 * 收到推送
 *
 * ⚠️ 要检测**空洞**：推送是不可靠的（Redis pub/sub 不持久化，网关重启期间会丢）。
 * 发现 seq 不连续就用 HTTP 把中间的补回来——可靠性在数据库不在长连接。
 */
async function onPush(msg: ChatMessage) {
  const conv = conversation.value
  if (!conv || msg.conv_id !== conv.id) return

  const real = messages.value.filter((m) => m.seq !== Number.MAX_SAFE_INTEGER)
  const localMax = real.length ? real[real.length - 1].seq : 0

  if (msg.seq > localMax + 1 && localMax > 0) {
    const missing = await getMessages(conv.id, { after_seq: localMax, limit: 100 })
    missing.forEach(upsert)
  } else {
    upsert(msg)
  }

  await scrollToBottom()
  await flushRead()
}

async function scrollToBottom() {
  await nextTick()
  const el = listRef.value
  if (el) el.scrollTop = el.scrollHeight
}

// ---------------------------------------------------------------- 生命周期

let offReady: (() => void) | undefined
let offMessage: (() => void) | undefined

onMounted(async () => {
  await loadContacts()

  /*
   * 重连后的对齐：ready 是「握手完成」的信号，每次重连都会再来一次。
   * 收到就重拉当前会话——断线期间到达的消息靠这一步补齐，
   * 而不是指望推送把断线那段时间的消息重发一遍（Redis pub/sub 不持久化）。
   */
  offReady = chatSocket.on('ready', () => {
    connected.value = true
    if (conversation.value) void loadHistory()
  })
  offMessage = chatSocket.on('message.new', (data) => onPush(data as ChatMessage))

  chatSocket.connect()

  document.addEventListener('visibilitychange', onVisible)
})

function onVisible() {
  if (!document.hidden) {
    void flushRead()
    if (!chatSocket.connected) chatSocket.connect()
  }
}

onBeforeUnmount(() => {
  offReady?.()
  offMessage?.()
  document.removeEventListener('visibilitychange', onVisible)
  // 不 close()：socket 是全局单例，别的地方（顶栏红点）还要用它
})

function isMine(m: LocalMessage) {
  return m.sender_id === myId.value
}

function timeOf(m: LocalMessage) {
  return m.created_at ? m.created_at.slice(11, 16) : ''
}
</script>

<template>
  <div class="chat">
    <!-- 左栏：这一版是通讯录，第 ③ 批换成会话列表 -->
    <aside class="chat__side">
      <div class="chat__search">
        <el-input v-model="keyword" placeholder="搜索同事" clearable size="default" />
      </div>

      <div v-loading="loadingContacts" class="chat__contacts">
        <button
          v-for="c in contacts"
          :key="c.id"
          class="contact"
          :class="{ 'contact--active': conversation?.peer_id === c.id }"
          type="button"
          @click="openWith(c)"
        >
          <el-avatar :size="36" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
          <div class="contact__body">
            <div class="contact__name">{{ c.real_name }}</div>
            <div class="contact__sub">{{ c.username }}</div>
          </div>
        </button>

        <EmptyState
          v-if="!loadingContacts && !contacts.length"
          scene="search"
          :keyword="keyword"
          :action="false"
          :size="60"
        />
      </div>
    </aside>

    <!-- 右栏：消息区 -->
    <section class="chat__main">
      <template v-if="conversation">
        <header class="chat__header">
          <span class="chat__title">{{ conversation.name }}</span>
          <el-tag :type="connected ? 'success' : 'info'" size="small" effect="plain">
            {{ connected ? '已连接' : '连接中…' }}
          </el-tag>
        </header>

        <div ref="listRef" v-loading="loadingMessages" class="chat__messages">
          <div v-for="m in messages" :key="m.client_msg_id || m.id" class="msg" :class="{ 'msg--mine': isMine(m) }">
            <el-avatar :size="32" class="msg__avatar">{{ m.sender_name.slice(0, 1) }}</el-avatar>
            <div class="msg__body">
              <div class="msg__meta">
                <span>{{ isMine(m) ? '我' : m.sender_name }}</span>
                <span class="msg__time">{{ timeOf(m) }}</span>
              </div>
              <div class="msg__bubble" :class="{ 'msg__bubble--failed': m._state === 'failed' }">
                {{ m.content }}
                <span v-if="m._state === 'sending'" class="msg__state">发送中…</span>
                <span v-else-if="m._state === 'failed'" class="msg__state msg__state--failed">发送失败</span>
              </div>
            </div>
          </div>

          <EmptyState
            v-if="!loadingMessages && !messages.length"
            scene="empty"
            description="还没有消息，说点什么吧"
            :action="false"
          />
        </div>

        <footer class="chat__composer">
          <el-input
            v-model="draft"
            type="textarea"
            :rows="3"
            resize="none"
            placeholder="输入消息，Enter 发送，Shift+Enter 换行"
            @keydown.enter.exact.prevent="send"
          />
          <el-button type="primary" :icon="Promotion" :loading="sending" @click="send">发送</el-button>
        </footer>
      </template>

      <EmptyState v-else scene="empty" description="选择左侧任意同事，开始对话" :action="false" />
    </section>
  </div>
</template>

<style scoped>
.chat {
  display: flex;
  height: calc(100vh - 140px);
  min-height: 420px;
  overflow: hidden;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
}

.chat__side {
  display: flex;
  flex-direction: column;
  width: 280px;
  flex: 0 0 280px;
  border-right: 1px solid var(--el-border-color-lighter);
}

.chat__search {
  padding: 12px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.chat__contacts {
  flex: 1;
  overflow-y: auto;
  padding: 8px;
}

.contact {
  display: flex;
  gap: 10px;
  align-items: center;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
}

.contact:hover {
  background: var(--el-fill-color-light);
}

.contact--active {
  background: var(--el-color-primary-light-9);
}

.contact__body {
  min-width: 0;
}

.contact__name {
  font-size: 14px;
  color: var(--el-text-color-primary);
}

.contact__sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.chat__main {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.chat__header {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.chat__title {
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.chat__messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  background: var(--el-bg-color-page);
}

.msg {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}

/* 自己的消息靠右：把整行反向排即可，不用另写一套结构 */
.msg--mine {
  flex-direction: row-reverse;
}

.msg__avatar {
  flex: 0 0 auto;
}

.msg__body {
  max-width: 62%;
  min-width: 0;
}

.msg__meta {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.msg--mine .msg__meta {
  flex-direction: row-reverse;
}

.msg__bubble {
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  font-size: 14px;
  line-height: 1.6;
  color: var(--el-text-color-primary);
  word-break: break-word;
  white-space: pre-wrap;
}

.msg--mine .msg__bubble {
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
  color: #fff;
}

.msg__bubble--failed {
  border-color: var(--el-color-danger);
}

.msg__state {
  margin-left: 8px;
  font-size: 12px;
  opacity: 0.75;
}

.msg__state--failed {
  color: var(--el-color-danger);
}

.chat__composer {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  padding: 12px 16px;
  border-top: 1px solid var(--el-border-color-lighter);
}

.chat__composer :deep(.el-textarea) {
  flex: 1;
}
</style>
