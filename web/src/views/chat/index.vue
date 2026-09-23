<script setup lang="ts">
/**
 * 聊天
 *
 * 布局：最左图标栏（消息 / 通讯录）→ 中栏（会话列表或通讯录）→ 右栏（消息区或名片）。
 * 产品规则见 docs/chat-prd.md，技术设计见 docs/chat-tech.md。
 *
 * 三件与全站其他页面不同的事，都是有意的：
 *
 * - **不用 `ProTable`**：它是分页表格的抽象，聊天要的是倒序无限滚动 + 粘底，
 *   两者的滚动语义完全相反
 * - **消息顺序以服务端 seq 为准**：本地乐观上屏的消息拿到响应后按 seq 归位，
 *   不是简单替换——期间可能已经收到别人的消息，直接替换会让顺序错乱
 * - **发消息走 HTTP**，长连接只负责收
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  ArrowLeft,
  Bell,
  ChatDotRound,
  Delete,
  Document,
  Edit,
  FolderOpened,
  Loading,
  MuteNotification,
  Notebook,
  Picture,
  Plus,
  RefreshLeft,
  Remove,
  Search,
  Setting,
  Top,
  UserFilled,
} from '@element-plus/icons-vue'
import EmptyState from '@/components/EmptyState.vue'
import { EMOJIS } from './emoji'
import GroupAvatar from './GroupAvatar.vue'
import { BizError } from '@/utils/request'
import { formatListTime, formatMessageTime, parseChatTime } from '@/utils/chatTime'
import { copyText as writeClipboard, uuid } from '@/utils/secureFallback'
import { BizCode } from '@/constants/bizCode'
import { fetchInbox, readAllNotices, readNotice, type BellNotice, type NoticeDetail } from '@/api/notice'
import { chatSocket } from '@/utils/chatSocket'
import { useUserStore } from '@/stores/user'
import { useChatStore } from '@/stores/chat'
import { notifyPrefs, requestPermission } from '@/utils/chatNotify'
import { useRoute } from 'vue-router'
import {
  getContact,
  getContactDepts,
  getContacts as getContactPage,
  type ContactDept,
  type ContactPerson,
} from '@/api/contact'
import {
  addMembers,
  createGroup,
  dissolveGroup,
  getConversation,
  getConversations,
  getMembers,
  getMessages,
  markRead,
  openConversation,
  recallMessage,
  removeConversation,
  removeMember,
  sendMessage,
  updateGroup,
  updateSettings,
  uploadChatFile,
  type ChatConversation,
  type ChatConversationRow,
  type ChatMember,
  type ChatMessage,
} from '@/api/chat'

/** 本地乐观上屏的消息多带两个字段，服务端不认识它们 */
interface LocalMessage extends ChatMessage {
  _state?: 'sending' | 'failed'
}

/** 一页多少条，也是「还有没有更早的」的判据：取回来不满一页就是到头了 */
const PAGE_SIZE = 30

/** 与服务端 `chat.message.maxLength` 的默认值一致；改了参数这里只影响提示，拦截仍在服务端 */
const MAX_LEN = 5000

/** 连续消息合并、插时间分隔的间隔 */
const GAP_MS = 5 * 60 * 1000

/**
 * 出错提示
 *
 * 接口错误请求拦截器已经弹过了（400 的 message、429 的「过于频繁」都在那里），
 * 这里再弹就是同一句话出现两次。只补拦截器管不到的：本地异常与 422
 */
function toastError(e: unknown, fallback: string) {
  if (e instanceof BizError && e.status !== 422) return
  ElMessage.error(e instanceof Error ? e.message : fallback)
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
const fileInput = ref<HTMLInputElement>()
const imageInput = ref<HTMLInputElement>()
const uploading = ref(false)

/**
 * 对方读到哪条了（单聊）
 *
 * 只用来给**我发出的最后一条**打「已读」——逐条标已读在单聊里没有信息量，
 * 对方读到哪条，前面的必然都读过了（chat-prd.md §5.4）。
 */
const peerReadSeq = ref(0)

/** 通讯录里点中的人：右栏显示他的名片，而不是直接开会话 */
const activeContact = ref<ContactPerson | null>(null)

/** 会话列表的本地搜索。会话最多几百个，全在内存里，不值得为它打接口 */
const convKeyword = ref('')
const filteredConversations = computed(() => {
  const kw = convKeyword.value.trim()
  return kw ? conversations.value.filter((c) => c.name.includes(kw)) : conversations.value
})

/** 群成员抽屉「添加成员」的勾选 */
const picked = ref<number[]>([])

/**
 * 发起群聊弹窗
 *
 * 有自己的一套搜索与勾选，不复用通讯录栏的：两者同时存在（弹窗开着时
 * 通讯录栏里的筛选还在），共用状态的话关掉弹窗通讯录就被改掉了。
 * 勾选存整个对象而不是 id：右边「已选」要显示头像和名字，而搜索换词后
 * 左边列表里已经没有那个人了
 */
const groupDialog = ref(false)
const groupKeyword = ref('')
const groupCandidates = ref<ContactPerson[]>([])
const groupLoading = ref(false)
const groupPicked = ref<ContactPerson[]>([])
const groupCreating = ref(false)

/** 群成员抽屉 */
const memberDrawer = ref(false)
const members = ref<ChatMember[]>([])
const loadingMembers = ref(false)
const addMode = ref(false)

const isGroup = computed(() => conversation.value?.type === 2)
const isOwner = computed(() => !!conversation.value && conversation.value.owner_id === myId.value)

/**
 * @ 面板
 *
 * `mentioned` 记「我点选过谁」，发送时再拿最终文本去匹配 `@姓名`——
 * 这样用户把 `@张明` 删掉之后，那个 id 自然就不会被带上，
 * 不需要监听删除、也不需要维护光标位置与 id 的对应关系。
 */
const atVisible = ref(false)
const atKeyword = ref('')
const mentioned = ref(new Map<string, number>())
const textareaRef = ref()

/** 提醒开关。存 localStorage，是每个浏览器各自的偏好 */
const prefDesktop = ref(notifyPrefs.desktop)
const prefSound = ref(notifyPrefs.sound)

const route = useRoute()

/**
 * 打开过的会话各自记一个滚动位置
 *
 * 只存在内存里：刷新页面就该回到底部（那是「我来看新消息」的语义），
 * 而在会话之间来回切时，回到原来的位置才不会让人重新找。
 */
const scrollMemo = new Map<number, number>()

const atCandidates = computed(() => {
  const kw = atKeyword.value.trim()
  const list = members.value.filter((m) => m.user_id !== myId.value)

  return kw ? list.filter((m) => m.real_name.includes(kw)) : list
})

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
  // 图标栏按钮留着焦点的话，它的 tooltip 会一直挂着
  ;(document.activeElement as HTMLElement | null)?.blur()
  if (sideTab.value === tab) return
  sideTab.value = tab

  /*
   * 看通讯录时当前会话并不在眼前：告诉 store「没在看」，
   * 这期间它来的消息才会照常计未读、弹提醒；切回来再补一次已读
   */
  if (tab === 'chat') {
    if (conversation.value) {
      chatStore.enter(conversation.value.id)
      clearUnreadLocally(conversation.value.id)
      void flushRead()
    }
    return
  }

  chatStore.leave()

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

  // 离开前记下当前滚动位置，切回来时还原
  if (conversation.value && listRef.value) {
    scrollMemo.set(conversation.value.id, listRef.value.scrollTop)
  }

  noticeMode.value = false
  conversation.value = row
  chatStore.enter(row.id)
  clearUnreadLocally(row.id)
  // 换会话必须清零：不清的话上一个会话的已读水位会把这个会话的消息也标成「已读」
  peerReadSeq.value = 0
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

// ---------------------------------------------------------------- 右键菜单

/**
 * 会话右键菜单
 *
 * 原来是 hover 时浮出三个图标按钮，问题有二：图标按钮要靠 tooltip 才知道是什么，
 * 而且浮层会盖住摘要与时间——鼠标只是经过也会把一行信息挡掉。
 * 右键菜单是桌面 IM 的通用做法，带文字、不占列表空间、不经过就不出现。
 */
const menu = reactive<{ row: ChatConversationRow | null; x: number; y: number }>({ row: null, x: 0, y: 0 })
const menuRef = ref<HTMLElement>()

async function openMenu(e: MouseEvent, row: ChatConversationRow) {
  menu.row = row
  menu.x = e.clientX
  menu.y = e.clientY

  // 贴着视口边缘右键时往回收，别让菜单一半跑到屏幕外。
  // 要等渲染出来才量得到真实尺寸（三行还是四行、文字宽度都会变）
  await nextTick()
  const el = menuRef.value
  if (!el) return

  const { width, height } = el.getBoundingClientRect()
  menu.x = Math.min(menu.x, window.innerWidth - width - 8)
  menu.y = Math.min(menu.y, window.innerHeight - height - 8)
}

function closeMenu() {
  menu.row = null
}

async function onMenu(action: 'pin' | 'mute' | 'delete' | 'quit' | 'dissolve') {
  const row = menu.row
  closeMenu()
  if (!row) return

  try {
    if (action === 'pin') await togglePin(row)
    else if (action === 'mute') await toggleMute(row)
    else if (action === 'delete') await removeRow(row)
    else if (action === 'quit') await quitGroup(row.id)
    else await doDissolve(row.id)
  } catch (e) {
    // 取消确认框会抛 'cancel'，那不是错误
    if (e !== 'cancel') toastError(e, '操作失败')
  }
}

/**
 * 点任意别处、滚动、窗口变化、按 Esc 都收起菜单
 *
 * 监听挂在 capture 阶段：列表里的点击有 @click.stop 的地方，冒泡阶段收不到。
 * 右键另一行时，先触发这里的 contextmenu 关掉旧菜单，再由那一行打开新的——
 * 所以这里判一下事件是不是来自菜单自身
 */
function onGlobalPointer(e: Event) {
  const target = e.target as Node
  if (menu.row && !menuRef.value?.contains(target)) closeMenu()
  if (peek.visible && !peekRef.value?.contains(target)) closePeek()
}

function onGlobalKey(e: KeyboardEvent) {
  if (e.key === 'Escape') closeFloating()
}

/** 滚动、窗口变化时两种浮层都收起：它们是 fixed 定位，内容一动位置就对不上了 */
function closeFloating() {
  closeMenu()
  closePeek()
}

// ---------------------------------------------------------------- 头像名片

/**
 * 点消息头像看对方资料（钉钉同款）
 *
 * 与右键菜单同一套做法：Teleport 到 body + fixed 定位，而不是每条消息挂一个
 * el-popover——一屏几十条消息就是几十个 popover 实例，而同一时刻最多只开一个。
 *
 * 资料现取现用、按人缓存：消息里只有 sender_name，职位部门电话都没有；
 * 同一个人的头像一屏里会点很多次，不必每次都打接口。
 */
const peek = reactive<{
  visible: boolean
  loading: boolean
  /** 当前名片是谁的，用来丢弃过期的请求结果 */
  userId: number
  person: ContactPerson | null
  /** 取不到资料（已离职、无权限）时退回消息里带的名字 */
  fallbackName: string
  x: number
  y: number
}>({ visible: false, loading: false, userId: 0, person: null, fallbackName: '', x: 0, y: 0 })
const peekRef = ref<HTMLElement>()
const peekCache = new Map<number, ContactPerson>()

async function openPeek(e: MouseEvent, m: LocalMessage) {
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const WIDTH = 280

  // 对方的头像在左边，名片往右弹；自己的在右边，往左弹——都不盖住被点的头像
  peek.x = isMine(m) ? rect.left - WIDTH - 8 : rect.right + 8
  peek.y = rect.top
  peek.userId = m.sender_id
  peek.fallbackName = isMine(m) ? userStore.nickname : m.sender_name
  peek.person = peekCache.get(m.sender_id) ?? null
  peek.loading = !peek.person
  peek.visible = true

  if (!peek.person) {
    try {
      const person = await getContact(m.sender_id)
      peekCache.set(person.id, person)
      // 请求期间可能已经点了别人，结果对不上就丢掉
      if (peek.visible && peek.userId === person.id) {
        peek.person = person
      }
    } catch {
      /* 取不到就只显示名字 */
    } finally {
      if (peek.userId === m.sender_id) peek.loading = false
    }
  }

  // 贴着视口底边时往上收，与右键菜单同理
  await nextTick()
  const el = peekRef.value
  if (!el) return
  const { height } = el.getBoundingClientRect()
  peek.x = Math.max(8, Math.min(peek.x, window.innerWidth - WIDTH - 8))
  peek.y = Math.max(8, Math.min(peek.y, window.innerHeight - height - 8))
}

function closePeek() {
  peek.visible = false
}

/**
 * 名片上的「发消息」按钮只在有意义时出现：
 * 不是我自己，也不是当前这个单聊的对方（已经在跟他聊了）
 */
const peekCanChat = computed(() => {
  const p = peek.person
  if (!p || p.id === myId.value) return false

  return !(conversation.value?.type === 1 && conversation.value.peer_id === p.id)
})

async function chatFromPeek() {
  const p = peek.person
  closePeek()
  if (p) await openWith(p)
}

/** 会话列表的时间，分档规则见 utils/chatTime */
function listTime(at: string | null): string {
  const d = parseChatTime(at)
  return d ? formatListTime(d) : ''
}

// ---------------------------------------------------------------- 会话

async function openWith(contact: ContactPerson) {
  // 点了「发送消息」就切回消息：用户的意图是「跟这个人说话」，不是「继续浏览通讯录」
  sideTab.value = 'chat'

  const conv = await openConversation(contact.id)

  // 服务端可能返回一个已存在的会话，列表里未必有（之前删过）。重拉一次最稳，
  // 顺带拿到它的未读与置顶状态
  await loadList()

  const row = conversations.value.find((c) => c.id === conv.id)
  if (row) {
    await selectConversation(row)
  } else {
    noticeMode.value = false
    conversation.value = conv
    chatStore.enter(conv.id)
    await loadHistory()
  }
}

// ---------------------------------------------------------------- 系统公告

/**
 * 「系统公告」入口
 *
 * 它不是一个会话：数据直接读公告与已读回执（与顶栏铃铛同一份），不往聊天表里复制。
 * 这样撤回、删除、已读天然一致——复制一份的话，撤回后每个人那里都还留着一条。
 * 未读计入总数，由服务端的未读汇总（chatStore.notice）一并给出
 */
const noticeMode = ref(false)
const notices = ref<BellNotice[]>([])
const noticePage = ref(1)
const noticeTotal = ref(0)
const noticeLoading = ref(false)
const noticeDetail = ref<NoticeDetail | null>(null)
const NOTICE_PAGE_SIZE = 20

/** 搜索时只有关键词像「系统公告」才留着这一行，否则它会挡在搜索结果最上面 */
const showNoticeRow = computed(() => {
  const kw = convKeyword.value.trim()
  return !kw || '系统公告'.includes(kw)
})

async function openNoticePanel(focusId = 0) {
  // 与切会话一样：离开前记下滚动位置
  if (conversation.value && listRef.value) {
    scrollMemo.set(conversation.value.id, listRef.value.scrollTop)
  }

  conversation.value = null
  messages.value = []
  // 看公告时没有「正在看的会话」，这期间来的消息照常计未读、照常提醒
  chatStore.leave()

  noticeMode.value = true
  noticeDetail.value = null
  await loadNotices(true)

  if (focusId) await openNotice(focusId)
}

async function loadNotices(reset = false) {
  if (noticeLoading.value) return
  if (reset) noticePage.value = 1

  noticeLoading.value = true
  try {
    const res = await fetchInbox({ page_num: noticePage.value, page_size: NOTICE_PAGE_SIZE })
    notices.value = reset ? res.list : [...notices.value, ...res.list]
    noticeTotal.value = res.total
  } finally {
    noticeLoading.value = false
  }
}

const noticeHasMore = computed(() => notices.value.length < noticeTotal.value)

function onNoticeScroll(e: Event) {
  const el = e.target as HTMLElement
  if (!noticeHasMore.value || noticeLoading.value) return
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 80) {
    noticePage.value += 1
    void loadNotices()
  }
}

/**
 * 打开一条：服务端顺带落已读回执，并推 notice.read 给我自己的所有标签页，
 * 各处的数字靠那个推送对齐；这里再主动刷一次，是为了长连接断着的时候也对
 */
async function openNotice(id: number) {
  try {
    noticeDetail.value = await readNotice(id)
  } catch (e) {
    // 404：点开的那一刻它刚被撤回或删除
    toastError(e, '公告不存在或已被撤回')
    await loadNotices(true)
    return
  }

  const hit = notices.value.find((n) => n.id === id)
  if (hit) hit.is_read = true
  void chatStore.refresh()
}

async function markAllNoticesRead() {
  await readAllNotices()
  notices.value = notices.value.map((n) => ({ ...n, is_read: true }))
  void chatStore.refresh()
}

/** 公告有变化时，正开着公告面板就重拉；正在看的那条被撤回 / 删除了就退回列表 */
function onNoticeChanged(data: unknown) {
  if (!noticeMode.value) return
  const d = data as { id: number; action: string }

  if (noticeDetail.value?.id === d.id && (d.action === 'revoked' || d.action === 'deleted')) {
    noticeDetail.value = null
    ElMessage.info('这条公告已被撤回')
  }
  void loadNotices(true)
}

async function loadHistory() {
  if (!conversation.value) return

  // 群聊顺带拉成员：消息头像要用（见 avatarOf），@ 面板也要用。
  // 每次换会话都重拉——只在「为空时拉」的话，换到另一个群还是上一个群的人
  if (isGroup.value) void loadMembersQuietly()
  else members.value = []

  loadingMessages.value = true
  try {
    messages.value = await getMessages(conversation.value.id, { limit: PAGE_SIZE })
    hasMore.value = messages.value.length === PAGE_SIZE
    await scrollToBottom()
    await flushRead()

    /*
     * 初始化对方的已读水位
     *
     * 不做的话刷新页面后「已读」会消失，直到对方**再读一次**才重新出现——
     * 而对方可能早就读完了，不会再触发任何事件。
     *
     * 这里用的是一个保守近似：会话列表里有对方的 max_seq 但没有对方的已读水位，
     * 服务端目前也不返回它（返回它等于把「对方读到哪」暴露给一个列表接口）。
     * 所以退而求其次——只要我不是最后一个发言的人，说明对方看过了。
     * 这个近似在「对方发过消息之后」总是对的，而那是绝大多数情况。
     */
    const last = messages.value[messages.value.length - 1]
    if (last && last.sender_id !== myId.value) {
      peerReadSeq.value = Math.max(peerReadSeq.value, last.seq)
    }

    // 有记录就还原到上次的位置，没有就停在底部（scrollToBottom 已经做过了）
    const memo = scrollMemo.get(conversation.value.id)
    if (memo !== undefined) {
      await nextTick()
      if (listRef.value) listRef.value.scrollTop = memo
    }
  } finally {
    loadingMessages.value = false
  }
}

// ---------------------------------------------------------------- 向上翻历史

const hasMore = ref(false)
const loadingOlder = ref(false)

/**
 * 滚到顶附近就取更早的一页
 *
 * ⚠️ 插到前面之后要把滚动位置补回去：内容在上方长高了，scrollTop 不变的话
 * 视口会跟着内容往下走，用户正在看的那条被顶出屏幕，看起来像「跳了一下」。
 * 做法是记下插入前的 scrollHeight，插入后把差值加回 scrollTop
 */
async function loadOlder() {
  const conv = conversation.value
  const el = listRef.value
  if (!conv || !el || !hasMore.value || loadingOlder.value) return

  // 乐观消息的 seq 是 MAX_SAFE_INTEGER，不能当游标
  const first = messages.value.find((m) => m.seq !== Number.MAX_SAFE_INTEGER)
  if (!first) return

  loadingOlder.value = true
  try {
    const older = await getMessages(conv.id, { before_seq: first.seq, limit: PAGE_SIZE })
    // 请求期间切了会话，结果不属于眼前这个
    if (conversation.value?.id !== conv.id) return

    hasMore.value = older.length === PAGE_SIZE
    if (!older.length) return

    const prevHeight = el.scrollHeight
    const prevTop = el.scrollTop
    messages.value = [...older, ...messages.value]

    await nextTick()
    el.scrollTop = el.scrollHeight - prevHeight + prevTop
  } catch {
    /* 失败就停在原地，下次滚到顶再试 */
  } finally {
    loadingOlder.value = false
  }
}

function onListScroll() {
  const el = listRef.value
  if (el && el.scrollTop < 80) void loadOlder()
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
  // 在看通讯录时右栏是名片，消息并没有被看见
  if (!conv || !last || document.hidden || sideTab.value !== 'chat') return

  try {
    await markRead(conv.id, last.seq)
  } catch {
    // 已读失败不打扰用户：下次打开会话或收到新消息时会再推一次
  }
}

// ---------------------------------------------------------------- 收发

async function send() {
  const text = draft.value.trim()
  if (!text || !canSend.value) return
  if (tooLong.value) {
    ElMessage.warning(`消息不能超过 ${MAX_LEN} 字，当前 ${draftLen.value} 字`)
    return
  }

  const mentions = collectMentions(text)

  draft.value = ''
  atVisible.value = false
  mentioned.value.clear()

  await deliver({
    type: 'text',
    content: text,
    extra: mentions as ChatMessage['extra'],
  })
}

/**
 * 发一条消息：乐观上屏 → 请求 → 归位
 *
 * 文本、图片、文件三种走同一条路径，失败重发也复用它——
 * 三处各写一遍的话，「失败要标红」「成功要按 seq 归位」这类细节总有一处会漏。
 */
async function deliver(
  payload: { type: string; content: string; extra?: ChatMessage['extra'] },
  reuseClientMsgId?: string,
) {
  const conv = conversation.value
  if (!conv) return

  // 不直接用 crypto.randomUUID：线上是 http，非安全上下文里它不存在（见 utils/secureFallback）
  const clientMsgId = reuseClientMsgId ?? uuid()

  // 重发时复用同一个 client_msg_id：服务端靠它幂等，
  // 换一个的话「超时但其实成功了」的那条会变成两条
  if (reuseClientMsgId) {
    const existing = messages.value.find((m) => m.client_msg_id === clientMsgId)
    if (existing) existing._state = 'sending'
  } else {
    messages.value.push({
      id: 0,
      conv_id: conv.id,
      // 比任何真实 seq 都大，保证排在末尾；拿到响应后换成真实 seq
      seq: Number.MAX_SAFE_INTEGER,
      sender_id: myId.value,
      sender_name: '我',
      type: payload.type as ChatMessage['type'],
      content: payload.content,
      extra: payload.extra ?? null,
      client_msg_id: clientMsgId,
      status: 1,
      recalled_by: 0,
      created_at: '',
      _state: 'sending',
    })
    await scrollToBottom()
  }

  sending.value = true
  try {
    const saved = await sendMessage(conv.id, {
      client_msg_id: clientMsgId,
      type: payload.type,
      content: payload.content,
      extra: payload.extra ?? null,
    })
    replaceOptimistic(clientMsgId, saved)
  } catch (e) {
    const target = messages.value.find((m) => m.client_msg_id === clientMsgId)
    if (target) target._state = 'failed'

    // 发的时候对方刚好离职了：界面立刻切到只读，不用等刷新
    if (e instanceof BizError && e.code === BizCode.CHAT_PEER_DISABLED && conversation.value) {
      conversation.value = { ...conversation.value, peer_active: false }
    }
    toastError(e, '发送失败')
  } finally {
    sending.value = false
  }
}

/** 点击重发。用原来那条的内容与 client_msg_id，不新建一条 */
function resend(m: LocalMessage) {
  if (m._state !== 'failed') return
  void deliver({ type: m.type, content: m.content, extra: m.extra }, m.client_msg_id)
}

/**
 * 桌面通知开关
 *
 * 打开时才申请权限——一进页面就弹权限框是最招人烦的做法，
 * 而且 Chrome 会把这种请求判为 spam 并永久拒绝。
 */
async function toggleDesktop(value: string | number | boolean) {
  const on = value === true
  if (on && !(await requestPermission())) {
    prefDesktop.value = false
    ElMessage.warning('浏览器拒绝了通知权限，请在地址栏左侧的站点设置里开启')
    return
  }

  notifyPrefs.desktop = on
}

function toggleSound(value: string | number | boolean) {
  notifyPrefs.sound = value === true
}

// ---------------------------------------------------------------- @

/**
 * 输入时检测 @
 *
 * 只在**群聊**里触发：单聊就两个人，@ 没有意义，弹面板只会挡住输入。
 * 触发条件是「光标前最近的 @ 之后还没有空格」——有空格说明那个 @ 已经
 * 输完了（或者根本不是在 @ 人，比如在写邮箱）。
 */
function onDraftInput() {
  if (!isGroup.value) return

  const text = draft.value
  const at = text.lastIndexOf('@')

  if (at < 0 || /\s/.test(text.slice(at + 1))) {
    atVisible.value = false
    return
  }

  atKeyword.value = text.slice(at + 1)

  // 面板第一次打开时才拉成员，之后复用
  if (!atVisible.value && !members.value.length) void loadMembersQuietly()

  atVisible.value = true
}

/** 静默取成员（@ 面板与消息头像用），不开抽屉 */
async function loadMembersQuietly() {
  const id = conversation.value!.id
  try {
    const list = await getMembers(id)
    // 请求期间切了会话就丢掉，否则 A 群的成员会盖到 B 群上
    if (conversation.value?.id === id) members.value = list
  } catch {
    /* 取不到就让面板空着、头像退回首字，不影响聊天 */
  }
}

/** 表情面板开关（受控，点完一个表情不收起，方便连着点几个） */
const emojiVisible = ref(false)
const emojiOffset = ref(12)
const composerRef = ref<HTMLElement>()

/**
 * 表情面板往上抬到整个输入框之上
 *
 * 面板锚在按钮上，而按钮在输入框底部——不抬的话面板正好盖住正在打的字，
 * 插了什么表情都看不见。输入框会随内容长高，所以每次打开前现量
 */
function measureEmojiOffset(e: MouseEvent) {
  const btn = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const box = composerRef.value?.getBoundingClientRect()
  emojiOffset.value = box ? btn.top - box.top + 8 : 12
}

/**
 * 插入表情：插在光标处而不是追加到末尾
 *
 * 点表情时焦点已经跑到面板上了，但 textarea 的 selectionStart 在失焦后仍然保留，
 * 所以还能拿到用户原来的光标位置
 */
function insertEmoji(emoji: string) {
  insertText(emoji)
}

/** 在光标处插入一段文本（表情、Ctrl+Enter 补的换行都走这里） */
function insertText(piece: string) {
  const el: HTMLTextAreaElement | undefined = textareaRef.value?.textarea
  const text = draft.value
  const start = el?.selectionStart ?? text.length
  const end = el?.selectionEnd ?? text.length

  draft.value = text.slice(0, start) + piece + text.slice(end)

  void nextTick(() => {
    if (!el) return
    el.focus()
    const caret = start + piece.length
    el.setSelectionRange(caret, caret)
  })
}

/** 工具栏上的 @ 按钮：补一个 @ 并弹出面板，和手打 @ 走同一条路 */
function insertAt() {
  const text = draft.value
  draft.value = text + (text && !/\s$/.test(text) ? ' @' : '@')
  onDraftInput()
  void nextTick(() => textareaRef.value?.focus())
}

/** 选中某人：把 `@姓名 ` 替换进去，并记下 id */
function pickMention(name: string, userId: number) {
  const text = draft.value
  const at = text.lastIndexOf('@')

  draft.value = text.slice(0, at) + `@${name} `
  mentioned.value.set(name, userId)
  atVisible.value = false

  // 焦点要还回去，否则选完人光标丢了，用户得再点一次输入框
  void nextTick(() => textareaRef.value?.focus())
}

/** @所有人：只有群主能用，服务端也会再判一次 */
function pickAtAll() {
  const text = draft.value
  const at = text.lastIndexOf('@')

  draft.value = text.slice(0, at) + '@所有人 '
  atVisible.value = false
  void nextTick(() => textareaRef.value?.focus())
}

/**
 * 从最终文本里解出 @ 了谁
 *
 * 以文本为准而不是以点选记录为准：用户点了 `@张明` 之后又把它删掉，
 * 记录里还留着但文本里没有——按记录发就会给一个没被 @ 的人种红点。
 */
function collectMentions(text: string): { at_all: boolean; at_user_ids: number[] } | null {
  if (!isGroup.value) return null

  const atAll = isOwner.value && text.includes('@所有人')
  const ids: number[] = []

  mentioned.value.forEach((id, name) => {
    if (text.includes(`@${name}`)) ids.push(id)
  })

  if (!atAll && !ids.length) return null

  return { at_all: atAll, at_user_ids: ids }
}

// ---------------------------------------------------------------- 附件

function pickImage() {
  imageInput.value?.click()
}

function pickFile() {
  fileInput.value?.click()
}

/**
 * 选中文件后：先传，再发一条附件消息
 *
 * 两步而不是一步：上传走的是通用接口（`/admin/upload`），它不知道聊天的存在；
 * 发消息才是聊天的事。中间失败的话只留下一个孤儿文件，由留存清理带走——
 * 比做成一步到位然后在聊天接口里处理 multipart 要干净得多。
 */
async function onFilePicked(e: Event, type: 'image' | 'file') {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  // 立刻清空，否则连续选同一个文件不会再触发 change
  input.value = ''
  if (file) await sendFile(file, type)
}

async function sendFile(file: File, type: 'image' | 'file') {
  uploading.value = true
  try {
    const up = await uploadChatFile(file)
    const extra: ChatMessage['extra'] = { url: up.url, name: up.name, size: up.size, ext: up.ext }

    // 图片带上原始宽高：不给的话图片加载完成的瞬间整段消息会往下跳
    if (type === 'image') {
      const size = await imageSize(file).catch(() => null)
      if (size) Object.assign(extra, size)
    }

    await deliver({ type, content: up.name, extra })
  } catch (err) {
    toastError(err, '上传失败')
  } finally {
    uploading.value = false
  }
}

function imageSize(file: File): Promise<{ width: number; height: number }> {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file)
    const img = new Image()
    img.onload = () => {
      URL.revokeObjectURL(url)
      resolve({ width: img.naturalWidth, height: img.naturalHeight })
    }
    img.onerror = () => {
      URL.revokeObjectURL(url)
      reject(new Error('读不出图片尺寸'))
    }
    img.src = url
  })
}

/** 粘贴图片直接发——截图后 Ctrl+V 是最高频的发图方式 */
async function onPaste(e: ClipboardEvent) {
  const item = Array.from(e.clipboardData?.items ?? []).find((i) => i.type.startsWith('image/'))
  if (!item) return

  const file = item.getAsFile()
  if (!file) return

  e.preventDefault()
  await sendFile(file, 'image')
}

// ---------------------------------------------------------------- 拖拽

/**
 * 拖文件进消息区直接发
 *
 * 用计数而不是布尔：拖过子元素时 dragenter/dragleave 会成对地在父子之间来回触发，
 * 布尔值会跟着闪。进一层 +1、出一层 -1，归零才算真的离开
 */
const dragDepth = ref(0)
const dragging = computed(() => dragDepth.value > 0)

function hasFiles(e: DragEvent) {
  return Array.from(e.dataTransfer?.types ?? []).includes('Files')
}

function onDragEnter(e: DragEvent) {
  if (!canSend.value || !hasFiles(e)) return
  dragDepth.value += 1
}

function onDragLeave(e: DragEvent) {
  if (!hasFiles(e)) return
  dragDepth.value = Math.max(0, dragDepth.value - 1)
}

async function onDrop(e: DragEvent) {
  dragDepth.value = 0
  if (!canSend.value) return

  // 一次拖多个就逐个发，保持用户选中的顺序；图片按图片发（能预览），其余按文件
  for (const file of Array.from(e.dataTransfer?.files ?? [])) {
    await sendFile(file, file.type.startsWith('image/') ? 'image' : 'file')
  }
}

// ---------------------------------------------------------------- 输入

/**
 * 发送键：Enter 还是 Ctrl+Enter
 *
 * 老后台用户的高频诉求——写长消息时习惯 Enter 换行。存 localStorage，
 * 与提醒开关一样是每台电脑各自的习惯
 */
const ENTER_KEY = 'keel_chat_enter'
const enterMode = ref<'enter' | 'ctrl'>(readEnterMode())

function readEnterMode(): 'enter' | 'ctrl' {
  try {
    return localStorage.getItem(ENTER_KEY) === 'ctrl' ? 'ctrl' : 'enter'
  } catch {
    return 'enter'
  }
}

watch(enterMode, (v) => {
  try {
    localStorage.setItem(ENTER_KEY, v)
  } catch {
    /* 存不了就只在本次生效 */
  }
})

const sendHint = computed(() =>
  enterMode.value === 'enter' ? 'Enter 发送，Shift+Enter 换行' : 'Ctrl+Enter 发送，Enter 换行',
)

/**
 * 按键
 *
 * ⚠️ 先排掉输入法组合中的 Enter：中文输入法里按 Enter 是「把拼音上屏」，
 * 不排的话打一串英文字母想上屏，消息就直接发出去了
 */
function onComposerKeydown(e: Event) {
  const ev = e as KeyboardEvent
  if (ev.key === 'Escape') {
    atVisible.value = false
    return
  }
  if (ev.key !== 'Enter' || ev.isComposing || ev.keyCode === 229) return

  const withMod = ev.ctrlKey || ev.metaKey
  const shouldSend = enterMode.value === 'enter' ? !ev.shiftKey && !withMod : withMod

  if (shouldSend) {
    ev.preventDefault()
    void send()
    return
  }

  // Ctrl+Enter 模式下 textarea 本身不会因为 Ctrl+Enter 换行，而 Enter 模式下
  // Shift+Enter 是原生换行，都不用管；只有「Enter 模式按了 Ctrl+Enter」要补一个换行
  if (enterMode.value === 'enter' && withMod) {
    ev.preventDefault()
    insertText('\n')
  }
}

/** 字数按字符数算（与服务端 mb_strlen 一致），emoji 不按 UTF-16 算成两个 */
const draftLen = computed(() => [...draft.value].length)
const tooLong = computed(() => draftLen.value > MAX_LEN)

/**
 * 能不能发：单聊对方离职了就不能
 *
 * 老数据或接口没带这个字段时按「能」处理——宁可让服务端拦一次，
 * 也不要因为字段缺失把所有人的输入框都关掉
 */
const canSend = computed(() => {
  const c = conversation.value
  return !!c && !(c.type === 1 && c.peer_active === false)
})

// ---------------------------------------------------------------- 群

function togglePick(id: number) {
  const i = picked.value.indexOf(id)
  if (i >= 0) picked.value.splice(i, 1)
  else picked.value.push(id)
}

async function openGroupDialog() {
  // 加号按钮留着焦点的话，它的 tooltip 会一直浮在弹窗上
  ;(document.activeElement as HTMLElement | null)?.blur()
  groupDialog.value = true
  groupKeyword.value = ''
  groupPicked.value = []
  await loadGroupCandidates()
}

async function loadGroupCandidates() {
  groupLoading.value = true
  try {
    const res = await getContactPage({ keyword: groupKeyword.value.trim(), page_size: 100 })
    // 自己不出现在候选里：建群的人必然在群里，服务端也会自动加上
    groupCandidates.value = res.list.filter((c) => c.id !== myId.value)
  } finally {
    groupLoading.value = false
  }
}

let groupSearchTimer: number | undefined
watch(groupKeyword, () => {
  clearTimeout(groupSearchTimer)
  groupSearchTimer = window.setTimeout(loadGroupCandidates, 300)
})

function isGroupPicked(id: number) {
  return groupPicked.value.some((c) => c.id === id)
}

function toggleGroupPick(c: ContactPerson) {
  if (isGroupPicked(c.id)) groupPicked.value = groupPicked.value.filter((x) => x.id !== c.id)
  else groupPicked.value.push(c)
}

/**
 * 建群
 *
 * 选满 2 人才给建：两个人的「群」就是单聊，而单聊有自己的去重逻辑。
 * 群名留空让服务端拼默认名——前端再拼一遍就是两份规则。
 */
async function submitGroup() {
  if (groupPicked.value.length < 2) {
    ElMessage.warning('群聊至少需要选择 2 位同事')
    return
  }

  groupCreating.value = true
  try {
    const conv = await createGroup(groupPicked.value.map((c) => c.id))
    groupDialog.value = false
    convKeyword.value = ''
    await loadList()

    const row = conversations.value.find((c) => c.id === conv.id)
    if (row) await selectConversation(row)
  } catch (e) {
    toastError(e, '建群失败')
  } finally {
    groupCreating.value = false
  }
}

async function openMembers() {
  memberDrawer.value = true
  addMode.value = false
  loadingMembers.value = true
  try {
    members.value = await getMembers(conversation.value!.id)
  } finally {
    loadingMembers.value = false
  }
}

/** 进入「加人」模式：复用通讯录，但排掉已在群里的 */
async function startAdd() {
  addMode.value = true
  picked.value = []
  await ensureDepts()
  await loadContacts()
}

const addableContacts = computed(() => {
  const inGroup = new Set(members.value.map((m) => m.user_id))
  return contacts.value.filter((c) => !inGroup.has(c.id))
})

async function submitAdd() {
  if (!picked.value.length) return

  try {
    await addMembers(conversation.value!.id, picked.value)
    picked.value = []
    addMode.value = false
    await openMembers()
    await refreshConversation()
  } catch (e) {
    toastError(e, '添加失败')
  }
}

async function kick(m: ChatMember) {
  await ElMessageBox.confirm(`确定把「${m.real_name}」移出群聊？`, '移出成员', { type: 'warning' })
  await removeMember(conversation.value!.id, m.user_id)
  await openMembers()
  await refreshConversation()
}

/**
 * 退群 / 解散
 *
 * 带 id 参数：右键菜单是对**某一行**操作，那一行不一定是当前打开的会话。
 * 不传就是当前会话（群成员抽屉里的按钮走这条）。
 */
async function quitGroup(id = conversation.value!.id) {
  await ElMessageBox.confirm('退出后将不再接收该群消息，历史记录也会从列表移除。', '退出群聊', {
    type: 'warning',
    confirmButtonText: '退出',
  })

  await removeMember(id, myId.value)
  memberDrawer.value = false
  await afterLeaveGroup(id)
}

async function doDissolve(id = conversation.value!.id) {
  await ElMessageBox.confirm(
    '解散后所有成员的会话都会消失。消息会保留在服务器上（审计需要），但没有人能再看到。',
    '解散群聊',
    { type: 'warning', confirmButtonText: '解散' },
  )

  await dissolveGroup(id)
  memberDrawer.value = false
  await afterLeaveGroup(id)
}

/**
 * 退群/解散之后：从列表移除；只有它恰好是当前打开的会话才清空右栏——
 * 在右键菜单里退掉另一个群，不该把正在聊的这个也关掉
 */
async function afterLeaveGroup(id: number) {
  if (conversation.value?.id === id) {
    conversation.value = null
    messages.value = []
    chatStore.leave()
  }
  conversations.value = conversations.value.filter((c) => c.id !== id)
  void chatStore.refresh()
}

async function renameGroup() {
  const { value } = await ElMessageBox.prompt('群名称', '修改群名', {
    inputValue: conversation.value!.name,
    inputValidator: (v: string) => (v.trim() ? true : '群名称不能为空'),
  })

  applyGroupInfo(await updateGroup(conversation.value!.id, { name: value.trim() }))
}

/**
 * 群头像（群主）
 *
 * 两步：先走聊天的上传拿到地址，再改群资料——与发图片同一条路，服务端只认 `/uploads/chat/` 下的图。
 * 恢复默认 = 把头像置空，列表里重新拼成员头像
 */
const groupAvatarInput = ref<HTMLInputElement>()
const uploadingGroupAvatar = ref(false)

function pickGroupAvatar() {
  if (!isOwner.value || uploadingGroupAvatar.value) return
  groupAvatarInput.value?.click()
}

async function onGroupAvatarPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !conversation.value) return

  if (!file.type.startsWith('image/')) {
    ElMessage.warning('请选择图片')
    return
  }

  uploadingGroupAvatar.value = true
  try {
    const up = await uploadChatFile(file)
    applyGroupInfo(await updateGroup(conversation.value.id, { avatar: up.url }))
    ElMessage.success('群头像已更新')
  } catch (err) {
    toastError(err, '更换失败')
  } finally {
    uploadingGroupAvatar.value = false
  }
}

async function resetGroupAvatar() {
  if (!conversation.value) return
  try {
    applyGroupInfo(await updateGroup(conversation.value.id, { avatar: '' }))
  } catch (err) {
    toastError(err, '操作失败')
  }
}

/**
 * 群资料变了（名称、头像、人数）：标题栏与左栏那一行一起更新
 *
 * 整体换掉 avatar_members 而不是合并：设了自定义头像时服务端不再下发它，
 * 合并的话旧的成员拼图会留着
 */
function applyGroupInfo(fresh: ChatConversation) {
  const pick = {
    name: fresh.name,
    avatar: fresh.avatar,
    avatar_members: fresh.avatar_members,
    member_count: fresh.member_count,
  }

  if (conversation.value?.id === fresh.id) conversation.value = { ...conversation.value, ...pick }

  const row = conversations.value.find((c) => c.id === fresh.id)
  if (row) Object.assign(row, pick)
}

/** 成员数、群名、群头像变了要刷新标题栏与左栏 */
async function refreshConversation() {
  if (!conversation.value) return
  applyGroupInfo(await getConversation(conversation.value.id))
}

// ---------------------------------------------------------------- 撤回

/** 2 分钟内、且是自己发的，才显示撤回按钮。服务端仍会再判一次 */
function canRecall(m: LocalMessage): boolean {
  if (m.sender_id !== myId.value || m.status !== 1 || !m.created_at || m._state) return false

  return Date.now() - new Date(m.created_at.replace(/-/g, '/')).getTime() < 120_000
}

async function recall(m: LocalMessage) {
  try {
    const updated = await recallMessage(m.id)
    applyRecall(updated)
  } catch (e) {
    toastError(e, '撤回失败')
  }
}

function applyRecall(updated: ChatMessage) {
  const idx = messages.value.findIndex((m) => m.id === updated.id)
  if (idx >= 0) messages.value[idx] = { ...messages.value[idx], ...updated }

  const row = conversations.value.find((c) => c.id === updated.conv_id)
  if (row && row.max_seq === updated.seq) row.last_msg_text = '撤回了一条消息'
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
      row.last_msg_text = summaryOf(msg)
      row.last_msg_at = msg.created_at
      row.max_seq = msg.seq
      if (msg.sender_id !== myId.value) row.unread += 1
      // 别的群改了名、换了头像、进出了人：这一行的名字与拼接头像都可能变，重拉一次列表最稳
      if (msg.type === 'system') void loadList()
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
    row.last_msg_text = summaryOf(msg)
    row.last_msg_at = msg.created_at
    row.max_seq = msg.seq
    // 在看通讯录时它并没被看见，照常累加；切回消息时再清零
    if (sideTab.value === 'chat') row.unread = 0
    else if (msg.sender_id !== myId.value) row.unread += 1
  }

  // 入群、退群的系统消息意味着成员变了，头像和 @ 面板要跟着刷新
  // 改群名、改群头像同样落系统消息：标题栏与左栏那一行的名字、头像跟着刷新
  if (msg.type === 'system' && isGroup.value) {
    void loadMembersQuietly()
    void refreshConversation()
  }

  await scrollToBottom()
  await flushRead()
}

/**
 * 左栏摘要，与服务端 ChatService::summarize 同一套规则
 *
 * 原来写的是 `[${msg.type}]`，推送一到，图片、文件、系统消息的摘要就成了
 * `[image]` `[file]` `[system]`，要刷新才变回服务端给的中文
 */
function summaryOf(msg: ChatMessage): string {
  if (msg.type === 'image') return '[图片]'
  if (msg.type === 'file') return '[文件]'
  return [...msg.content].slice(0, 40).join('')
}

async function scrollToBottom() {
  await nextTick()
  const el = listRef.value
  if (el) el.scrollTop = el.scrollHeight
}

// ---------------------------------------------------------------- 生命周期

let offReady: (() => void) | undefined
let offMessage: (() => void) | undefined
let offRecalled: (() => void) | undefined
let offRead: (() => void) | undefined
let offNotice: (() => void) | undefined

onMounted(async () => {
  chatStore.bind()
  await loadList()
  void chatStore.refresh()

  // 从桌面通知点进来会带 ?conv= 或 ?notice=，直接打开那个会话或那条公告
  await openFromQuery()

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
  offRecalled = chatSocket.on('message.recalled', (data) => applyRecall(data as ChatMessage))
  offNotice = chatSocket.on('notice.changed', onNoticeChanged)

  /*
   * 对方已读：把水位记下来，用于给我最后一条消息打「已读」
   *
   * 要排掉自己的回执——我在别的标签页标已读时，这个事件也会推给我，
   * 不排的话「对方已读」会在我自己读消息时亮起来
   */
  offRead = chatSocket.on('conversation.read', (data) => {
    const d = data as { conv_id: number; user_id: number; last_read_seq: number }
    if (d.conv_id !== conversation.value?.id || d.user_id === myId.value) return
    peerReadSeq.value = Math.max(peerReadSeq.value, d.last_read_seq)
  })

  chatSocket.connect()
  // 顶栏的消息入口往往已经先连上了，那次 ready 发生在本页挂监听之前，收不到
  connected.value = chatSocket.connected

  document.addEventListener('visibilitychange', onVisible)

  window.addEventListener('mousedown', onGlobalPointer, true)
  window.addEventListener('contextmenu', onGlobalPointer, true)
  window.addEventListener('scroll', closeFloating, true)
  window.addEventListener('resize', closeFloating)
  window.addEventListener('keydown', onGlobalKey)
})

async function openFromQuery() {
  const notice = Number(route.query.notice || 0)
  if (notice) {
    sideTab.value = 'chat'
    await openNoticePanel(notice)
    return
  }

  const wanted = Number(route.query.conv || 0)
  if (wanted) {
    const row = conversations.value.find((c) => c.id === wanted)
    if (row) await selectConversation(row)
  }
}

/*
 * 已经在聊天页时点桌面通知，路由只变 query、组件不重建，onMounted 不会再跑。
 * 不看这个变化的话，点通知只会把窗口拉到前台，停在原来的会话上
 */
watch(
  () => [route.query.conv, route.query.notice],
  () => void openFromQuery(),
)

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
  offRecalled?.()
  offRead?.()
  offNotice?.()
  document.removeEventListener('visibilitychange', onVisible)

  // 这几条挂在 window 上，页面卸载后不摘的话会一直攒着（页签缓存切换时尤其）
  window.removeEventListener('mousedown', onGlobalPointer, true)
  window.removeEventListener('contextmenu', onGlobalPointer, true)
  window.removeEventListener('scroll', closeFloating, true)
  window.removeEventListener('resize', closeFloating)
  window.removeEventListener('keydown', onGlobalKey)
  // 不 close()：socket 是全局单例，别的地方（顶栏红点）还要用它
})

function isMine(m: LocalMessage) {
  return m.sender_id === myId.value
}

/** 群成员 id → 头像，给消息头像查表用 */
const memberAvatars = computed(() => new Map(members.value.map((m) => [m.user_id, m.avatar])))

/**
 * 消息头像
 *
 * 头像**不存进消息**，按发送人实时查：存进去的话改了头像，历史消息还是旧的
 * （这正是之前「换了头像聊天框没变化」的原因——那时干脆没传头像，只显示首字）。
 * 我自己取登录态；单聊对方就是会话头像；群聊查成员表，查不到（已退群）退回首字
 */
function avatarOf(m: LocalMessage): string | undefined {
  if (isMine(m)) return userStore.avatar || undefined
  if (conversation.value?.type === 1) return conversation.value.avatar || undefined

  return memberAvatars.value.get(m.sender_id) || undefined
}

/**
 * 我发出的最后一条消息的 seq
 *
 * 「已读」只标这一条。逐条标在单聊里没有信息量——对方读到哪条，
 * 前面的必然都读过了，每条都挂个「已读」只是视觉噪音。
 */
const myLastSeq = computed(() => {
  const mine = messages.value.filter((m) => m.sender_id === myId.value && !m._state)
  return mine.length ? mine[mine.length - 1].seq : 0
})

/** 只在单聊、且是我最后一条、且对方水位够到了，才显示 */
function readHintOf(m: LocalMessage): string {
  if (conversation.value?.type !== 1 || m.sender_id !== myId.value || m.seq !== myLastSeq.value) return ''

  return peerReadSeq.value >= m.seq ? '已读' : '未读'
}

/**
 * 图片占位尺寸
 *
 * 用服务端存的原始宽高按比例缩到上限内。**必须在图片加载前就占住位置**，
 * 否则图片到达的瞬间整段消息会往下跳一大截，用户正在看的那条被顶走。
 * 没有宽高信息（老消息、或取尺寸失败）就退回一个固定方块。
 */
function imageStyle(m: LocalMessage): Record<string, string> {
  const MAX_W = 220
  const MAX_H = 260
  const w = m.extra?.width ?? 0
  const h = m.extra?.height ?? 0

  if (!w || !h) return { width: '160px', height: '160px' }

  const scale = Math.min(MAX_W / w, MAX_H / h, 1)

  return { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` }
}

/** 文件大小说成人话。小于 1MB 用 KB——「0.0MB」谁也看不懂 */
function humanSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`

  return `${bytes} B`
}

/** 这条消息 @ 到我了吗——用于给气泡加一圈强调边框 */
function mentionsMe(m: LocalMessage): boolean {
  const ids = m.extra?.at_user_ids
  if (!ids || m.sender_id === myId.value) return false

  return ids.includes(myId.value)
}

/** 发送中的消息还没有服务端时间，按「现在」算——它确实就是现在发的 */
function tsOf(m: LocalMessage): number {
  return parseChatTime(m.created_at)?.getTime() ?? Date.now()
}

function timeOf(m: LocalMessage) {
  return formatMessageTime(new Date(tsOf(m)))
}

/** 系统消息与撤回是居中的一行灰字，不参与合并，也不该让后面的消息「接着上一条」 */
function isPlain(m: LocalMessage) {
  return m.type !== 'system' && m.status !== 2
}

/**
 * 每条消息的排版信息
 *
 * - `divider`：与上一条隔了 5 分钟以上（或是第一条）就在上方插一个时间
 * - `continued`：同一个人 5 分钟内的连发，不再重复头像与名字
 *
 * 在 computed 里一次算完，而不是在模板里对每条调函数去看上一条——
 * 后者每次重渲染都是 O(n) 次函数调用，且两件事要各算一遍间隔
 */
const rows = computed(() =>
  messages.value.map((m, i) => {
    const prev = i > 0 ? messages.value[i - 1] : null
    // 取绝对值：顺序以 seq 为准，时间只是展示。服务器时钟回拨、导入的老数据
    // 都可能让后一条的时间更早，这时同样该插分隔，而不是被当成「连发」合并掉
    const gap = prev ? Math.abs(tsOf(m) - tsOf(prev)) : Infinity
    const divider = gap > GAP_MS ? timeOf(m) : ''

    const continued =
      !!prev && !divider && isPlain(m) && isPlain(prev) && prev.sender_id === m.sender_id

    return { m, divider, continued }
  }),
)

async function copyText(m: LocalMessage) {
  if (await writeClipboard(m.content)) ElMessage.success('已复制')
  else ElMessage.warning('浏览器不允许写剪贴板，请手动选中复制')
}
</script>

<template>
  <div class="chat">
    <!--
      最左侧图标栏：消息 / 通讯录
      不用顶部页签是因为两者是「两个地方」而不是「同一处的两个视图」——
      竖向图标栏把这层关系表达得更直接（钉钉桌面端同此），也给以后加入口
      （文件、日程之类）留了位置，横向页签加到第三个就挤了
    -->
    <nav class="chat__rail">
      <el-tooltip content="消息" placement="right" :show-after="300">
        <button
          type="button"
          class="rail__btn"
          :class="{ 'rail__btn--on': sideTab === 'chat' }"
          @click="switchTab('chat')"
        >
          <el-icon><ChatDotRound /></el-icon>
          <!-- 切到通讯录时仍看得见「有未读」，不用切回去确认 -->
          <span v-if="chatStore.total > 0" class="rail__dot" />
        </button>
      </el-tooltip>
      <el-tooltip content="通讯录" placement="right" :show-after="300">
        <button
          type="button"
          class="rail__btn"
          :class="{ 'rail__btn--on': sideTab === 'contact' }"
          @click="switchTab('contact')"
        >
          <!-- 通讯录用本子而不是 Postcard：后者像证件，而且「岗位管理」菜单已经在用它 -->
          <el-icon><Notebook /></el-icon>
        </button>
      </el-tooltip>
    </nav>

    <aside class="chat__side">
      <!-- 消息栏顶部：搜索 + 发起群聊。建群放这里而不是通讯录：
           「拉个群聊一下」是从聊天里冒出来的念头，不是在翻通讯录时 -->
      <div v-if="sideTab === 'chat'" class="chat__side-head chat__side-head--tools">
        <el-input v-model="convKeyword" placeholder="搜索" :prefix-icon="Search" clearable />
        <el-tooltip content="发起群聊" placement="bottom" :show-after="300">
          <button type="button" class="side__plus" @click="openGroupDialog">
            <el-icon><Plus /></el-icon>
          </button>
        </el-tooltip>
      </div>
      <div v-else class="chat__side-head">通讯录</div>

      <div v-show="sideTab === 'chat'" v-loading="loadingList" class="chat__list">
        <!-- 系统公告：固定在最上面，不参与置顶排序、没有右键菜单（它删不掉，也没有免打扰） -->
        <div
          v-if="showNoticeRow"
          class="conv conv--notice"
          :class="{ 'conv--active': noticeMode }"
          @click="openNoticePanel()"
          @contextmenu.prevent
        >
          <div class="conv__notice-icon"><el-icon><Bell /></el-icon></div>
          <div class="conv__body">
            <div class="conv__line">
              <span class="conv__name">系统公告</span>
              <span class="conv__time">{{ listTime(chatStore.notice.latest_at) }}</span>
            </div>
            <div class="conv__line">
              <span class="conv__brief">{{ chatStore.notice.latest_title || '暂无公告' }}</span>
              <span v-if="chatStore.notice.unread > 0" class="conv__badge">
                {{ chatStore.notice.unread > 99 ? '99+' : chatStore.notice.unread }}
              </span>
            </div>
          </div>
        </div>

        <div
          v-for="row in filteredConversations"
          :key="row.id"
          class="conv"
          :class="{
            'conv--active': conversation?.id === row.id,
            'conv--pinned': row.is_pinned,
            'conv--menu': menu.row?.id === row.id,
          }"
          @click="selectConversation(row)"
          @contextmenu.prevent="openMenu($event, row)"
        >
          <GroupAvatar :size="38" :src="row.avatar" :faces="row.avatar_members" :name="row.name" />

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
              <!-- 免打扰且没有未读时，行尾仍要看得出它是静音的，否则用户会纳闷为什么没提醒 -->
              <el-icon v-else-if="row.is_muted" class="conv__muted"><MuteNotification /></el-icon>
            </div>
          </div>

        </div>

        <EmptyState
          v-if="!loadingList && !conversations.length"
          scene="empty"
          description="还没有会话，去通讯录找个同事聊聊"
          :action="false"
          :size="70"
        />
        <EmptyState
          v-else-if="!loadingList && !filteredConversations.length"
          scene="search"
          :keyword="convKeyword"
          :action="false"
          :size="60"
        />
      </div>

      <!-- 通讯录：部门树 + 人员。点人在右栏看名片，名片上再「发送消息」 -->
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
          <div
            v-for="c in contacts"
            :key="c.id"
            class="contact"
            :class="{ 'contact--on': activeContact?.id === c.id }"
            @click="activeContact = c"
          >
            <el-avatar :size="36" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
            <div class="contact__body">
              <div class="contact__name">{{ c.real_name }}</div>
              <div class="contact__sub">{{ c.post_name || c.dept_name }}</div>
            </div>
          </div>

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

    <!--
      右栏（通讯录）：名片
      与消息区是两个 section 而不是同一个里的 v-if：消息区用 v-show 留着，
      切去通讯录看一眼再回来，滚动位置、草稿、@ 面板都还在
    -->
    <section v-if="sideTab === 'contact'" class="chat__main">
      <div v-if="activeContact" class="card">
        <el-avatar :size="96" :src="activeContact.avatar || undefined" class="card__avatar">
          {{ activeContact.real_name.slice(0, 1) }}
        </el-avatar>
        <div class="card__name">{{ activeContact.real_name }}</div>
        <div class="card__sub">{{ activeContact.post_name || '' }}</div>

        <!-- 手机号、邮箱无字段权限时后端给的是掩码，照样显示；空值整行不出 -->
        <dl class="card__info">
          <template v-if="activeContact.dept_name">
            <dt>部门</dt>
            <dd>{{ activeContact.dept_name }}</dd>
          </template>
          <template v-if="activeContact.phone">
            <dt>手机</dt>
            <dd>{{ activeContact.phone }}</dd>
          </template>
          <template v-if="activeContact.email">
            <dt>邮箱</dt>
            <dd>{{ activeContact.email }}</dd>
          </template>
        </dl>

        <!-- 自己的名片不给「发送消息」：服务端也不允许和自己开单聊 -->
        <el-button
          v-if="activeContact.id !== myId"
          type="primary"
          size="large"
          :icon="ChatDotRound"
          class="card__send"
          @click="openWith(activeContact)"
        >
          发送消息
        </el-button>
      </div>

      <EmptyState v-else scene="empty" description="在左侧选择一位同事，查看名片" :action="false" />
    </section>

    <!-- 右栏：消息区 -->
    <section
      v-show="sideTab === 'chat'"
      class="chat__main"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent
      @dragleave="onDragLeave"
      @drop.prevent="onDrop"
    >
      <!-- 拖文件进来时的提示层。pointer-events: none，否则它自己会触发 dragleave -->
      <div v-if="dragging" class="chat__drop">松开鼠标，发送给「{{ conversation?.name }}」</div>

      <template v-if="conversation">
        <header class="chat__header">
          <span class="chat__title">{{ conversation.name }}</span>
          <span v-if="isGroup" class="chat__count">{{ conversation.member_count }} 人</span>
          <el-tag v-if="!canSend" type="info" size="small">已离职</el-tag>
          <el-tag :type="connected ? 'success' : 'info'" size="small" effect="plain">
            {{ connected ? '已连接' : '连接中…' }}
          </el-tag>
          <div class="chat__header-ops">
            <el-button v-if="isGroup" text :icon="UserFilled" @click="openMembers">群成员</el-button>

            <el-popover placement="bottom-end" :width="260" trigger="click">
              <template #reference>
                <el-button text :icon="Setting" />
              </template>

              <div class="prefs">
                <div class="prefs__row">
                  <span>桌面通知</span>
                  <el-switch v-model="prefDesktop" @change="toggleDesktop" />
                </div>
                <div class="prefs__row">
                  <span>提示音</span>
                  <el-switch v-model="prefSound" @change="toggleSound" />
                </div>
                <div class="prefs__row">
                  <span>发送键</span>
                  <el-radio-group v-model="enterMode" size="small">
                    <el-radio-button value="enter">Enter</el-radio-button>
                    <el-radio-button value="ctrl">Ctrl+Enter</el-radio-button>
                  </el-radio-group>
                </div>
                <p class="prefs__hint">
                  只影响这台电脑上的这个浏览器；单个会话的免打扰在左栏右键设置。
                </p>
              </div>
            </el-popover>
          </div>
        </header>

        <div ref="listRef" v-loading="loadingMessages" class="chat__messages" @scroll.passive="onListScroll">
          <!-- 顶部状态：只在真的翻过页时说「没有更多」，短会话里挂这句是噪音 -->
          <div v-if="loadingOlder" class="msg__more"><el-icon class="is-loading"><Loading /></el-icon> 加载中…</div>
          <div v-else-if="!hasMore && messages.length > PAGE_SIZE" class="msg__more">没有更早的消息了</div>

          <template v-for="r in rows" :key="r.m.client_msg_id || r.m.id">
            <!-- 时间分隔：与上一条隔了 5 分钟以上才出现，格式按今天 / 今年 / 往年分档 -->
            <div v-if="r.divider" class="msg__divider">{{ r.divider }}</div>

            <div class="msg" :class="{ 'msg--mine': isMine(r.m), 'msg--continued': r.continued }">
              <!-- 系统消息（入群、改群名、解散）：与撤回同一种居中灰字。
                   它们本来就该按时间夹在聊天记录里，所以用的是同一套 seq -->
              <div v-if="r.m.type === 'system'" class="msg__recalled">{{ r.m.content }}</div>

              <!-- 撤回：整条变成一行灰字，不保留气泡。留着气泡会让人以为内容还在 -->
              <div v-else-if="r.m.status === 2" class="msg__recalled">
                {{
                  r.m.recalled_by !== r.m.sender_id
                    ? '一条消息已被群主撤回'
                    : isMine(r.m)
                      ? '你撤回了一条消息'
                      : `${r.m.sender_name} 撤回了一条消息`
                }}
              </div>

              <template v-else>
                <!-- 连发的消息不再重复头像，但留出同样的宽度，气泡才对得齐 -->
                <div v-if="r.continued" class="msg__avatar-gap" />
                <el-avatar
                  v-else
                  :size="32"
                  :src="avatarOf(r.m)"
                  class="msg__avatar"
                  @click="openPeek($event, r.m)"
                >
                  {{ r.m.sender_name.slice(0, 1) }}
                </el-avatar>

                <div class="msg__body">
                  <div v-if="!r.continued" class="msg__meta">
                    <span>{{ isMine(r.m) ? '我' : r.m.sender_name }}</span>
                    <span class="msg__time">{{ timeOf(r.m) }}</span>
                  </div>

                  <!--
                    内容 + 操作。操作（复制、撤回）绝对定位在内容旁边、hover 才出现，
                    不占排版：以前撤回按钮放在时间那一行里，隐藏时仍占宽度，
                    把短消息的气泡撑出一大截空白；而连发的消息压根没有时间那一行
                  -->
                  <div class="msg__content">
                    <!-- 图片：直接铺在气泡外，套气泡会多一圈背景色 -->
                    <el-image
                      v-if="r.m.type === 'image' && r.m.extra"
                      class="msg__image"
                      :src="r.m.extra.url"
                      :preview-src-list="[r.m.extra.url]"
                      :initial-index="0"
                      fit="cover"
                      preview-teleported
                      :style="imageStyle(r.m)"
                    />

                    <!-- 文件：可点的卡片，点了下载 -->
                    <a
                      v-else-if="r.m.type === 'file' && r.m.extra"
                      class="msg__file"
                      :href="r.m.extra.url"
                      :download="r.m.extra.name"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <el-icon class="msg__file-icon"><Document /></el-icon>
                      <span class="msg__file-body">
                        <span class="msg__file-name">{{ r.m.extra.name }}</span>
                        <span class="msg__file-size">{{ humanSize(r.m.extra.size) }}</span>
                      </span>
                    </a>

                    <div
                      v-else
                      class="msg__bubble"
                      :class="{
                        'msg__bubble--failed': r.m._state === 'failed',
                        'msg__bubble--at': mentionsMe(r.m),
                      }"
                    >
                      {{ r.m.content }}
                    </div>

                    <div v-if="!r.m._state" class="msg__ops">
                      <button v-if="r.m.type === 'text'" type="button" class="msg__op" @click="copyText(r.m)">复制</button>
                      <button v-if="canRecall(r.m)" type="button" class="msg__op" @click="recall(r.m)">撤回</button>
                    </div>
                  </div>

                  <div v-if="r.m._state || readHintOf(r.m)" class="msg__foot">
                    <span v-if="r.m._state === 'sending'" class="msg__state">发送中…</span>
                    <template v-else-if="r.m._state === 'failed'">
                      <span class="msg__state msg__state--failed">发送失败</span>
                      <el-button text size="small" :icon="RefreshLeft" @click="resend(r.m)">重发</el-button>
                    </template>
                    <span v-else class="msg__state">{{ readHintOf(r.m) }}</span>
                  </div>
                </div>
              </template>
            </div>
          </template>

          <EmptyState
            v-if="!loadingMessages && !messages.length"
            scene="empty"
            :description="canSend ? '还没有消息，说点什么吧' : '没有聊天记录'"
            :action="false"
          />
        </div>

        <!--
          输入区：输入框与工具栏收进同一个圆角框，发送键在框内右下。
          工具栏放在输入框下面而不是上面：眼睛从打字的地方往下一扫就是「发」，
          而且框的上沿要留给 @ 面板往上弹
        -->
        <!-- 单聊对方离职：历史照看，输入区换成一句说明。服务端同样会拦 -->
        <footer v-if="!canSend" class="chat__composer">
          <div class="chat__readonly">对方已离职，无法再发送消息，历史记录仍可查看</div>
        </footer>

        <footer v-else class="chat__composer">
          <div ref="composerRef" class="composer">
            <!-- @ 面板：浮在输入框上方。用 absolute 而不是 el-popover——
                 popover 的定位跟着触发元素走，而这里要贴着输入框顶边 -->
            <div v-if="atVisible && atCandidates.length" class="atpanel">
              <button v-if="isOwner" type="button" class="atpanel__item" @click="pickAtAll">
                <span class="atpanel__all">@所有人</span>
              </button>
              <button
                v-for="m in atCandidates"
                :key="m.user_id"
                type="button"
                class="atpanel__item"
                @click="pickMention(m.real_name, m.user_id)"
              >
                <el-avatar :size="22" :src="m.avatar || undefined">{{ m.real_name.slice(0, 1) }}</el-avatar>
                <span>{{ m.real_name }}</span>
              </button>
            </div>

            <el-input
              ref="textareaRef"
              v-model="draft"
              type="textarea"
              :autosize="{ minRows: 2, maxRows: 6 }"
              resize="none"
              placeholder="请输入消息"
              class="composer__input"
              @input="onDraftInput"
              @keydown="onComposerKeydown"
              @paste="onPaste"
            />

            <div class="composer__bar">
              <el-popover
                v-model:visible="emojiVisible"
                placement="top-start"
                :width="344"
                :offset="emojiOffset"
                trigger="click"
              >
                <template #reference>
                  <button type="button" class="composer__tool" title="表情" @mousedown="measureEmojiOffset">
                    <!-- EP 图标集里没有笑脸，补一个与旁边图标同粗细的描边款 -->
                    <svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"
                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="12" cy="12" r="9" />
                      <path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8" />
                      <circle cx="9" cy="10" r=".6" fill="currentColor" />
                      <circle cx="15" cy="10" r=".6" fill="currentColor" />
                    </svg>
                  </button>
                </template>

                <div class="emoji">
                  <button
                    v-for="e in EMOJIS"
                    :key="e"
                    type="button"
                    class="emoji__item"
                    @click="insertEmoji(e)"
                  >
                    {{ e }}
                  </button>
                </div>
              </el-popover>
              <el-tooltip v-if="isGroup" content="@ 提醒" placement="top" :show-after="300">
                <button type="button" class="composer__tool composer__tool--at" @click="insertAt">@</button>
              </el-tooltip>
              <el-tooltip content="图片（也可直接粘贴）" placement="top" :show-after="300">
                <button type="button" class="composer__tool" :disabled="uploading" @click="pickImage">
                  <el-icon><Picture /></el-icon>
                </button>
              </el-tooltip>
              <el-tooltip content="文件" placement="top" :show-after="300">
                <button type="button" class="composer__tool" :disabled="uploading" @click="pickFile">
                  <el-icon><FolderOpened /></el-icon>
                </button>
              </el-tooltip>
              <span v-if="uploading" class="composer__uploading">
                <el-icon class="is-loading"><Loading /></el-icon>上传中
              </span>

              <!-- 快到上限才出现计数，平时不占地方；超了变红且发送键置灰 -->
              <span
                v-if="draftLen > MAX_LEN - 500"
                class="composer__count"
                :class="{ 'composer__count--over': tooLong }"
              >
                {{ draftLen }} / {{ MAX_LEN }}
              </span>
              <span class="composer__hint">{{ sendHint }}</span>
              <!-- 空草稿时置灰而不是隐藏：按钮位置不动，用户知道它在哪 -->
              <el-button
                type="primary"
                round
                class="composer__send"
                :disabled="!draft.trim() || tooLong"
                @click="send"
              >
                发送
              </el-button>
            </div>
          </div>

          <!-- 原生 input 藏起来，用按钮触发：el-upload 会带进它自己的列表与样式，
               而这里只需要「选一个文件」这一件事 -->
          <input
            ref="imageInput"
            type="file"
            accept="image/*"
            class="chat__file-input"
            @change="onFilePicked($event, 'image')"
          />
          <input
            ref="fileInput"
            type="file"
            class="chat__file-input"
            @change="onFilePicked($event, 'file')"
          />
        </footer>
      </template>

      <!-- 系统公告面板：列表 → 详情。正文在入库前已经白名单净化，这里直接 v-html（同顶栏铃铛） -->
      <template v-else-if="noticeMode">
        <header class="chat__header">
          <el-button v-if="noticeDetail" text :icon="ArrowLeft" @click="noticeDetail = null">返回</el-button>
          <span class="chat__title">系统公告</span>
          <div class="chat__header-ops">
            <el-button v-if="!noticeDetail" text :disabled="!chatStore.notice.unread" @click="markAllNoticesRead">
              全部已读
            </el-button>
          </div>
        </header>

        <div v-if="noticeDetail" class="notice-detail">
          <h2 class="notice-detail__title">{{ noticeDetail.title }}</h2>
          <div class="notice-detail__meta">
            <span>{{ noticeDetail.publisher_name }}</span>
            <span>{{ noticeDetail.published_at ? formatMessageTime(parseChatTime(noticeDetail.published_at)!) : '' }}</span>
          </div>
          <div class="rich-content" v-html="noticeDetail.content" />
        </div>

        <div v-else v-loading="noticeLoading && !notices.length" class="notice-list" @scroll.passive="onNoticeScroll">
          <button
            v-for="n in notices"
            :key="n.id"
            type="button"
            class="notice-item"
            :class="{ 'notice-item--unread': !n.is_read }"
            @click="openNotice(n.id)"
          >
            <span class="notice-item__dot" />
            <span class="notice-item__body">
              <span class="notice-item__title">{{ n.title }}</span>
              <span class="notice-item__summary">{{ n.summary }}</span>
            </span>
            <span class="notice-item__time">{{ listTime(n.published_at) }}</span>
          </button>

          <div v-if="noticeLoading && notices.length" class="msg__more">加载中…</div>
          <EmptyState
            v-if="!noticeLoading && !notices.length"
            scene="empty"
            description="还没有公告"
            :action="false"
          />
        </div>
      </template>

      <EmptyState v-else scene="empty" description="选择一个会话开始聊天" :action="false" />
    </section>

    <!--
      会话右键菜单
      挂到 body 上而不是跟着行渲染：行在可滚动容器里，overflow 会把菜单裁掉；
      Teleport 出去之后用 fixed 定位，位置由鼠标坐标决定
    -->
    <Teleport to="body">
      <ul
        v-if="menu.row"
        ref="menuRef"
        class="ctxmenu"
        :style="{ left: `${menu.x}px`, top: `${menu.y}px` }"
        @contextmenu.prevent
      >
        <li class="ctxmenu__item" @click="onMenu('pin')">
          <el-icon><Top /></el-icon>{{ menu.row.is_pinned ? '取消置顶' : '置顶聊天' }}
        </li>
        <li class="ctxmenu__item" @click="onMenu('mute')">
          <el-icon><component :is="menu.row.is_muted ? Bell : MuteNotification" /></el-icon>
          {{ menu.row.is_muted ? '取消免打扰' : '消息免打扰' }}
        </li>
        <li class="ctxmenu__sep" />
        <!-- 单聊是「删除聊天」（只从我的列表移除）；群聊是退群或解散——
             对群而言「只从列表移除」没有意义，下一条消息又会把它拉回来 -->
        <li v-if="menu.row.type === 1" class="ctxmenu__item ctxmenu__item--danger" @click="onMenu('delete')">
          <el-icon><Delete /></el-icon>删除聊天
        </li>
        <li
          v-else-if="menu.row.owner_id === myId"
          class="ctxmenu__item ctxmenu__item--danger"
          @click="onMenu('dissolve')"
        >
          <el-icon><Remove /></el-icon>解散群聊
        </li>
        <li v-else class="ctxmenu__item ctxmenu__item--danger" @click="onMenu('quit')">
          <el-icon><Remove /></el-icon>退出群聊
        </li>
      </ul>
    </Teleport>

    <!--
      发起群聊：左边搜人勾选，右边是已选。
      用弹窗不用抽屉：这是一次性的「选完就走」，不需要边看边操作
    -->
    <el-dialog v-model="groupDialog" title="发起群聊" width="620px" append-to-body>
      <div class="gpick">
        <div class="gpick__left">
          <el-input v-model="groupKeyword" placeholder="搜索同事" :prefix-icon="Search" clearable />
          <div v-loading="groupLoading" class="gpick__list">
            <label v-for="c in groupCandidates" :key="c.id" class="contact">
              <el-checkbox :model-value="isGroupPicked(c.id)" @change="toggleGroupPick(c)" />
              <el-avatar :size="32" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
              <div class="contact__body">
                <div class="contact__name">{{ c.real_name }}</div>
                <div class="contact__sub">{{ c.post_name || c.dept_name }}</div>
              </div>
            </label>
            <EmptyState
              v-if="!groupLoading && !groupCandidates.length"
              scene="search"
              :keyword="groupKeyword"
              :action="false"
              :size="60"
            />
          </div>
        </div>

        <div class="gpick__right">
          <div class="gpick__count">已选 {{ groupPicked.length }} 人<span>（至少 2 人）</span></div>
          <div class="gpick__list">
            <div v-for="c in groupPicked" :key="c.id" class="contact">
              <el-avatar :size="32" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
              <div class="contact__body">
                <div class="contact__name">{{ c.real_name }}</div>
              </div>
              <el-button text size="small" :icon="Delete" @click="toggleGroupPick(c)" />
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <el-button @click="groupDialog = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="groupPicked.length < 2"
          :loading="groupCreating"
          @click="submitGroup"
        >
          创建{{ groupPicked.length ? `（${groupPicked.length + 1} 人）` : '' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 头像名片：点消息头像弹出，做法同右键菜单（Teleport + fixed） -->
    <Teleport to="body">
      <div
        v-if="peek.visible"
        ref="peekRef"
        class="peek"
        :style="{ left: `${peek.x}px`, top: `${peek.y}px` }"
      >
        <div class="peek__head">
          <el-avatar :size="52" :src="peek.person?.avatar || undefined" class="peek__avatar">
            {{ (peek.person?.real_name || peek.fallbackName).slice(0, 1) }}
          </el-avatar>
          <div class="peek__who">
            <div class="peek__name">{{ peek.person?.real_name || peek.fallbackName }}</div>
            <div class="peek__sub">{{ peek.person?.post_name || '' }}</div>
          </div>
        </div>

        <div v-if="peek.loading" class="peek__loading">
          <el-icon class="is-loading"><Loading /></el-icon>
        </div>
        <dl v-else-if="peek.person" class="peek__info">
          <template v-if="peek.person.dept_name">
            <dt>部门</dt>
            <dd>{{ peek.person.dept_name }}</dd>
          </template>
          <template v-if="peek.person.phone">
            <dt>手机</dt>
            <dd>{{ peek.person.phone }}</dd>
          </template>
          <template v-if="peek.person.email">
            <dt>邮箱</dt>
            <dd>{{ peek.person.email }}</dd>
          </template>
        </dl>
        <div v-else class="peek__empty">查看不到该成员的资料</div>

        <el-button v-if="peekCanChat" type="primary" class="peek__send" :icon="ChatDotRound" @click="chatFromPeek">
          发消息
        </el-button>
      </div>
    </Teleport>

    <!-- 群成员抽屉。用抽屉不用弹窗：成员列表可能几十行，
         弹窗撑不下就要在内部再套一层滚动（P2 定的规范）

         ⚠️ `#footer` 必须是 el-drawer 的**直接子元素**。把它包进
         `<template v-if>` 里的话编译器会直接崩（Cannot read properties of
         undefined (reading 'type')），而报错信息完全指不到这一点。
         所以两种模式的分支写在插槽**内部**，不是套在插槽外面 -->
    <el-drawer v-model="memberDrawer" :title="addMode ? '添加成员' : '群成员'" size="360px">
      <el-input v-if="addMode" v-model="keyword" placeholder="搜索同事" clearable size="small" />

      <!-- 群资料：头像、群名。群主点头像更换、点铅笔改名 -->
      <div v-if="!addMode && conversation" class="gprofile">
        <div
          class="gprofile__avatar"
          :class="{ 'is-editable': isOwner }"
          :title="isOwner ? '更换群头像' : undefined"
          @click="pickGroupAvatar"
        >
          <GroupAvatar
            :size="64"
            :src="conversation.avatar"
            :faces="conversation.avatar_members"
            :name="conversation.name"
          />
          <span v-if="isOwner" class="gprofile__mask">{{ uploadingGroupAvatar ? '上传中' : '更换' }}</span>
        </div>
        <div class="gprofile__info">
          <div class="gprofile__name">
            <span class="gprofile__text">{{ conversation.name }}</span>
            <el-button v-if="isOwner" link :icon="Edit" aria-label="修改群名" @click="renameGroup" />
          </div>
          <div class="gprofile__sub">{{ conversation.member_count }} 人</div>
          <el-button v-if="isOwner && conversation.avatar" link type="primary" size="small" @click="resetGroupAvatar">
            恢复默认头像
          </el-button>
        </div>
        <input
          ref="groupAvatarInput"
          type="file"
          accept="image/*"
          class="chat__file-input"
          @change="onGroupAvatarPicked"
        />
      </div>

      <div v-loading="addMode ? loadingContacts : loadingMembers" class="members">
        <template v-if="!addMode">
          <div v-for="m in members" :key="m.user_id" class="member">
            <el-avatar :size="34" :src="m.avatar || undefined">{{ m.real_name.slice(0, 1) }}</el-avatar>
            <span class="member__name">{{ m.real_name }}</span>
            <el-tag v-if="m.role === 1" type="warning" size="small" effect="plain">群主</el-tag>
            <!-- 群主自己不显示移出按钮：移出群主等于让群没人管，服务端也会拒 -->
            <el-button
              v-if="isOwner && m.user_id !== myId"
              text
              size="small"
              :icon="Delete"
              @click="kick(m)"
            />
          </div>
        </template>

        <template v-else>
          <label v-for="c in addableContacts" :key="c.id" class="member member--pick">
            <el-checkbox :model-value="picked.includes(c.id)" @change="togglePick(c.id)" />
            <el-avatar :size="34" :src="c.avatar || undefined">{{ c.real_name.slice(0, 1) }}</el-avatar>
            <span class="member__name">{{ c.real_name }}</span>
          </label>
        </template>
      </div>

      <template #footer>
        <div v-if="!addMode" class="members__foot">
          <el-button v-if="isOwner" type="primary" @click="startAdd">添加成员</el-button>
          <el-button v-if="isOwner" type="danger" plain @click="doDissolve()">解散群聊</el-button>
          <el-button v-else type="danger" plain @click="quitGroup()">退出群聊</el-button>
        </div>

        <div v-else class="members__foot">
          <el-button @click="addMode = false">返回</el-button>
          <el-button type="primary" :disabled="!picked.length" @click="submitAdd">
            添加{{ picked.length ? ` ${picked.length} 人` : '' }}
          </el-button>
        </div>
      </template>
    </el-drawer>

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

/* 最左侧图标栏：窄、底色比面板深一档，一眼看出它是「导航」而不是内容 */
.chat__rail {
  display: flex;
  flex: 0 0 60px;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  width: 60px;
  padding-top: 14px;
  border-right: 1px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-lighter);
}

.rail__btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  font-size: 20px;
  color: var(--el-text-color-secondary);
  cursor: pointer;
}

.rail__btn:hover {
  background: var(--el-fill-color);
  color: var(--el-text-color-primary);
}

.rail__btn--on,
.rail__btn--on:hover {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

/* 描一圈与栏同色的边，红点压在图标上也分得清 */
.rail__dot {
  position: absolute;
  top: 7px;
  right: 7px;
  width: 8px;
  height: 8px;
  border: 2px solid var(--el-fill-color-lighter);
  border-radius: 50%;
  background: var(--el-color-danger);
  box-sizing: content-box;
}

.chat__side-head {
  padding: 13px 16px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.chat__side-head--tools {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 10px 12px;
}

/* 圆形加号：与搜索框同高，钉钉/企业微信都是这个位置 */
.side__plus {
  display: flex;
  flex: 0 0 32px;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: 50%;
  background: var(--el-fill-color-light);
  font-size: 16px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.side__plus:hover {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
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

/* 系统公告的图标头像：与会话头像同尺寸，底色用主色浅档，一眼看出它不是一个人 */
.conv__notice-icon {
  display: flex;
  flex: 0 0 38px;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: var(--el-color-warning-light-8);
  font-size: 19px;
  color: var(--el-color-warning);
}

.notice-list {
  flex: 1;
  overflow-y: auto;
  padding: 8px 12px;
}

.notice-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  width: 100%;
  padding: 12px;
  border: 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
  background: transparent;
  text-align: left;
  color: inherit;
  cursor: pointer;
}

.notice-item:hover {
  background: var(--el-fill-color-light);
}

/* 未读才有圆点；已读保留占位，标题才对得齐 */
.notice-item__dot {
  flex: 0 0 8px;
  width: 8px;
  height: 8px;
  margin-top: 6px;
  border-radius: 50%;
}

.notice-item--unread .notice-item__dot {
  background: var(--el-color-danger);
}

.notice-item__body {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.notice-item__title {
  font-size: 14px;
  color: var(--el-text-color-regular);
}

.notice-item--unread .notice-item__title {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.notice-item__summary {
  overflow: hidden;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notice-item__time {
  flex: none;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.notice-detail {
  flex: 1;
  overflow-y: auto;
  padding: 20px 28px;
}

.notice-detail__title {
  margin: 0 0 8px;
  font-size: 20px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.notice-detail__meta {
  display: flex;
  gap: 12px;
  margin-bottom: 18px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  font-size: 12px;
  color: var(--el-text-color-secondary);
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

/* 右键菜单打开时，被操作的那一行保持高亮——否则菜单弹出后看不出是对哪一行 */
.conv--menu {
  background: var(--el-fill-color-light);
}

.conv__muted {
  flex: 0 0 auto;
  font-size: 14px;
  color: var(--el-text-color-placeholder);
}

/* 右键菜单：Teleport 到 body，scoped 样式照样生效（元素仍由本组件渲染） */
.ctxmenu {
  position: fixed;
  z-index: 3000;
  min-width: 148px;
  margin: 0;
  padding: 6px 0;
  list-style: none;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color-overlay);
  box-shadow: var(--el-box-shadow-light);
}

.ctxmenu__item {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 8px 16px;
  font-size: 14px;
  color: var(--el-text-color-primary);
  cursor: pointer;
  white-space: nowrap;
}

.ctxmenu__item:hover {
  background: var(--el-fill-color-light);
}

.ctxmenu__item .el-icon {
  font-size: 16px;
}

.ctxmenu__item--danger {
  color: var(--el-color-danger);
}

.ctxmenu__sep {
  height: 1px;
  margin: 4px 0;
  background: var(--el-border-color-lighter);
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

.contact--on,
.contact--on:hover {
  background: var(--el-color-primary-light-9);
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

.chat__count {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.chat__header-ops {
  margin-left: auto;
}

/* 群成员抽屉顶部的群资料 */
.gprofile {
  display: flex;
  gap: 14px;
  align-items: center;
  padding: 4px 0 16px;
  margin-bottom: 8px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.gprofile__avatar {
  position: relative;
  flex: none;
  border-radius: 50%;
  overflow: hidden;
}

.gprofile__avatar.is-editable {
  cursor: pointer;
}

/* 「更换」只在悬停时浮出，平时不挡头像 */
.gprofile__mask {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--el-overlay-color-lighter);
  font-size: 12px;
  color: var(--el-color-white);
  opacity: 0;
  transition: opacity 0.15s;
}

.gprofile__avatar.is-editable:hover .gprofile__mask {
  opacity: 1;
}

.gprofile__info {
  min-width: 0;
}

.gprofile__name {
  display: flex;
  gap: 4px;
  align-items: center;
}

.gprofile__text {
  overflow: hidden;
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.gprofile__sub {
  margin: 2px 0 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.members {
  padding: 4px 0;
}

.prefs__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.prefs__hint {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}

.member {
  position: relative;
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 8px 4px;
  border-radius: 6px;
}

.member--pick {
  cursor: pointer;
}

.member--pick:hover {
  background: var(--el-fill-color-light);
}

.member__name {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  color: var(--el-text-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.members__foot {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

/*
 * 右栏整体一个底色：标题栏、消息区、输入区连成一片，只用细线分隔。
 * 原来是白标题栏 + 灰消息区 + 白输入区三段，色块互相打架
 */
.chat__main {
  position: relative;
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  background: var(--el-fill-color-lighter);
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
  cursor: pointer;
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

/* 自己的气泡用浅蓝底深字：满版品牌蓝在浅底上太跳，一屏都是自己消息时尤其刺眼 */
.msg--mine .msg__bubble {
  background: var(--el-color-primary-light-8);
  border-color: var(--el-color-primary-light-7);
  color: var(--el-text-color-primary);
}

/* 两级选择器：自己的气泡带边框色，一级的话失败的红框会被它盖掉 */
.msg .msg__bubble--failed {
  border-color: var(--el-color-danger);
}

.msg__foot {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 3px;
  font-size: 12px;
}

.msg--mine .msg__foot {
  justify-content: flex-end;
}

.msg__state {
  color: var(--el-text-color-placeholder);
}

.msg__state--failed {
  color: var(--el-color-danger);
}

/* 撤回：整行居中的灰字，不保留气泡——留着气泡会让人以为内容还在 */
.msg__recalled {
  flex: 1;
  text-align: center;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

/* 连发的消息贴近上一条：14px 的常规间距减到 4px，看得出是「一口气说的」 */
.msg--continued {
  margin-top: -10px;
}

.msg__avatar-gap {
  flex: 0 0 32px;
}

.msg__divider {
  margin: 4px 0 14px;
  text-align: center;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.msg__more {
  display: flex;
  gap: 4px;
  align-items: center;
  justify-content: center;
  padding: 0 0 12px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

/* 内容块按内容收缩，操作按钮以它为定位参照 */
.msg__content {
  position: relative;
  width: fit-content;
  max-width: 100%;
}

.msg--mine .msg__content {
  margin-left: auto;
}

/* 操作：hover 才出现，挂在内容外侧（别人的在右、自己的在左），不占排版 */
.msg__ops {
  position: absolute;
  bottom: 0;
  left: calc(100% + 6px);
  display: none;
  gap: 2px;
  white-space: nowrap;
}

.msg--mine .msg__ops {
  left: auto;
  right: calc(100% + 6px);
}

.msg:hover .msg__ops {
  display: flex;
}

.msg__op {
  padding: 2px 6px;
  border: 0;
  border-radius: 4px;
  background: transparent;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  cursor: pointer;
}

.msg__op:hover {
  background: var(--el-fill-color);
  color: var(--el-color-primary);
}

.msg__image {
  display: block;
  border-radius: 8px;
  background: var(--el-fill-color-light);
  cursor: zoom-in;
}

.msg__file {
  display: flex;
  gap: 10px;
  align-items: center;
  max-width: 260px;
  padding: 10px 12px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color);
  text-decoration: none;
  color: inherit;
}

.msg__file:hover {
  border-color: var(--el-color-primary);
}

.msg__file-icon {
  font-size: 24px;
  color: var(--el-color-primary);
}

.msg__file-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.msg__file-name {
  font-size: 13px;
  color: var(--el-text-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.msg__file-size {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.chat__composer {
  padding: 4px 16px 14px;
}

/* 输入框与工具栏同在一个圆角框里，聚焦时整框描主色 */
.composer {
  position: relative;
  border: 1px solid var(--el-border-color-light);
  border-radius: 10px;
  background: var(--el-bg-color);
  transition: border-color 0.2s;
}

.composer:focus-within {
  border-color: var(--el-color-primary);
}

/* 去掉 el-input 自带的边框与阴影，框由外层 .composer 负责 */
.composer__input :deep(.el-textarea__inner) {
  padding: 10px 12px 4px;
  border: 0;
  box-shadow: none;
  background: transparent;
}

.composer__bar {
  display: flex;
  gap: 2px;
  align-items: center;
  padding: 4px 8px 8px;
}

.composer__tool {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  font-size: 18px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.composer__tool:hover:not(:disabled) {
  background: var(--el-fill-color-light);
  color: var(--el-color-primary);
}

.composer__tool:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.composer__tool svg {
  width: 19px;
  height: 19px;
}

.composer__tool--at {
  font-size: 17px;
  font-weight: 600;
}

.composer__uploading {
  display: flex;
  gap: 4px;
  align-items: center;
  margin-left: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.composer__count {
  margin-left: auto;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.composer__count--over {
  color: var(--el-color-danger);
}

/* 有计数时提示紧跟在计数后面，没有时由它自己顶到右边 */
.composer__count + .composer__hint {
  margin-left: 10px;
}

.composer__hint {
  margin-left: auto;
  margin-right: 10px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.composer__send {
  min-width: 72px;
}

/* @ 面板浮在输入框上方，不挤占布局 */
.atpanel {
  position: absolute;
  left: 0;
  right: 0;
  bottom: calc(100% + 6px);
  max-height: 200px;
  overflow-y: auto;
  padding: 4px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color-overlay);
  box-shadow: var(--el-box-shadow-light);
  z-index: 5;
}

.atpanel__item {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  padding: 6px 8px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--el-text-color-primary);
  font-size: 13px;
  text-align: left;
  cursor: pointer;
}

.atpanel__item:hover {
  background: var(--el-fill-color-light);
}

.atpanel__all {
  color: var(--el-color-primary);
}

/* 被 @ 到的消息加一圈强调边框，别人的气泡是白底所以看得出来 */
.msg__bubble--at {
  border-color: var(--el-color-warning);
  box-shadow: 0 0 0 1px var(--el-color-warning) inset;
}

.chat__file-input {
  display: none;
}

.chat__readonly {
  padding: 18px 0;
  border: 1px dashed var(--el-border-color);
  border-radius: 10px;
  text-align: center;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

/* 拖拽提示层盖满右栏；不接收指针事件，否则它自己会触发 dragleave 导致闪烁 */
.chat__drop {
  position: absolute;
  inset: 8px;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px dashed var(--el-color-primary);
  border-radius: 10px;
  background: color-mix(in srgb, var(--el-color-primary-light-9) 88%, transparent);
  font-size: 15px;
  color: var(--el-color-primary);
  pointer-events: none;
}

/* 头像名片（挂在 body 上，scoped 仍生效：它是本组件模板的一部分） */
.peek {
  position: fixed;
  z-index: 3000;
  width: 280px;
  padding: 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  background: var(--el-bg-color-overlay);
  box-shadow: var(--el-box-shadow-light);
}

.peek__head {
  display: flex;
  gap: 12px;
  align-items: center;
}

.peek__avatar {
  --keel-avatar-size: 52px;
  flex: 0 0 auto;
}

.peek__who {
  min-width: 0;
}

.peek__name {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.peek__sub {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.peek__info {
  display: grid;
  grid-template-columns: 36px 1fr;
  gap: 8px 10px;
  margin: 14px 0 0;
  padding-top: 12px;
  border-top: 1px solid var(--el-border-color-lighter);
  font-size: 13px;
}

.peek__info:empty {
  display: none;
}

.peek__info dt {
  color: var(--el-text-color-secondary);
}

.peek__info dd {
  margin: 0;
  color: var(--el-text-color-primary);
  word-break: break-all;
}

.peek__loading,
.peek__empty {
  margin-top: 14px;
  font-size: 13px;
  text-align: center;
  color: var(--el-text-color-secondary);
}

.peek__send {
  width: 100%;
  margin-top: 16px;
}

/* 表情面板。popover 内容虽然挂到 body 上，但仍是本组件的模板，scoped 样式照样生效 */
.emoji {
  display: grid;
  grid-template-columns: repeat(10, 1fr);
  gap: 2px;
  max-height: 232px;
  overflow-y: auto;
}

.emoji__item {
  height: 30px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  font-size: 20px;
  line-height: 30px;
  cursor: pointer;
}

.emoji__item:hover {
  background: var(--el-fill-color-light);
}

/* 通讯录名片：居中一列，钉钉桌面端的样子 */
.card {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  max-width: 360px;
  margin: 72px auto 0;
  padding: 0 16px;
}

/* 首字字号按全站约定跟着尺寸走（styles/index.css 的 --keel-avatar-size） */
.card__avatar {
  --keel-avatar-size: 96px;
}

.card__name {
  margin-top: 16px;
  font-size: 22px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.card__sub {
  min-height: 20px;
  margin-top: 4px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.card__info {
  display: grid;
  grid-template-columns: 48px 1fr;
  gap: 10px 12px;
  width: 100%;
  margin: 24px 0 28px;
  padding: 16px 0;
  border-top: 1px solid var(--el-border-color-lighter);
  border-bottom: 1px solid var(--el-border-color-lighter);
  font-size: 14px;
}

.card__info:empty {
  display: none;
}

.card__info dt {
  color: var(--el-text-color-secondary);
}

.card__info dd {
  margin: 0;
  color: var(--el-text-color-primary);
  word-break: break-all;
}

.card__send {
  width: 200px;
}

/* 发起群聊弹窗：左选右看 */
.gpick {
  display: flex;
  height: 400px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
}

.gpick__left,
.gpick__right {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
  padding: 10px;
}

.gpick__left {
  border-right: 1px solid var(--el-border-color-lighter);
}

.gpick__list {
  flex: 1;
  margin-top: 8px;
  overflow-y: auto;
}

.gpick__count {
  padding: 6px 2px;
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.gpick__count span {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
