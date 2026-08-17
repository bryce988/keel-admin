import { defineStore } from 'pinia'
import request from '@/utils/request'

export interface DictItem {
  label: string
  value: string
  tagType: string
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
          // 单个字典取不到不该让整个页面白屏，降级为空选项
          this.data[code] = []
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
          missing.forEach((c) => (this.data[c] = []))
        })

      missing.forEach((c) => (this.pending[c] = task.then(() => this.data[c] ?? [])))
      await task
      missing.forEach((c) => delete this.pending[c])
    },

    /** 取某个值对应的字典项，用于渲染标签 */
    find(code: string, value: unknown): DictItem | undefined {
      return this.data[code]?.find((item) => item.value === String(value))
    },

    /** 字典维护后调用，或退出登录时清空 */
    forget(code?: string) {
      if (code) {
        delete this.data[code]
      } else {
        this.data = {}
      }
    }
  }
})
