import { acceptHMRUpdate, defineStore } from 'pinia'
import { fetchPublicParams } from '@/api/system'

const SIDEBAR_KEY = 'keel_sidebar_collapsed'
const THEME_KEY = 'keel_theme'
const LAYOUT_KEY = 'keel_layout'

export type ThemeMode = 'light' | 'dark'

/**
 * 导航版式
 *
 * - `side` 经典：一级、二级都在左侧栏，二级嵌套展开
 * - `mix`  混合：一级在顶栏横排，左侧栏只显示当前一级项的子菜单
 *
 * 两者用的是**同一棵**后端菜单树（`userStore.menus`），只是分层渲染的位置不同，
 * 所以切换版式不涉及路由重注册，也不需要后端配合。
 */
export type LayoutMode = 'side' | 'mix'

/** 读版式：localStorage 里可能是历史遗留的脏值，只认已知的两个 */
function readLayout(): LayoutMode {
  return localStorage.getItem(LAYOUT_KEY) === 'mix' ? 'mix' : 'side'
}

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

/** 全局界面状态：侧栏折叠、主题、导航版式，均持久化到 localStorage */
export const useAppStore = defineStore('app', {
  state: () => ({
    sidebarCollapsed: localStorage.getItem(SIDEBAR_KEY) === '1',
    theme: (localStorage.getItem(THEME_KEY) as ThemeMode) || 'light',
    layout: readLayout(),

    /**
     * 站点标识，由后端参数下发（`sys.name` / `sys.logo` / `sys.footer`）
     *
     * 这里的初值是**兜底**不是默认值：接口没回来、或者部署时后端没起来时用它，
     * 保证 logo 位置不是一片空白。真正的默认值在 `server/scripts/seed.php` 里。
     *
     * 放在 app store 而不是各页面自己取：登录页要用（未登录）、侧栏要用（已登录）、
     * 路由标题也要用，三个地方各请求一次不如存一份。
     */
    site: {
      name: 'Keel',
      /** 登录页 Logo 图片地址；空串表示用内置的矢量标记 */
      logo: '',
      footer: ''
    }
  }),

  actions: {
    /**
     * 拉站点标识
     *
     * 免登录接口，在 `main.ts` 里 **不 await** 地发出去：
     * 站点名这种东西不值得让整个应用等一个网络往返，
     * 而后端不可用时 await 会把首屏卡成白屏——兜底值本来就是为这一刻准备的。
     *
     * 代价是首屏可能先画兜底名再换成真名。默认部署下两者一致（seed 里
     * `sys.name` 就是这个值），只有改过参数的站点会看到一次替换。
     *
     * 失败静默：这是装饰性数据，弹一个「加载站点信息失败」既没法处理也没意义。
     */
    async loadSite() {
      try {
        const data = await fetchPublicParams()
        this.site = {
          name: data['sys.name'] || this.site.name,
          logo: data['sys.logo'] || '',
          footer: data['sys.footer'] || ''
        }
      } catch {
        // 保持兜底值
      }
    },

    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      localStorage.setItem(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0')
    },

    /**
     * 切换导航版式
     *
     * 顺手把折叠态复位：混合版式下侧栏只剩当前模块的几个二级项，
     * 带着上一个版式的折叠状态过来，用户会看到一条只有图标的窄条，
     * 而这时候他刚点完「混合布局」，最需要看清的恰恰是侧栏变成了什么。
     */
    setLayout(mode: LayoutMode) {
      if (this.layout === mode) return
      this.layout = mode
      localStorage.setItem(LAYOUT_KEY, mode)
      this.sidebarCollapsed = false
      localStorage.setItem(SIDEBAR_KEY, '0')
    },

    /**
     * 手动切换（顶栏那个月亮/太阳按钮）
     *
     * 与 `setTheme` 分开，是因为过渡只该出现在用户主动切换时：
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
