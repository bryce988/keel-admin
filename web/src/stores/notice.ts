import { acceptHMRUpdate, defineStore } from 'pinia'
import { fetchBell, readAllNotices, readNotice, type BellNotice, type NoticeDetail } from '@/api/notice'
import { useUserStore } from '@/stores/user'

/**
 * 顶栏铃铛的状态与轮询
 *
 * ## 为什么是轮询而不是推送
 *
 * 脚手架不引长连接：WebSocket 要额外的进程、网关配置（nginx 的 upgrade 头）、
 * 断线重连与鉴权续期，而公告这种「一天几条」的东西，延迟一分钟毫无影响。
 * 后端那个接口就是照着被反复轮询设计的——两条走索引的查询，不 join。
 *
 * 三条省请求的规则，缺一条都会在生产上变成持续的无效流量（前两条在
 * `<NoticeBell>` 的定时器里，第三条在这里）：
 * - 组件卸载时清掉定时器
 * - 标签页不可见时不轮询，切回来立刻补一次（挂着十几个后台标签页是常态）
 * - 未登录不轮询：登录页、令牌过期后都不该每分钟打一次 401
 */

/**
 * 轮询间隔。公告不是即时通讯，一分钟足够；再短就是在给自己造流量。
 *
 * 定时器本身在 `<NoticeBell>` 里，不在这个 store 里：轮询到新公告要弹
 * ElNotification，而那是呈现层的事——store 自己弹东西，任何引用它的地方
 * （包括测试）都会跟着弹。store 只回答「有没有新的」，弹不弹由组件决定。
 */
export const NOTICE_POLL_MS = 60_000

/** 弹过的最新公告 id 存 localStorage，按用户分键——同一台电脑换人登录不该继承 */
const POPPED_KEY = 'keel_notice_popped'

export const useNoticeStore = defineStore('notice', {
  state: () => ({
    unreadCount: 0,
    list: [] as BellNotice[],
    latestId: 0,
    latestTitle: '',
    loading: false,
    /** 详情弹窗当前展示的公告，null = 未打开 */
    current: null as NoticeDetail | null,
    /**
     * 本次会话是否已经拉过一次
     *
     * 首次拉取**不弹**提示：刚登录时把积压的未读弹出来是骚扰，
     * 角标已经把「有未读」这件事说清楚了。弹窗只留给「你在线时新发布的」。
     */
    primed: false
  }),

  actions: {
    /**
     * 拉一次铃铛数据
     *
     * @returns 需要弹提示的公告标题，没有则空串——弹窗由组件负责，
     *   store 不直接调 ElNotification（那会让它在测试里也弹东西）
     */
    async poll(): Promise<string> {
      const userStore = useUserStore()
      if (!userStore.token) return ''

      this.loading = true
      try {
        const data = await fetchBell()
        const prevPopped = this.poppedId()

        this.unreadCount = data.unread_count
        this.list = data.list
        this.latestId = data.latest_id
        this.latestTitle = data.latest_title

        const isNew = data.latest_id > 0 && data.latest_id > prevPopped
        const shouldPop = isNew && this.primed
        this.primed = true

        // 不管弹没弹都记下水位：首次拉取时把积压的未读一并算作「已知」，
        // 否则下一次轮询会把它们当成新消息弹一遍
        if (isNew) this.rememberPopped(data.latest_id)

        return shouldPop ? data.latest_title : ''
      } catch {
        // 轮询失败保持原样，不清空角标：一次网络抖动不该让消息看起来"没了"
        return ''
      } finally {
        this.loading = false
      }
    },

    /** 打开一条：拿正文的同时服务端落已读回执，回来再刷新角标 */
    async open(id: number) {
      this.current = await readNotice(id)

      const hit = this.list.find((n) => n.id === id)
      if (hit && !hit.is_read) {
        hit.is_read = true
        this.unreadCount = Math.max(0, this.unreadCount - 1)
      }
    },

    close() {
      this.current = null
    },

    async markAllRead() {
      await readAllNotices()
      this.unreadCount = 0
      this.list = this.list.map((n) => ({ ...n, is_read: true }))
      // 水位推到当前最新，否则下次轮询会把刚标记已读的这批当成新消息
      if (this.latestId) this.rememberPopped(this.latestId)
    },

    /** 登出时清干净：下一个人登录进来不该看到上一个人的未读 */
    reset() {
      this.$patch({ unreadCount: 0, list: [], latestId: 0, latestTitle: '', current: null, primed: false })
    },

    poppedId(): number {
      const uid = useUserStore().profile?.user.id ?? 0
      return Number(localStorage.getItem(`${POPPED_KEY}:${uid}`) || 0)
    },

    rememberPopped(id: number) {
      const uid = useUserStore().profile?.user.id ?? 0
      localStorage.setItem(`${POPPED_KEY}:${uid}`, String(id))
    }
  }
})

/*
 * 少了这段，热更后组件持有的是旧 store 实例：
 * 接口明明返回了新数据，角标就是不动，刷新一下又好了（CLAUDE.md 已踩过的坑）。
 */
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useNoticeStore, import.meta.hot))
}
