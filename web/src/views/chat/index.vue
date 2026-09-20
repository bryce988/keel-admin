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
import { ElMessage, ElMessageBox } from 'element-plus'
import { Bell, Delete, Promotion, Top } from '@element-plus/icons-vue'
import EmptyState from '@/components/EmptyState.vue'
import { chatSocket } from '@/utils/chatSocket'
import { useUserStore } from '@/stores/user'
import { useChatStore } from '@/stores/chat'
import { getContactDepts, getContacts as getContactPage, type ContactDept, type ContactPerson } from '@/api/contact'
import {
  getConversations,
  getMessages,
  markRead,
  openConversation,
  removeConversation,
  sendMessage,
  updateSettings,
  type ChatConversation,
  type ChatConversationRow,
  type ChatMessage,
} from '@/api/chat'

/** 本地乐观上屏的消息多带两个字段，服务端不认识它们 */
interface LocalMessage extends ChatMessage {
  _state?: 'sending' | 'failed'
}

const userStore = useUserStore()
const chatStore = useChatStore()
const myId = computed(() => Number(userStore.profile?.user.id ?? 0))

/** 左栏：会话列表 */
const conversations = ref<ChatConversationRow[]>([])
const loadingList = ref(false)

/**
 * 左栏页签
 *
 * 通讯录与会话列表**并列在同一个界面里**，不是独立菜单——
 * 想给同事发条消息不该先去别的页面找人再跳回来，找人和聊天是连着的。
 * 这也是钉钉桌面端的做法。
 */
const sideTab = ref<'chat' | 'contact'>('chat')

/** 通讯录：组织架构树 + 搜索 */
const depts = ref<ContactDept[]>([])
const contacts = ref<ContactPerson[]>([])
const keyword = ref('')
const loadingContacts = ref(false)
const activeDeptId = ref(0)

const conversation = ref<ChatConversation | null>(null)
const messages = ref<LocalMessage[]>([])
const loadingMessages = ref(false)
const draft = ref('')
const sending = ref(false)
const connected = ref(false)

const listRef = ref<HTMLElement>()

// ---------------------------------------------------------------- 通讯录

/**
 * 通讯录取数
 *
 * 有关键词时**跨部门搜全公司**，忽略当前选中的部门——用户打字就是因为
 * 不知道人在哪个部门，这时候还按部门过滤等于帮倒忙。
 */
async function loadContacts() {
  loadingContacts.value = true
  try {
    const kw = keyword.value.trim()
    const res = await getContactPage({
      keyword: kw,
      dept_id: kw ? 0 : activeDeptId.value,
      // 选中某个部门时含子部门：点「技术部」要看到底下所有小组的人
      include_children: true,
      page_size: 100,
    })
    contacts.value = res.list
  } finally {
    loadingContacts.value = false
  }
}

/** 部门树只在第一次切到通讯录时拉，之后不会变 */
async function ensureDepts() {
  if (depts.value.length) return
  depts.value = await getContactDepts()
}

async function switchTab(tab: 'chat' | 'contact') {
  sideTab.value = tab
  if (tab !== 'contact') return

  await ensureDepts()
  if (!contacts.value.length) await loadContacts()
}

function pickDept(node: ContactDept) {
  // 再点一次选中的部门 = 取消筛选，回到全公司
  activeDeptId.value = activeDeptId.value === node.id ? 0 : node.id
  keyword.value = ''
  void loadContacts()
}

let searchTimer: number | undefined
watch(keyword, () => {
  clearTimeout(searchTimer)
  searchTimer = window.setTimeout(loadContacts, 300)
})

// ---------------------------------------------------------------- 会话列表

async function loadList() {
  loadingList.value = true
  try {
    conversations.value = await getConversations()
  } finally {
    loadingList.value = false
  }
}

/** 本地把某个会话的未读清零，不等服务端往返——点进去红点要立刻消失 */
function clearUnreadLocally(convId: number) {
  const row = conversations.value.find((c) => c.id === convId)
  if (row) {
    row.unread = 0
    row.has_at = false
  }
}

async function selectConversation(row: ChatConversationRow) {
  if (conversation.value?.id === row.id) return

  conversation.value = row
  chatStore.enter(row.id)
  clearUnreadLocally(row.id)
  await loadHistory()
}

async function togglePin(row: ChatConversationRow) {
  const res = await updateSettings(row.id, { is_pinned: !row.is_pinned })
  row.is_pinned = res.is_pinned
  // 重排而不是整表重拉：置顶只影响顺序，没必要为此打一次网络
  conversations.value.sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned))
}

async function toggleMute(row: ChatConversationRow) {
  const res = await updateSettings(row.id, { is_muted: !row.is_muted })
  row.is_muted = res.is_muted
  // 免打扰会影响全局数字（免打扰的不计入），所以这里要让 store 重新对一次
  void chatStore.refresh()
}

async function removeRow(row: ChatConversationRow) {
  await ElMessageBox.confirm(
    '只会从你的列表移除，不会删除消息，对方也不受影响。若对方再发消息，会话会重新出现。',
    `删除与「${row.name}」的会话`,
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )

  await removeConversation(row.id)
  conversations.value = conversations.value.filter((c) => c.id !== row.id)

  if (conversation.value?.id === row.id) {
    conversation.value = null
    messages.value = []
    chatStore.leave()
  }

  void chatStore.refresh()
}

/** 相对时间。今天给时刻，昨天给「昨天」，更早给日期——列表里精确到秒没有意义 */
function listTime(at: string | null): string {
  if (!at) return ''

  const d = at.slice(0, 10)
  const today = new Date()
  const ymd = (x: Date) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`

  if (d === ymd(today)) return at.slice(11, 16)

  const yesterday = new Date(today.getTime() - 86_400_000)
  if (d === ymd(yesterday)) return '昨天'

  return at.slice(5, 10)
}

// ---------------------------------------------------------------- 会话

async function openWith(contact: ContactPerson) {
  // 选完人自动切回消息：用户的意图是「跟这个人说话」，不是「继续浏览通讯录」
  sideTab.value = 'chat'

  const conv = await openConversation(contact.id)

  // 服务端可能返回一个已存在的会话，列表里未必有（之前删过）。重拉一次最稳，
  // 顺带拿到它的未读与置顶状态
  await loadList()

  const row = conversations.value.find((c) => c.id === conv.id)
  if (row) {
    await selectConversation(row)
  } else {
    conversation.value = conv
    chatStore.enter(conv.id)
    await loadHistory()
  }
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

  // 不是当前会话的消息：只更新左栏（摘要、时间、未读、排序），不碰消息区
  if (!conv || msg.conv_id !== conv.id) {
    const row = conversations.value.find((c) => c.id === msg.conv_id)
    if (row) {
      row.last_msg_text = msg.type === 'text' ? msg.content.slice(0, 40) : `[${msg.type}]`
      row.last_msg_at = msg.created_at
      row.max_seq = msg.seq
      if (msg.sender_id !== myId.value) row.unread += 1
      // 新消息把会话顶上去，但置顶的仍然在最前
      conversations.value.sort((a, b) => {
        if (a.is_pinned !== b.is_pinned) return Number(b.is_pinned) - Number(a.is_pinned)
        return (b.last_msg_at ?? '').localeCompare(a.last_msg_at ?? '')
      })
    } else {
      // 列表里没有这个会话：对方刚发起，或我之前删过它。重拉一次
      await loadList()
    }
    return
  }

  const real = messages.value.filter((m) => m.seq !== Number.MAX_SAFE_INTEGER)
  const localMax = real.length ? real[real.length - 1].seq : 0

  if (msg.seq > localMax + 1 && localMax > 0) {
    const missing = await getMessages(conv.id, { after_seq: localMax, limit: 100 })
    missing.forEach(upsert)
  } else {
    upsert(msg)
  }

  // 当前会话的摘要也要更新，否则切走再回来左栏还是旧的
  const row = conversations.value.find((c) => c.id === msg.conv_id)
  if (row) {
    row.last_msg_text = msg.type === 'text' ? msg.content.slice(0, 40) : `[${msg.type}]`
    row.last_msg_at = msg.created_at
    row.max_seq = msg.seq
    row.unread = 0
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
  chatStore.bind()
  await loadList()
  void chatStore.refresh()

  /*
   * 重连后的对齐：ready 是「握手完成」的信号，每次重连都会再来一次。
   * 收到就重拉当前会话——断线期间到达的消息靠这一步补齐，
   * 而不是指望推送把断线那段时间的消息重发一遍（Redis pub/sub 不持久化）。
   */
  offReady = chatSocket.on('ready', () => {
    connected.value = true
    // 重连后左栏和消息区都要对齐：断线期间的消息推送不会重发
    void loadList()
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
  chatStore.leave()
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
    <!-- 左栏：会话列表 -->
    <aside class="chat__side">
      <!-- 页签：通讯录与会话列表并列，切换而不是跳页面 -->
      <div class="chat__tabs">
        <button
          type="button"
          class="chat__tab"
          :class="{ 'chat__tab--on': sideTab === 'chat' }"
          @click="switchTab('chat')"
        >
          消息
          <span v-if="chatStore.total > 0" class="chat__tab-dot" />
        </button>
        <button
          type="button"
          class="chat__tab"
          :class="{ 'chat__tab--on': sideTab === 'contact' }"
          @click="switchTab('contact')"
        >
          通讯录
        </button>
      </div>

      <div v-show="sideTab === 'chat'" v-loading="loadingList" class="chat__list">
        <div
          v-for="row in conversations"
          :key="row.id"
          class="conv"
          :class="{ 'conv--active': conversation?.id === row.id, 'conv--pinned': row.is_pinned }"
          @click="selectConversation(row)"
        >
          <el-avatar :size="38" :src="row.avatar || undefined">{{ row.name.slice(0, 1) }}</el-avatar>

          <div class="conv__body">
            <div class="conv__line">
              <span class="conv__name">{{ row.name }}</span>
              <span class="conv__time">{{ listTime(row.last_msg_at) }}</span>
            </div>
            <div class="conv__line">
              <span class="conv__brief">
                <span v-if="row.has_at" class="conv__at">[有人@我]</span>
                {{ row.last_msg_text || '暂无消息' }}
              </span>

              <!--
                免打扰只显示小圆点不显示数字：用户设它就是为了红点别跳。
                数字仍然是「未读几条」，圆点只回答「有没有未读」
              -->
              <span v-if="row.unread > 0" class="conv__badge" :class="{ 'conv__badge--dot': row.is_muted }">
                {{ row.is_muted ? '' : (row.unread > 99 ? '99+' : row.unread) }}
              </span>
            </div>
          </div>

          <!-- 操作按钮：hover 才出现，常驻会把本来就窄的列表挤满 -->
          <div class="conv__ops" @click.stop>
            <el-tooltip :content="row.is_pinned ? '取消置顶' : '置顶'" placement="top">
              <el-button text size="small" :icon="Top" :type="row.is_pinned ? 'primary' : ''" @click="togglePin(row)" />
            </el-tooltip>
            <el-tooltip :content="row.is_muted ? '取消免打扰' : '免打扰'" placement="top">
              <el-button text size="small" :icon="Bell" :type="row.is_muted ? 'warning' : ''" @click="toggleMute(row)" />
            </el-tooltip>
            <el-tooltip content="删除会话" placement="top">
              <el-button text size="small" :icon="Delete" @click="removeRow(row)" />
            </el-tooltip>
          </div>
        </div>

        <EmptyState
          v-if="!loadingList && !conversations.length"
          scene="empty"
          description="还没有会话，去通讯录找个同事聊聊"
          :action="false"
          :size="70"
        />
      </div>

      <!-- 通讯录：部门树 + 人员。点人直接开会话并切回消息 -->
      <div v-show="sideTab === 'contact'" class="chat__contact">
        <div class="chat__search">
          <el-input v-model="keyword" placeholder="搜索同事" clearable size="small" />
        </div>

        <!-- 搜索时跨部门搜全公司，部门筛选就不该再干扰，所以藏起来 -->
        <div v-if="!keyword.trim() && depts.length" class="dept">
          <button
            type="button"
            class="dept__item"
            :class="{ 'dept__item--on': activeDeptId === 0 }"
            @click="pickDept({ id: 0, name: '', children: [] })"
          >
            全部
          </button>
          <template v-for="d in depts" :key="d.id">
            <button
              type="button"
              class="dept__item"
              :class="{ 'dept__item--on': activeDeptId === d.id }"
              @click="pickDept(d)"
            >
              {{ d.name }}
            </button>
            <button
              v-for="c in d.children"
              :key="c.id"
              type="button"
              class="dept__item dept__item--sub"
              :class="{ 'dept__item--on': activeDeptId === c.id }"
              @click="pickDept(c)"
            >
              {{ c.name }}
            </button>
          </template>
        </div>

        <div v-loading="loadingContacts" class="chat__people">
          <button v-for="c in contacts" :key="c.id" class="contact" type="button" @click="openWith(c)">
            <el-avatar :size="36" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
            <div class="contact__body">
              <div class="contact__name">{{ c.real_name }}</div>
              <!-- 手机号无权限时是掩码，直接显示即可，不用判断 -->
              <div class="contact__sub">{{ c.post_name || c.dept_name }}{{ c.phone ? ' · ' + c.phone : '' }}</div>
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

/* 页签：两个等宽按钮 + 底部指示条，比 el-tabs 轻，也不会带进它的默认边距 */
.chat__tabs {
  display: flex;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.chat__tab {
  position: relative;
  flex: 1;
  padding: 11px 0;
  border: 0;
  background: transparent;
  font-size: 14px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.chat__tab:hover {
  color: var(--el-text-color-primary);
}

.chat__tab--on {
  color: var(--el-color-primary);
  font-weight: 600;
}

.chat__tab--on::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: -1px;
  width: 28px;
  height: 2px;
  margin-left: -14px;
  border-radius: 1px;
  background: var(--el-color-primary);
}

/* 页签上的小红点：切到通讯录时仍然看得见「有未读」，不用切回去确认 */
.chat__tab-dot {
  position: absolute;
  top: 8px;
  margin-left: 2px;
  width: 6px;
  height: 6px;
  border-radius: 3px;
  background: var(--el-color-danger);
}

.chat__contact {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.chat__search {
  padding: 10px 12px 8px;
}

/* 部门筛选：两级平铺成一列，子部门缩进。
   280px 的栏里放 el-tree 的展开箭头太挤，而部门通常只有两级 */
.dept {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 0 12px 8px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.dept__item {
  padding: 3px 9px;
  border: 0;
  border-radius: 10px;
  background: var(--el-fill-color-light);
  font-size: 12px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.dept__item:hover {
  background: var(--el-fill-color);
}

.dept__item--sub::before {
  content: '·';
  margin-right: 3px;
  color: var(--el-text-color-placeholder);
}

.dept__item--on {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.chat__people {
  flex: 1;
  overflow-y: auto;
  padding: 6px;
}

.chat__list {
  flex: 1;
  overflow-y: auto;
  padding: 6px;
}

.conv {
  position: relative;
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 9px 10px;
  border-radius: 6px;
  cursor: pointer;
}

.conv:hover {
  background: var(--el-fill-color-light);
}

.conv--active {
  background: var(--el-color-primary-light-9);
}

/* 置顶用一条左边框标记，不占额外行高——列表里每一行都很金贵 */
.conv--pinned::before {
  content: '';
  position: absolute;
  left: 0;
  top: 8px;
  bottom: 8px;
  width: 2px;
  border-radius: 1px;
  background: var(--el-color-primary);
}

.conv__body {
  flex: 1;
  min-width: 0;
}

.conv__line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.conv__line + .conv__line {
  margin-top: 3px;
}

.conv__name {
  font-size: 14px;
  color: var(--el-text-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.conv__time {
  flex: 0 0 auto;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.conv__brief {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.conv__at {
  color: var(--el-color-danger);
}

.conv__badge {
  flex: 0 0 auto;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: var(--el-color-danger);
  color: #fff;
  font-size: 12px;
  line-height: 18px;
  text-align: center;
}

/* 免打扰：只留一个小圆点，不显示数字 */
.conv__badge--dot {
  min-width: 8px;
  width: 8px;
  height: 8px;
  padding: 0;
  border-radius: 4px;
  background: var(--el-text-color-placeholder);
}

/* 操作按钮 hover 才出现：常驻会把本来就窄的列表挤满 */
.conv__ops {
  display: none;
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  padding: 2px 4px;
  border-radius: 6px;
  background: var(--el-bg-color);
  box-shadow: var(--el-box-shadow-lighter);
}

.conv:hover .conv__ops {
  display: flex;
}

.picker {
  max-height: 320px;
  margin-top: 10px;
  overflow-y: auto;
}

/*
 * 通讯录人员行
 *
 * ⚠️ 这几条是补回来的：第 ③ 批把左栏从通讯录换成会话列表时，
 * 连带删掉了 .contact*，而弹窗里的选人列表还在用它——
 * 表现是头像和文字竖排、条目横向换行。删样式时要连它的使用点一起搜一遍。
 */
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

.contact__body {
  min-width: 0;
  flex: 1;
}

.contact__name {
  font-size: 14px;
  color: var(--el-text-color-primary);
}

.contact__sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
