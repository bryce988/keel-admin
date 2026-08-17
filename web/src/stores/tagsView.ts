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
  /** 首页签固定，不可关闭 */
  affix?: boolean
}

const STORAGE_KEY = 'keel_tags_view'
const HOME_PATH = '/dashboard'
/** 上限：超出后淘汰最早打开的（首页签与当前页不淘汰），见 PROJECT.md §5 */
export const MAX_TAGS = 8

const HOME_TAG: TagItem = { path: HOME_PATH, fullPath: HOME_PATH, title: '系统概览', affix: true }

function load(): { tags: TagItem[]; active: string } {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return { tags: [HOME_TAG], active: HOME_PATH }

    const saved = JSON.parse(raw) as { tags: TagItem[]; active: string }
    const tags = Array.isArray(saved.tags)
      ? saved.tags
          .filter((t) => t?.path)
          // fullPath 是后加的字段，老数据里没有，退回 path 兼容
          .map((t) => ({ ...t, fullPath: t.fullPath || t.path }))
      : []
    if (!tags.some((t) => t.path === HOME_PATH)) tags.unshift(HOME_TAG)

    return {
      tags: tags.slice(0, MAX_TAGS),
      active: tags.some((t) => t.path === saved.active) ? saved.active : HOME_PATH
    }
  } catch {
    return { tags: [HOME_TAG], active: HOME_PATH }
  }
}

export const useTagsViewStore = defineStore('tagsView', {
  state: () => {
    const { tags, active } = load()
    return { tags, active, lastEvicted: '' }
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
      this.lastEvicted = ''

      const existing = this.tags.find((t) => t.path === path)
      if (existing) {
        // 同一个页面换了筛选条件：不新开页签，只记住最新地址
        existing.fullPath = route.fullPath
        this.persist()
        return
      }

      this.tags.push({ path, fullPath: route.fullPath, title, affix: path === HOME_PATH })

      // 超上限：淘汰最早打开的，首页签与当前页不动
      while (this.tags.length > MAX_TAGS) {
        const victim = this.tags.find((t) => !t.affix && t.path !== this.active)
        if (!victim) break
        this.tags = this.tags.filter((t) => t.path !== victim.path)
        this.lastEvicted = victim.title
      }

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
