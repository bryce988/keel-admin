import { acceptHMRUpdate, defineStore } from 'pinia'
import { getUnread, type ChatMessage, type ChatNoticeEntry } from '@/api/chat'
import { chatSocket } from '@/utils/chatSocket'
import { notifyNewMessage } from '@/utils/chatNotify'
import { useUserStore } from '@/stores/user'
import router from '@/router'

/**
 * 聊天未读状态 —— **全站唯一的数据源**
 *
 * 顶栏红点、会话列表角标、浏览器标题三处都从这里派生，
 * 任何一处都不允许自己算（产品验收明确要求三者一致，chat-prd.md §9）。
 *
 * ## 为什么不轮询
 *
 * 与公告铃铛（`stores/notice.ts`）相反：那边一天几条、延迟一分钟无所谓，
 * 所以轮询最省事。聊天有现成的长连接，推送一到就更新，**不需要定时器**。
 * 只在两个时机主动拉一次全量：
 * - 首次进入（拿到积压的未读）
 * - 长连接 ready（含每次重连——断线期间的消息推送不会重发）
 *
 * 这也意味着红点的准确性不依赖轮询频率，而依赖「重连后必然对齐」这条。
 */
export const useChatStore = defineStore('chat', {
  state: () => ({
    /** 红点上的数字：不含免打扰的会话，含未读公告 */
    total: 0,
    /** 消息列表顶部「系统公告」那一行的数据，由服务端的未读汇总一并给出 */
    notice: { unread: 0, latest_id: 0, latest_title: '', latest_at: null } as ChatNoticeEntry,
    /** 有未读的会话数，含免打扰 */
    conversations: 0,
    hasAt: false,
    /** 当前正在看的会话——它的新消息不该计入红点，用户就在看着 */
    activeConvId: 0,
    bound: false,
  }),

  actions: {
    /** 拉一次全量。未登录不拉，免得在登录页打 401 */
    async refresh() {
      if (!useUserStore().token) return

      try {
        const d = await getUnread()
        this.total = d.total
        this.notice = d.notice
        this.conversations = d.conversations
        this.hasAt = d.has_at
        this.syncTitle()
      } catch {
        // 红点拉失败不打扰用户，下次推送或重连时会再对一次
      }
    },

    /**
     * 挂上长连接的监听
     *
     * 幂等：`bound` 挡住重复绑定。布局组件和聊天页都会调它，
     * 不挡的话切一次页面就多一份监听，红点会翻倍跳。
     */
    bind() {
      if (this.bound) return
      this.bound = true

      // 每次重连都会再来一次 ready——断线期间的消息靠这一步补进红点
      chatSocket.on('ready', () => void this.refresh())

      chatSocket.on('message.new', (data) => {
        const msg = data as ChatMessage
        const me = Number(useUserStore().profile?.user.id ?? 0)

        // 自己发的不算未读；正在看的会话也不算——用户就在看着它
        if (msg.sender_id === me || msg.conv_id === this.activeConvId) return

        // 这里只做乐观 +1，不重新拉全量：一次对话几十条消息，
        // 每条都拉一次全量等于把长连接的好处又还回去了。
        // 免打扰的会话会让这个数字偏大，下次 refresh 会纠正回来
        this.total += 1
        this.syncTitle()

        /*
         * 提醒放在 store 里而不是聊天页里
         *
         * 聊天页只有打开时才挂监听，而提醒要在**任何页面**上都work——
         * 用户在看日志、在填表单时来了消息，同样该被提醒到。
         * 这和红点要全站一致是同一个理由。
         *
         * ⚠️ 免打扰的会话不提醒。这里拿不到会话的 is_muted（推送里没有），
         * 所以退而求其次：只有会计入 total 的消息才提醒——而 refresh 会把
         * 免打扰的数字纠正回去，代价是免打扰的会话可能会多响一声。
         * 要彻底做对，得让推送带上接收方的 is_muted，那会让一条广播
         * 因人而异，扇出成本从 O(1) 变成 O(成员数)
         */
        this.announce(msg)
      })

      chatSocket.on('conversation.read', () => void this.refresh())

      /*
       * 系统公告计入总数，所以公告有变化也要对一次
       *
       * 这里不做乐观 +1：公告的变化有发布、撤回、删除、编辑四种，
       * 只有发布是 +1，其余要么 -1 要么不变，自己算容易算错，直接拉一次最稳。
       * 只有发布才提醒（提示音 / 桌面通知）；撤回、删除只是让数字对齐
       */
      chatSocket.on('notice.changed', (data) => {
        void this.refresh()
        const d = data as { id: number; action: string; title: string }
        if (d.action === 'published') {
          notifyNewMessage('系统公告', d.title, () => {
            void router.push({ path: '/collab/chat', query: { notice: String(d.id) } })
          })
        }
      })
      // 在别的标签页 / 手机上读了公告，这里的数字也要跟着减
      chatSocket.on('notice.read', () => void this.refresh())
    },

    /**
     * 弹提醒
     *
     * 文本给内容，图片文件给类型——把文件名念出来没有意义，
     * 而「[图片]」已经说清楚了发生了什么。
     */
    announce(msg: ChatMessage) {
      const body =
        msg.type === 'image' ? '[图片]' : msg.type === 'file' ? '[文件]' : msg.content

      notifyNewMessage(msg.sender_name || '新消息', body, () => {
        // 点通知跳到聊天页。已经在聊天页时路由不会变，但窗口已经聚焦了
        void router.push({ path: '/collab/chat', query: { conv: String(msg.conv_id) } })
      })
    },

    /** 进入某个会话：它的未读立刻清掉，不等服务端往返 */
    enter(convId: number) {
      this.activeConvId = convId
    },

    leave() {
      this.activeConvId = 0
    },

    /**
     * 浏览器标题
     *
     * 挂着十几个标签页时，这里是用户唯一会注意到的地方。
     * 去掉已有的 `(n)` 前缀再加，否则会叠成 `(3) (2) Keel`。
     */
    syncTitle() {
      const base = document.title.replace(/^\(\d+\)\s*/, '')
      document.title = this.total > 0 ? `(${this.total}) ${base}` : base
    },
  },
})

if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useChatStore, import.meta.hot))
}
