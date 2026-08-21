/**
 * 模板用的假数据源 —— ⛔ 复制模板后请删掉本文件的引用
 *
 * 模板要能真的跑起来才算数：只有能打开、能翻页、能提交的模板，
 * 才会在组件签名变了的时候立刻露馅。但模板又不该依赖某个具体业务接口，
 * 所以这里放一份内存假数据，复制走之后把 import 换成你自己的 `api/xxx.ts` 即可。
 *
 * 这些函数的签名与真接口一致（分页入参、PageResult 出参），
 * 换成真接口时页面代码一行都不用改。
 */
import type { PageResult } from '@/components'

export interface DemoRow {
  id: number
  name: string
  code: string
  category_id: number
  status: number
  sort: number
  owner: string
  remark: string
  created_at: string
}

export interface DemoNode {
  id: number
  name: string
  children?: DemoNode[]
}

/** 分类树：树表联动页左侧用 */
export const demoTree: DemoNode[] = [
  {
    id: 1,
    name: '全部分类',
    children: [
      { id: 11, name: '硬件', children: [{ id: 111, name: '服务器' }, { id: 112, name: '网络设备' }] },
      { id: 12, name: '软件', children: [{ id: 121, name: '操作系统' }, { id: 122, name: '数据库' }] },
      { id: 13, name: '耗材' }
    ]
  }
]

const CATEGORY_NAME: Record<number, string> = {
  11: '硬件', 12: '软件', 13: '耗材', 111: '服务器', 112: '网络设备', 121: '操作系统', 122: '数据库'
}

function seed(): DemoRow[] {
  const owners = ['王强', '李娜', '赵敏', '孙磊']
  const cats = [111, 112, 121, 122, 13]

  return Array.from({ length: 57 }, (_, i) => ({
    id: i + 1,
    name: `示例数据 ${String(i + 1).padStart(3, '0')}`,
    code: `DEMO_${String(i + 1).padStart(3, '0')}`,
    category_id: cats[i % cats.length],
    status: i % 5 === 0 ? 0 : 1,
    sort: i,
    owner: owners[i % owners.length],
    remark: i % 3 === 0 ? '这是一条备注' : '',
    created_at: `2026-0${(i % 8) + 1}-1${i % 9} 09:${String(i % 60).padStart(2, '0')}:00`
  }))
}

let rows = seed()
let nextId = rows.length + 1

/** 假装有网络延迟，好让 loading 态在开发时真的能看见 */
function delay<T>(data: T, ms = 200): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(data), ms))
}

export function categoryName(id: number): string {
  return CATEGORY_NAME[id] ?? '—'
}

export function fetchDemoList(params: Record<string, any>): Promise<PageResult<DemoRow>> {
  const keyword = String(params.keyword ?? '').trim()
  const status = params.status
  const categoryId = Number(params.category_id ?? 0)

  let list = rows.filter((row) => {
    if (keyword && !row.name.includes(keyword) && !row.code.includes(keyword)) return false
    if (status !== '' && status !== undefined && row.status !== Number(status)) return false
    // 树上点父节点要能看到子分类的数据，与真实的「按部门筛选连同下级」一致
    if (categoryId && row.category_id !== categoryId && !isDescendant(row.category_id, categoryId)) {
      return false
    }
    return true
  })

  const field = String(params.sort_field ?? 'id')
  const order = params.sort_order === 'asc' ? 1 : -1
  list = [...list].sort((a, b) => ((a as any)[field] > (b as any)[field] ? order : -order))

  const pageNum = Number(params.page_num ?? 1)
  const pageSize = Number(params.page_size ?? 20)

  return delay({
    list: list.slice((pageNum - 1) * pageSize, pageNum * pageSize),
    total: list.length,
    page_num: pageNum,
    page_size: pageSize
  })
}

function isDescendant(nodeId: number, ancestorId: number): boolean {
  if (ancestorId === 1) return true
  return String(nodeId).startsWith(String(ancestorId))
}

export function fetchDemoDetail(id: number): Promise<DemoRow> {
  const row = rows.find((r) => r.id === id)

  return row ? delay({ ...row }) : Promise.reject(new Error('数据不存在'))
}

export function createDemo(data: Partial<DemoRow>): Promise<DemoRow> {
  const row: DemoRow = {
    id: nextId++,
    name: '', code: '', category_id: 13, status: 1, sort: 0, owner: '当前用户', remark: '',
    created_at: new Date().toLocaleString('sv-SE'),
    ...data
  } as DemoRow

  rows.unshift(row)

  return delay(row)
}

export function updateDemo(id: number, data: Partial<DemoRow>): Promise<DemoRow> {
  const row = rows.find((r) => r.id === id)
  if (!row) return Promise.reject(new Error('数据不存在'))
  Object.assign(row, data)

  return delay({ ...row })
}

export function deleteDemo(id: number): Promise<void> {
  rows = rows.filter((r) => r.id !== id)

  return delay(undefined)
}

// ------------------------------------------------------------ 主从页用

export interface DemoChild {
  id: number
  master_id: number
  label: string
  value: string
  sort: number
  status: number
}

/*
 * 每条主记录都给明细，id 能被 5 整除的除外
 *
 * 全都有明细的话演示不到「主记录没有明细」的空状态；
 * 但也不能像最初那样只给前几条——列表倒序排，
 * 默认选中的第一条恰好没明细，一打开就是空的，看着像页面坏了
 */
let children: DemoChild[] = rows
  .filter((master) => master.id % 5 !== 0)
  .flatMap((master) =>
    Array.from({ length: (master.id % 4) + 1 }, (_, i) => ({
      id: master.id * 100 + i,
      master_id: master.id,
      label: `明细项 ${i + 1}`,
      value: `V${i + 1}`,
      sort: i,
      status: 1
    }))
  )
let nextChildId = 100000

export function fetchDemoChildren(params: Record<string, any>): Promise<PageResult<DemoChild>> {
  const list = children.filter((c) => c.master_id === Number(params.master_id))

  return delay({ list, total: list.length, page_num: 1, page_size: list.length || 1 })
}

export function createDemoChild(data: Partial<DemoChild>): Promise<DemoChild> {
  const child = { id: nextChildId++, label: '', value: '', sort: 0, status: 1, ...data } as DemoChild
  children.push(child)

  return delay(child)
}

export function updateDemoChild(id: number, data: Partial<DemoChild>): Promise<DemoChild> {
  const child = children.find((c) => c.id === id)
  if (!child) return Promise.reject(new Error('数据不存在'))
  Object.assign(child, data)

  return delay({ ...child })
}

export function deleteDemoChild(id: number): Promise<void> {
  children = children.filter((c) => c.id !== id)

  return delay(undefined)
}
