import { acceptHMRUpdate, defineStore } from 'pinia'
import request from '@/utils/request'

export interface DictItem {
  label: string
  value: string
  tag_type: string
}

/**
 * 数据字典
 *
 * 全站枚举与状态色的唯一来源，页面里不写死选项（CLAUDE.md 前端约定）。
 *
 * 一个列表页往往同时用到三四个字典，若每个组件各发一次请求，
 * 首屏会出现一串重复的小请求。这里做两件事：
 * - 已加载的直接返回，不重复请求
 * - 同一个字典的并发请求共用一个 Promise
 */
export const useDictStore = defineStore('dict', {
  state: () => ({
    data: {} as Record<string, DictItem[] | undefined>,
    // 值可能为 undefined：请求结束后会 delete 掉，类型要如实反映
    pending: {} as Record<string, Promise<DictItem[]> | undefined>
  }),

  actions: {
    items(code: string): DictItem[] {
      return this.data[code] ?? []
    },

    async load(code: string): Promise<DictItem[]> {
      if (this.data[code]) return this.data[code]
      if (this.pending[code]) return this.pending[code]

      const task = request
        .get<unknown, DictItem[]>(`/admin/dicts/${code}/items`)
        .then((items) => {
          this.data[code] = items
          return items
        })
        .catch(() => {
          /*
           * 降级为空选项，但**不写进缓存**
           *
           * 写了 `this.data[code] = []` 的话，它就是个真值，
           * 上面两行的短路判断会认为「已加载」，于是这个字典**永远不再重试**——
           * 一次网络抖动（或令牌过期期间的那几个 401）就能让全站的
           * <DictTag> 一直显示「-」，只有整页刷新才好。
           * 不缓存失败，下一次 preload 会重新拉。
           */
          return []
        })
        .finally(() => {
          delete this.pending[code]
        })

      this.pending[code] = task
      return task
    },

    /** 批量预热，页面 onMounted 时一次拉齐 */
    async preload(codes: string[]): Promise<void> {
      const missing = codes.filter((c) => !this.data[c] && !this.pending[c])
      if (missing.length === 0) {
        await Promise.all(codes.map((c) => this.load(c)))
        return
      }

      const task = request
        .get<unknown, Record<string, DictItem[]>>('/admin/dicts/batch', {
          params: { codes: missing.join(',') }
        })
        .then((result) => {
          Object.assign(this.data, result)
        })
        .catch(() => {
          // 同上：失败不写缓存，否则这批字典这一整个会话都不会再拉了
        })

      missing.forEach((c) => (this.pending[c] = task.then(() => this.data[c] ?? [])))
      await task
      missing.forEach((c) => delete this.pending[c])
    },

    /** 取某个值对应的字典项，用于渲染标签 */
    find(code: string, value: unknown): DictItem | undefined {
      return this.data[code]?.find((item) => item.value === String(value))
    },

    /** 退出登录时清空；字典维护后请用 refresh() */
    forget(code?: string) {
      if (code) {
        delete this.data[code]
      } else {
        this.data = {}
      }
    },

    /**
     * 字典维护后重新拉取
     *
     * 只 forget 不够：别的页面被 keep-alive 缓存着，上面的 <DictTag> 仍然挂载在那儿，
     * 而它只在 onMounted 时 load 一次。清掉数据它会立刻变成兜底的「-」，
     * 且再也没人去补——所以必须紧接着重新拉一遍，让那些标签直接跟着变色。
     */
    async refresh(code: string): Promise<DictItem[]> {
      delete this.data[code]
      delete this.pending[code]

      return this.load(code)
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
  import.meta.hot.accept(acceptHMRUpdate(useDictStore, import.meta.hot))
}
