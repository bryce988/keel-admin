/**
 * 接口契约的公共类型
 *
 * 分页结构、列表查询参数、批量操作回执——这些是**后端契约**的一部分
 * （docs/api.md §1.3），不是组件的东西。原先它们定义在 `components/ProTable.vue`
 * 里、由 `@/components` 转出，于是 `api/*.ts` 要从组件 barrel 取接口类型：
 * 依赖方向反了，而且换掉表格组件就得动一遍所有接口文件。
 *
 * 现在方向是 组件 → 类型 ← 接口，两边都只依赖这一个文件。
 */

/** 分页响应，字段名与后端逐字一致 */
export interface PageResult<Row = Record<string, unknown>> {
  list: Row[]
  total: number
  page_num: number
  page_size: number
}

/** 列表查询参数：分页 + 排序 + 各页自己的筛选项 */
export interface TableQuery {
  page_num: number
  page_size: number
  sort_field?: string
  sort_order?: 'asc' | 'desc'
  [key: string]: unknown
}

/** 批量操作回执：部分成功也是成功，失败的逐条给原因 */
export interface BatchOutcome {
  success_count: number
  fail_count: number
  succeeded: number[]
  failed: Array<{ id: number; reason: string }>
}
