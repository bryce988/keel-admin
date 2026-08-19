import { acceptHMRUpdate, defineStore } from 'pinia'

const SIDEBAR_KEY = 'keel_sidebar_collapsed'
const THEME_KEY = 'keel_theme'

export type ThemeMode = 'light' | 'dark'

/**
 * 降级路径用的定时器（见 `toggleTheme`）
 *
 * 模块级变量在前端没有 webman 那种常驻内存的忌讳（每个浏览器标签页一份）。
 * 连点切换时用它取消上一次的摘除，否则前一次的定时器会把后一次刚加上的类
 * 提前摘掉，禁用过渡的窗口就断了。
 */
let switchTimer = 0

/** `startViewTransition` 还没进 TS 的 DOM 类型，这里自己声明一个最小签名 */
type ViewTransitionDocument = Document & {
  startViewTransition?: (cb: () => void) => { finished: Promise<void> }
}

/** 全局界面状态：侧栏折叠、主题，均持久化到 localStorage */
export const useAppStore = defineStore('app', {
  state: () => ({
    sidebarCollapsed: localStorage.getItem(SIDEBAR_KEY) === '1',
    theme: (localStorage.getItem(THEME_KEY) as ThemeMode) || 'light'
  }),

  actions: {
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      localStorage.setItem(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0')
    },

    /**
     * 手动切换（顶栏那个月亮/太阳按钮）
     *
     * 与 `setTheme` 分开，是因为过渡只该出现在**用户主动切换**时：
     * `initTheme` 在首屏跑，那时候页面还没画出来，套上过渡只会让首屏
     * 从浅色淡入深色——正是要避免的那种"闪一下"。
     *
     * 观感全部交给 View Transitions（交叉淡化两张快照），同时把全站
     * 逐元素过渡按掉。两件事缺一不可，原因写在 styles/index.css 那两段注释里。
     */
    toggleTheme() {
      const next: ThemeMode = this.theme === 'dark' ? 'light' : 'dark'
      const root = document.documentElement
      const doc = document as ViewTransitionDocument
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

      root.classList.add('theme-switching')
      const release = () => root.classList.remove('theme-switching')

      if (reduced || typeof doc.startViewTransition !== 'function') {
        // 降级：关了动效的用户，以及还没支持 View Transitions 的浏览器。
        // 结果是干净的硬切——不给过渡，好过给一个会抖的过渡
        this.setTheme(next)
        window.clearTimeout(switchTimer)
        switchTimer = window.setTimeout(release, 50)
        return
      }

      // 回调里做 DOM 改动，浏览器负责拍前后两张快照并交叉淡化。
      // `finished` 在动画被跳过时（页面不可见、并发切换）同样会落地，
      // 所以类不会有留在身上摘不掉的情况——最坏也只是退化成瞬变
      doc.startViewTransition(() => {
        this.setTheme(next)
      }).finished.catch(() => {}).finally(release)
    },

    setTheme(mode: ThemeMode) {
      this.theme = mode
      localStorage.setItem(THEME_KEY, mode)
      document.documentElement.classList.toggle('dark', mode === 'dark')
    },

    /**
     * 首屏对齐
     *
     * `index.html` 里的内联脚本已经在绘制前把 `.dark` 加好了，这里再调一次
     * 是为了让 store 的 state 与 DOM 上的类保持同一个来源，
     * 不依赖"两边都读了同一个 localStorage 键"这种巧合。
     */
    initTheme() {
      this.setTheme(this.theme)
    }
  }
})

/**
 * Pinia 的 HMR 支持
 *
 * 不加这段，热更时 store 定义被替换、已挂载的组件却仍持有旧实例，
 * 表现为「接口明明返回了数据，界面就是不更新」，而且刷新一下又好了——
 * 最难查的一类问题。开发期才有影响，生产构建不会走到。
 */
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useAppStore, import.meta.hot))
}
