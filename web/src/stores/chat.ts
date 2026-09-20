import { acceptHMRUpdate, defineStore } from 'pinia'
import { getUnread, type ChatMessage } from '@/api/chat'
import { chatSocket } from '@/utils/chatSocket'
import { useUserStore } from '@/stores/user'

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
    /** 红点上的数字，不含免打扰 */
    total: 0,
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
      })

      chatSocket.on('conversation.read', () => void this.refresh())
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
