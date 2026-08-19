import { acceptHMRUpdate, defineStore } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'

export interface TagItem {
  /** 页签身份，等于 route.path。筛选条件变化不该开出一个新页签，所以身份不含 query */
  path: string
  /**
   * 最近一次访问的完整地址，含 query。
   * 点页签时跳这个而不是 path，否则切走再切回来筛选条件与页码就没了
   */
  fullPath: string
  title: string
  /**
   * 固定：不可关闭，且不参与任何批量关闭。
   * 首页签天生固定，其余由用户从页签菜单里切换
   */
  affix?: boolean
}

const STORAGE_KEY = 'keel_tags_view'
const HOME_PATH = '/dashboard'

/*
 * 这里**没有**页签数量上限
 *
 * 原来限 8 个、超出淘汰最早打开的并弹一条提示。实际用起来是
 * 「开着开着东西自己没了」——用户没做任何关闭动作却丢了上下文，
 * 比条子变长难受得多，而且提示只在淘汰那一瞬闪一下，回头根本想不起来丢了什么。
 * 条子本身是横向滚动的，装不下就滚；要收拾用菜单里的批量关闭。
 */

/*
 * 首页签在菜单下发之前就要显示，所以标题只能写死一份。
 * 它必须与 seed.php 里 `sys:dashboard:view` 的菜单名一致——
 * 不一致的结果是页签写着一个名字、侧边栏和面包屑写着另一个
 */
const HOME_TAG: TagItem = { path: HOME_PATH, fullPath: HOME_PATH, title: '概览', affix: true }

function load(): { tags: TagItem[]; active: string } {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return { tags: [HOME_TAG], active: HOME_PATH }

    const saved = JSON.parse(raw) as { tags: TagItem[]; active: string }
    const tags: TagItem[] = Array.isArray(saved.tags)
      ? saved.tags
          .filter((t) => t?.path)
          // fullPath 是后加的字段，老数据里没有，退回 path 兼容；
          // 首页签的固定态不信存量数据，永远为真
          .map((t) => ({
            ...t,
            fullPath: t.fullPath || t.path,
            affix: t.path === HOME_PATH ? true : !!t.affix
          }))
      : []
    if (!tags.some((t) => t.path === HOME_PATH)) tags.unshift(HOME_TAG)

    return {
      tags,
      active: tags.some((t) => t.path === saved.active) ? saved.active : HOME_PATH
    }
  } catch {
    return { tags: [HOME_TAG], active: HOME_PATH }
  }
}

export const useTagsViewStore = defineStore('tagsView', {
  state: () => {
    const { tags, active } = load()
    return { tags, active }
  },

  actions: {
    persist() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ tags: this.tags, active: this.active }))
    },

    /** 打开页面时调用；已存在则仅激活并更新 fullPath */
    open(route: RouteLocationNormalized) {
      const path = route.path
      const title = (route.meta.title as string) || path
      this.active = path

      const existing = this.tags.find((t) => t.path === path)
      if (existing) {
        // 同一个页面换了筛选条件：不新开页签，只记住最新地址
        existing.fullPath = route.fullPath
        this.persist()
        return
      }

      this.tags.push({ path, fullPath: route.fullPath, title, affix: path === HOME_PATH })
      this.persist()
    },

    /**
     * 关闭并返回应跳转的**完整地址**（关闭的是当前页时才需要跳转）
     *
     * 返回 fullPath 而不是 path：跳回去的那个页签也该带着它自己的筛选条件。
     */
    close(path: string): string | null {
      const index = this.tags.findIndex((t) => t.path === path)
      if (index === -1 || this.tags[index].affix) return null

      this.tags.splice(index, 1)
      if (this.active !== path) {
        this.persist()
        return null
      }

      const next = this.tags[Math.min(index, this.tags.length - 1)] ?? HOME_TAG
      this.active = next.path
      this.persist()
      return next.fullPath
    },

    closeOthers(path: string): string {
      this.tags = this.tags.filter((t) => t.affix || t.path === path)
      this.active = path
      this.persist()
      return this.tags.find((t) => t.path === path)?.fullPath ?? path
    },

    /**
     * 关闭左侧
     *
     * 与 closeRight 的写法刻意对称：固定页签一律留下，
     * 只有当前页被关掉时才需要跳转，返回值是「该跳去哪」
     */
    closeLeft(path: string): string | null {
      const index = this.tags.findIndex((t) => t.path === path)
      if (index === -1) return null

      this.tags = this.tags.filter((t, i) => i >= index || t.affix)
      if (this.tags.some((t) => t.path === this.active)) {
        this.persist()
        return null
      }

      this.active = path
      this.persist()
      return this.tags.find((t) => t.path === path)?.fullPath ?? path
    },

    closeRight(path: string): string | null {
      const index = this.tags.findIndex((t) => t.path === path)
      if (index === -1) return null

      this.tags = this.tags.filter((t, i) => i <= index || t.affix)
      if (!this.tags.some((t) => t.path === this.active)) {
        this.active = path
        this.persist()
        return this.tags.find((t) => t.path === path)?.fullPath ?? path
      }
      this.persist()
      return null
    },

    closeAll(): string {
      this.tags = this.tags.filter((t) => t.affix)
      this.active = HOME_PATH
      this.persist()
      return this.tags.find((t) => t.path === HOME_PATH)?.fullPath ?? HOME_PATH
    },

    /**
     * 切换固定
     *
     * 首页签不参与：它的固定不是用户偏好而是结构约束——
     * 允许取消就会出现「一个页签都不剩」的状态，那时候内容区显示什么没有定义
     */
    toggleAffix(path: string) {
      const tag = this.tags.find((t) => t.path === path)
      if (!tag || tag.path === HOME_PATH) return

      tag.affix = !tag.affix
      this.persist()
    },

    reset() {
      this.tags = [HOME_TAG]
      this.active = HOME_PATH
      localStorage.removeItem(STORAGE_KEY)
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
  import.meta.hot.accept(acceptHMRUpdate(useTagsViewStore, import.meta.hot))
}
