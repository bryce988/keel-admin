<script setup lang="ts">
/**
 * 小k 会话面板（聊天页右栏，点左栏「小k」那一行时显示）
 *
 * 设计见 docs/ai-prd.md §5、docs/ai-tech.md §7、§10。
 *
 * ## 一次问答在界面上的样子
 *
 *   提问（立刻上屏）→ 排队中 → 思考中 → 查询了 N 项（边查边出现）→ 逐字输出 → 落库的回答替换掉流式气泡
 *
 * 流式那部分全靠 `ai.*` 帧，**丢了也无所谓**：回答结束时服务端一定会落一条 type=ai 的消息
 * 并推 message.new，界面最终以那条为准（与聊天「长连接不可靠、数据库可靠」同一个思路）。
 * 刷新页面时拿不到已输出的半截，只显示「小k 正在回答…」，等完成那条到达。
 *
 * ## 小k 没有自己的身份
 *
 * 这个组件不做任何「能不能查」的判断——没权限的查询，服务端会让小k 自己说出来。
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { CircleCheck, CircleClose, Loading, MagicStick, Refresh, VideoPause } from '@element-plus/icons-vue'
import { getMessages, markRead, type ChatMessage } from '@/api/chat'
import {
  askAi,
  cancelAiRun,
  feedbackAi,
  getAiConversation,
  resetAi,
  type AiConversation,
  type AiLink,
  type AiMessageExtra,
  type AiStep,
} from '@/api/ai'
import { chatSocket } from '@/utils/chatSocket'
import { renderAiMarkdown } from '@/utils/aiMarkdown'
import { formatMessageTime, parseChatTime } from '@/utils/chatTime'
import { copyText, uuid } from '@/utils/secureFallback'
import { useChatStore } from '@/stores/chat'
import { useUserStore } from '@/stores/user'

const PAGE_SIZE = 30
/** 与服务端 AiService::MAX_QUESTION 一致 */
const MAX_LEN = 2000
const DISCLOSURE_KEY = 'keel.ai.disclosure'

const router = useRouter()
const chatStore = useChatStore()
const userStore = useUserStore()
const myId = computed(() => Number(userStore.profile?.user.id ?? 0))
const myAvatar = computed(() => userStore.profile?.user.avatar || undefined)

const conv = ref<AiConversation | null>(null)
const messages = ref<ChatMessage[]>([])
const loading = ref(true)
const loadingOlder = ref(false)
const hasMore = ref(true)
const listRef = ref<HTMLElement>()
const draft = ref('')
const sending = ref(false)

// ---------------------------------------------------------------- 进行中的问答

/** 0 = 没有进行中的问答 */
const runId = ref(0)
/** queued 排队中 · thinking 思考中 · answering 输出中 */
const phase = ref<'queued' | 'thinking' | 'answering'>('queued')
const streamText = ref('')
const streamRound = ref(0)
const streamSteps = ref<AiStep[]>([])

function resetStream() {
  runId.value = 0
  phase.value = 'queued'
  streamText.value = ''
  streamRound.value = 0
  streamSteps.value = []
}

const phaseText = computed(() =>
  phase.value === 'queued' ? '排队中…' : phase.value === 'thinking' ? '小k 正在思考…' : '',
)

// ---------------------------------------------------------------- 首次使用的数据外发说明

function readAck(): boolean {
  try {
    return localStorage.getItem(DISCLOSURE_KEY) === '1'
  } catch {
    return false
  }
}
const disclosureAck = ref(readAck())
function ackDisclosure() {
  disclosureAck.value = true
  try {
    localStorage.setItem(DISCLOSURE_KEY, '1')
  } catch {
    // 存不住大不了下次再显示一次
  }
}

// ---------------------------------------------------------------- 加载

async function load() {
  loading.value = true
  try {
    conv.value = await getAiConversation()
    chatStore.enter(conv.value.id)
    if (chatStore.ai) {
      chatStore.ai.conv_id = conv.value.id
      chatStore.ai.unread = 0
    }

    const rows = await getMessages(conv.value.id, { limit: PAGE_SIZE })
    messages.value = rows
    hasMore.value = rows.length >= PAGE_SIZE

    // 刷新页面时问答还在进行：恢复成「正在回答」，等完成那条消息到达
    if (conv.value.active_run_id) {
      runId.value = conv.value.active_run_id
      phase.value = 'thinking'
    }

    await scrollToBottom()
    void flushRead()
  } finally {
    loading.value = false
  }
}

async function loadOlder() {
  if (!conv.value || loadingOlder.value || !hasMore.value || !messages.value.length) return
  const el = listRef.value
  const before = el ? el.scrollHeight - el.scrollTop : 0

  loadingOlder.value = true
  try {
    const rows = await getMessages(conv.value.id, { before_seq: messages.value[0].seq, limit: PAGE_SIZE })
    hasMore.value = rows.length >= PAGE_SIZE
    messages.value = [...rows, ...messages.value]
    // 视口停在原来那条上，而不是跳到最顶
    await nextTick()
    if (el) el.scrollTop = el.scrollHeight - before
  } finally {
    loadingOlder.value = false
  }
}

function onScroll() {
  if (listRef.value && listRef.value.scrollTop < 60) void loadOlder()
}

async function scrollToBottom() {
  await nextTick()
  const el = listRef.value
  if (el) el.scrollTop = el.scrollHeight
}

/** 离底部近才跟着滚：用户往上翻看历史时，别被流式输出一直拽回底部 */
function nearBottom(): boolean {
  const el = listRef.value
  return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120
}

/** 窗口在后台时不算已读（与聊天同一条规则） */
async function flushRead() {
  if (!conv.value || document.hidden || !messages.value.length) return
  const seq = messages.value[messages.value.length - 1].seq
  try {
    await markRead(conv.value.id, seq)
  } catch {
    // 下次再标
  }
}

function upsert(msg: ChatMessage) {
  const i = messages.value.findIndex((m) => m.id === msg.id)
  if (i >= 0) messages.value[i] = msg
  else {
    messages.value.push(msg)
    messages.value.sort((a, b) => a.seq - b.seq)
  }
}

// ---------------------------------------------------------------- 提问

const draftLen = computed(() => draft.value.length)
const canAsk = computed(() => !runId.value && !sending.value && !!draft.value.trim() && draftLen.value <= MAX_LEN)

async function ask(text?: string) {
  const content = (text ?? draft.value).trim()
  if (!content || runId.value || sending.value) return

  sending.value = true
  try {
    const res = await askAi(content, uuid())
    upsert(res.message)
    if (!text) draft.value = ''
    // 长连接可能比这个 HTTP 响应先到（同机房完全可能）：
    // - ai.run.started 先到：runId 已经是它了，别把状态退回排队
    // - 回答都已经落库送达了（问题很简单时）：不要再进入「思考中」，否则会一直转下去
    const answered = messages.value.some(
      (m) => m.type === 'ai' && (m.extra as AiMessageExtra | null)?.run_id === res.run_id,
    )
    if (!answered && runId.value !== res.run_id) {
      resetStream()
      runId.value = res.run_id
    }
    await scrollToBottom()
  } catch {
    // 拦截器已经弹了提示（409 还没答完、429 配额用完…），草稿留着
  } finally {
    sending.value = false
  }
}

function onKeydown(evt: Event | KeyboardEvent) {
  const e = evt as KeyboardEvent
  // 输入法组合中的回车是在选字，不是发送
  if (e.key !== 'Enter' || e.isComposing || e.shiftKey) return
  e.preventDefault()
  if (canAsk.value) void ask()
}

async function stop() {
  if (!runId.value) return
  try {
    await cancelAiRun(runId.value)
  } catch {
    // 已经结束了也无所谓，完成那条消息会到
  }
}

async function newTopic() {
  try {
    const msg = await resetAi()
    upsert(msg)
    await scrollToBottom()
  } catch {
    // 409：还在回答，拦截器已提示
  }
}

// ---------------------------------------------------------------- 长连接

function onStarted(d: { run_id: number }) {
  // 另一个标签页发起的问答也会推到这里：接上它，两边一起显示进度
  if (runId.value !== d.run_id) {
    resetStream()
    runId.value = d.run_id
  }
  phase.value = 'thinking'
}

function onThinking(d: { run_id: number }) {
  if (d.run_id !== runId.value) return
  if (phase.value !== 'answering' || !streamText.value) phase.value = 'thinking'
}

function onDelta(d: { run_id: number; round: number; text: string }) {
  if (d.run_id !== runId.value) return
  // 换轮了（中间查过数据）：上一轮的半截话不是最终回答，丢掉
  if (d.round !== streamRound.value) {
    streamRound.value = d.round
    streamText.value = ''
  }
  streamText.value += d.text
  phase.value = 'answering'
  if (nearBottom()) void scrollToBottom()
}

function onStep(d: AiStep & { run_id: number }) {
  if (d.run_id !== runId.value) return
  streamSteps.value.push({ label: d.label, rows: d.rows, denied: d.denied })
  if (nearBottom()) void scrollToBottom()
}

function onMessage(msg: ChatMessage) {
  if (!conv.value || msg.conv_id !== conv.value.id) return
  const follow = nearBottom()
  upsert(msg)

  // 落库的回答到了：流式气泡让位给它
  const extra = (msg.extra ?? {}) as AiMessageExtra
  if (msg.type === 'ai' && extra.run_id && extra.run_id === runId.value) resetStream()

  if (follow) void scrollToBottom()
  void flushRead()
}

function onVisible() {
  if (!document.hidden) void flushRead()
}

const offs: (() => void)[] = []

onMounted(() => {
  offs.push(
    chatSocket.on('ai.run.started', onStarted),
    chatSocket.on('ai.thinking', onThinking),
    chatSocket.on('ai.delta', onDelta),
    chatSocket.on('ai.step', onStep),
    chatSocket.on('message.new', (d) => onMessage(d as ChatMessage)),
    // 重连后补齐：断线期间落库的回答不会重推
    chatSocket.on('ready', () => void catchUp()),
  )
  document.addEventListener('visibilitychange', onVisible)
  void load()
})

onBeforeUnmount(() => {
  offs.forEach((off) => off())
  document.removeEventListener('visibilitychange', onVisible)
  chatStore.leave()
})

async function catchUp() {
  if (!conv.value || !messages.value.length) return
  const last = messages.value[messages.value.length - 1].seq
  const rows = await getMessages(conv.value.id, { after_seq: last, limit: 100 })
  rows.forEach(onMessage)
  // 断线期间问答已经结束的话，流式状态要收掉
  if (runId.value) {
    const done = messages.value.some((m) => m.type === 'ai' && (m.extra as AiMessageExtra | null)?.run_id === runId.value)
    if (done) resetStream()
  }
}

// ---------------------------------------------------------------- 渲染

const onlyWelcome = computed(() => !messages.value.some((m) => m.sender_id === myId.value))

function extraOf(m: ChatMessage): AiMessageExtra {
  return (m.extra ?? {}) as AiMessageExtra
}

function linksOf(m: ChatMessage): AiLink[] {
  return extraOf(m).links ?? []
}

function html(m: ChatMessage): string {
  return renderAiMarkdown(m.content, { linkLabels: linksOf(m).map((l) => l.label) })
}

/** 流式期间还拿不到链接表，[[link:N]] 先不显示 */
const streamHtml = computed(() => renderAiMarkdown(streamText.value, { linkLabels: [] }))

function isReset(m: ChatMessage) {
  return m.type === 'system' && (m.extra as { kind?: string } | null)?.kind === 'ai_reset'
}

function timeOf(m: ChatMessage) {
  const d = parseChatTime(m.created_at)
  return d ? formatMessageTime(d) : ''
}

/** 站内链接：事件委托，markdown 渲染出来的按钮上只带了下标 */
function onContentClick(e: MouseEvent, m: ChatMessage) {
  const btn = (e.target as HTMLElement).closest('.ai-link') as HTMLElement | null
  if (!btn) return
  const link = linksOf(m)[Number(btn.dataset.link)]
  if (link) void router.push({ path: link.path, query: link.query })
}

async function copy(m: ChatMessage) {
  // 非安全上下文（线上 http://IP）没有 navigator.clipboard，统一走 secureFallback
  const ok = await copyText(m.content.replace(/\[\[link:\d+\]\]/g, '').trim())
  if (ok) ElMessage.success('已复制')
  else ElMessage.warning('复制失败，请手动选择文字')
}

/** 本次打开期间的评价状态。接口不回传历史评价——评价是给调提示词用的，不是给用户看的 */
const ratings = ref<Record<number, number>>({})

async function rate(m: ChatMessage, value: 1 | -1) {
  const id = extraOf(m).run_id
  if (!id) return
  const next = ratings.value[id] === value ? 0 : value

  let reason = ''
  if (next === -1) {
    try {
      const r = await ElMessageBox.prompt('哪里不对？（选填）', '反馈', {
        inputPlaceholder: '比如：数字不对 / 没理解我的问题',
        confirmButtonText: '提交',
        cancelButtonText: '跳过',
        distinguishCancelAndClose: true,
      })
      reason = r.value ?? ''
    } catch (action) {
      if (action === 'close') return
    }
  }

  await feedbackAi(id, next, reason)
  ratings.value[id] = next
  if (next !== 0) ElMessage.success('谢谢反馈')
}
</script>

<template>
  <div class="ai">
    <header class="ai__header">
      <span class="ai__title">小k</span>
      <el-tag size="small" type="warning" effect="plain">AI 助手</el-tag>
      <div class="ai__header-ops">
        <el-tooltip content="开始新话题，之后的提问不再带上之前的上下文" placement="bottom" :show-after="300">
          <el-button text :icon="Refresh" :disabled="!!runId" @click="newTopic">新对话</el-button>
        </el-tooltip>
      </div>
    </header>

    <div ref="listRef" v-loading="loading" class="ai__messages" @scroll.passive="onScroll">
      <div v-if="loadingOlder" class="ai__more"><el-icon class="is-loading"><Loading /></el-icon> 加载中…</div>

      <el-alert v-if="!disclosureAck && !loading" type="info" :closable="false" class="ai__disclosure">
        <template #title>使用前请知悉</template>
        你的提问、最近的对话，以及小k 替你查到的数据（手机号、邮箱已打码）会发送给 AI 服务商 DeepSeek 处理。
        小k 只能查询你本来就有权限看到的数据，不能修改任何东西。
        <el-button size="small" type="primary" link @click="ackDisclosure">知道了</el-button>
      </el-alert>

      <template v-for="m in messages" :key="m.id">
        <div v-if="isReset(m)" class="ai__reset"><span>新对话</span></div>

        <div v-else-if="m.type === 'system'" class="ai__system">{{ m.content }}</div>

        <!-- 我的提问 -->
        <div v-else-if="m.sender_id === myId" class="amsg amsg--mine">
          <el-avatar :size="32" :src="myAvatar" class="amsg__avatar">{{ m.sender_name.slice(0, 1) }}</el-avatar>
          <div class="amsg__body">
            <div class="amsg__meta"><span>我</span><span>{{ timeOf(m) }}</span></div>
            <div class="amsg__bubble amsg__bubble--mine">{{ m.status === 2 ? '（已撤回）' : m.content }}</div>
          </div>
        </div>

        <!-- 小k 的回答 -->
        <div v-else class="amsg">
          <div class="amsg__bot"><el-icon><MagicStick /></el-icon></div>
          <div class="amsg__body amsg__body--wide">
            <div class="amsg__meta"><span>小k</span><span>{{ timeOf(m) }}</span></div>

            <!-- 查询了哪些数据：默认折叠，人话描述，不暴露工具名与原始参数 -->
            <details v-if="extraOf(m).steps?.length" class="steps">
              <summary>查询了 {{ extraOf(m).steps!.length }} 项</summary>
              <div v-for="(s, i) in extraOf(m).steps" :key="i" class="steps__item">
                <el-icon v-if="s.denied" class="steps__denied"><CircleClose /></el-icon>
                <el-icon v-else class="steps__ok"><CircleCheck /></el-icon>
                <span>{{ s.label }}</span>
                <span v-if="!s.denied" class="steps__rows">{{ s.rows }} 条</span>
              </div>
            </details>

            <div
              class="amsg__bubble amsg__md"
              :class="{ 'amsg__bubble--failed': extraOf(m).status === 'failed' }"
              @click="onContentClick($event, m)"
              v-html="html(m)"
            />
            <div v-if="extraOf(m).partial" class="amsg__partial">
              <div class="amsg__partial-label">中断前已输出：</div>
              <div class="amsg__md" v-html="renderAiMarkdown(extraOf(m).partial!, { linkLabels: [] })" />
            </div>

            <div v-if="extraOf(m).kind === 'welcome' && onlyWelcome && conv?.suggestions.length" class="suggest">
              <button v-for="q in conv.suggestions" :key="q" type="button" class="suggest__item" :disabled="!!runId" @click="ask(q)">
                {{ q }}
              </button>
            </div>

            <div v-if="extraOf(m).run_id" class="amsg__foot">
              <el-tag v-if="extraOf(m).status === 'stopped'" size="small" type="info">已停止</el-tag>
              <template v-if="extraOf(m).status === 'done'">
                <button type="button" class="amsg__op" :class="{ 'amsg__op--on': ratings[extraOf(m).run_id!] === 1 }" @click="rate(m, 1)">有用</button>
                <button type="button" class="amsg__op" :class="{ 'amsg__op--on': ratings[extraOf(m).run_id!] === -1 }" @click="rate(m, -1)">没用</button>
              </template>
              <button type="button" class="amsg__op" @click="copy(m)">复制</button>
            </div>
          </div>
        </div>
      </template>

      <!-- 进行中的问答：流式气泡。落库的回答到了就消失 -->
      <div v-if="runId" class="amsg">
        <div class="amsg__bot"><el-icon><MagicStick /></el-icon></div>
        <div class="amsg__body amsg__body--wide">
          <div class="amsg__meta"><span>小k</span></div>
          <div v-if="streamSteps.length" class="steps steps--live">
            <div v-for="(s, i) in streamSteps" :key="i" class="steps__item">
              <el-icon v-if="s.denied" class="steps__denied"><CircleClose /></el-icon>
              <el-icon v-else class="steps__ok"><CircleCheck /></el-icon>
              <span>{{ s.label }}</span>
              <span v-if="!s.denied" class="steps__rows">{{ s.rows }} 条</span>
            </div>
          </div>
          <div class="amsg__bubble amsg__md">
            <div v-if="streamText" v-html="streamHtml" />
            <div v-if="phaseText" class="amsg__phase"><el-icon class="is-loading"><Loading /></el-icon>{{ phaseText }}</div>
          </div>
        </div>
      </div>
    </div>

    <footer class="ai__composer">
      <div class="composer">
        <el-input
          v-model="draft"
          type="textarea"
          :autosize="{ minRows: 2, maxRows: 6 }"
          resize="none"
          :maxlength="MAX_LEN"
          :placeholder="runId ? '小k 正在回答，可以先写下一个问题' : '问问小k，比如：技术部有多少人？'"
          class="composer__input"
          @keydown="onKeydown"
        />
        <div class="composer__bar">
          <span class="composer__hint">小k 的回答由 AI 生成，重要数据请以列表页为准</span>
          <span v-if="draftLen > MAX_LEN - 200" class="composer__count">{{ draftLen }} / {{ MAX_LEN }}</span>
          <el-button v-if="runId" round :icon="VideoPause" class="composer__send" @click="stop">停止</el-button>
          <el-button v-else type="primary" round class="composer__send" :disabled="!canAsk" :loading="sending" @click="ask()">
            发送
          </el-button>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.ai {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.ai__header {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.ai__title {
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.ai__header-ops {
  margin-left: auto;
}

.ai__messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

.ai__more {
  display: flex;
  gap: 4px;
  align-items: center;
  justify-content: center;
  padding: 0 0 12px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.ai__disclosure {
  margin-bottom: 14px;
}

.ai__system {
  margin-bottom: 14px;
  text-align: center;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

/* 新对话分隔：一条细线中间一个标签，一眼看出上下是两个话题 */
.ai__reset {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 6px 0 18px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.ai__reset::before,
.ai__reset::after {
  flex: 1;
  height: 1px;
  background: var(--el-border-color-lighter);
  content: '';
}

.amsg {
  display: flex;
  gap: 10px;
  margin-bottom: 16px;
}

.amsg--mine {
  flex-direction: row-reverse;
}

.amsg__avatar {
  flex: 0 0 auto;
}

/* 小k 的头像：品牌色圆底 + 图标，一眼看出它不是一个人 */
.amsg__bot {
  display: flex;
  flex: 0 0 32px;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--el-color-primary);
  font-size: 17px;
  color: var(--el-color-white);
}

.amsg__body {
  max-width: 62%;
  min-width: 0;
}

/* 回答通常比聊天消息长，还可能带表格，给宽一点 */
.amsg__body--wide {
  max-width: min(760px, 82%);
}

.amsg__meta {
  display: flex;
  gap: 8px;
  margin-bottom: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.amsg--mine .amsg__meta {
  flex-direction: row-reverse;
}

.amsg__bubble {
  padding: 8px 12px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color);
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-primary);
  word-break: break-word;
}

.amsg__bubble--mine {
  border-color: var(--el-color-primary-light-7);
  background: var(--el-color-primary-light-8);
  white-space: pre-wrap;
}

.amsg__bubble--failed {
  border-color: var(--el-color-danger-light-5);
  color: var(--el-color-danger);
}

.amsg__phase {
  display: flex;
  gap: 6px;
  align-items: center;
  color: var(--el-text-color-secondary);
}

.amsg__partial {
  margin-top: 6px;
  padding: 6px 10px;
  border-left: 2px solid var(--el-border-color);
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.amsg__partial-label {
  margin-bottom: 2px;
  font-size: 12px;
}

.amsg__foot {
  display: flex;
  gap: 4px;
  align-items: center;
  margin-top: 4px;
}

.amsg__op {
  padding: 2px 6px;
  border: 0;
  border-radius: 4px;
  background: transparent;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  cursor: pointer;
}

.amsg__op:hover {
  background: var(--el-fill-color);
  color: var(--el-text-color-primary);
}

.amsg__op--on {
  color: var(--el-color-primary);
}

/* ---------------- Markdown：只有渲染器会生成的这几种标签 ---------------- */
.amsg__md :deep(p) {
  margin: 0 0 6px;
}

.amsg__md :deep(p:last-child) {
  margin-bottom: 0;
}

.amsg__md :deep(ul),
.amsg__md :deep(ol) {
  margin: 4px 0 6px;
  padding-left: 20px;
}

.amsg__md :deep(code) {
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--el-fill-color);
  font-size: 13px;
}

.amsg__md :deep(.ai-table) {
  margin: 6px 0;
  overflow-x: auto;
}

.amsg__md :deep(table) {
  border-collapse: collapse;
  font-size: 13px;
}

.amsg__md :deep(th),
.amsg__md :deep(td) {
  padding: 4px 10px;
  border: 1px solid var(--el-border-color-lighter);
  text-align: left;
  white-space: nowrap;
}

.amsg__md :deep(th) {
  background: var(--el-fill-color-light);
  font-weight: 600;
}

.amsg__md :deep(.ai-link) {
  margin: 2px 4px 2px 0;
  padding: 2px 10px;
  border: 1px solid var(--el-color-primary-light-5);
  border-radius: 12px;
  background: var(--el-color-primary-light-9);
  font-size: 12px;
  color: var(--el-color-primary);
  cursor: pointer;
}

.amsg__md :deep(.ai-link:hover) {
  background: var(--el-color-primary-light-8);
}

/* ---------------- 查询步骤 ---------------- */
.steps {
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.steps summary {
  cursor: pointer;
  user-select: none;
}

.steps__item {
  display: flex;
  gap: 6px;
  align-items: center;
  padding: 2px 0 2px 14px;
}

.steps--live .steps__item {
  padding-left: 0;
}

.steps__ok {
  color: var(--el-color-success);
}

.steps__denied {
  color: var(--el-color-danger);
}

.steps__rows {
  color: var(--el-text-color-placeholder);
}

/* ---------------- 示例问题 ---------------- */
.suggest {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}

.suggest__item {
  padding: 5px 12px;
  border: 1px solid var(--el-border-color);
  border-radius: 14px;
  background: var(--el-bg-color);
  font-size: 13px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.suggest__item:hover:not(:disabled) {
  border-color: var(--el-color-primary-light-5);
  color: var(--el-color-primary);
}

.suggest__item:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

/* ---------------- 输入区：与聊天同一种圆角框 ---------------- */
.ai__composer {
  padding: 4px 16px 14px;
}

.composer {
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-bg-color);
  transition: border-color 0.2s;
}

.composer:focus-within {
  border-color: var(--el-color-primary);
}

.composer__input :deep(.el-textarea__inner) {
  padding: 10px 12px 4px;
  border: 0;
  box-shadow: none;
  background: transparent;
}

.composer__bar {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 4px 8px 8px 12px;
}

.composer__hint {
  flex: 1;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.composer__count {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.composer__send {
  min-width: 72px;
}
</style>
