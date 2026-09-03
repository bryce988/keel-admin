<script setup lang="ts" generic="T extends object">
import {
  computed,
  nextTick,
  onActivated,
  onBeforeUnmount,
  onDeactivated,
  onMounted,
  reactive,
  ref,
  watch,
  type Ref
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Refresh, Setting, Sort } from '@element-plus/icons-vue'
import DictTag from './DictTag.vue'
import type { PageResult, TableQuery } from '@/types/api'

/**
 * 列表表格
 *
 * 把「取数 → 分页 → 排序 → 加载态 → 空态 → 列设置」这一套收在一个组件里。
 * 页面只需要给一个 request 函数和列定义：
 *
 *   <ProTable ref="tableRef" :request="fetchUsers" :params="query" :columns="columns">
 *     <template #toolbar><el-button v-permission="'sys:user:create'">新增</el-button></template>
 *     <template #actions="{ row }">…</template>
 *   </ProTable>
 *
 * 分页参数与响应结构遵循 docs/api.md §1.3，不在页面里各写各的。
 *
 * ## 行类型是泛型的
 *
 * `T` 从 `request` 的返回值推断，页面通常不用显式写。推断出来之后：
 * `#actions="{ row }"` 里的 `row`、`columns` 的 `formatter(row)`、
 * `@selection-change` 的入参，全都是真实的行类型而不是 `Record<string, any>`。
 *
 * 这不是「更严格一点」的洁癖：接口少了一个字段、或者字段改了名，
 * 以前要等用户点开那一页、看到一列空白才发现，现在 `vue-tsc` 直接报错。
 * 改成泛型时就在 views/template 里逮到 4 处 row 类型对不上的地方。
 *
 * 内部访问单元格用 `cellValue()` 做一次收口的类型断言：`T` 只约束到 object，
 * 而 `interface Foo { a: string }` 在 TS 里并不满足 `Record<string, unknown>`
 * （接口不会隐式获得索引签名），约束写严了反而让调用方全都传不进来。
 */
/**
 * 列定义
 *
 * ## 宽度：优先 `minWidth`，不要用 `width`
 *
 * `width` 是死值，`minWidth` 是下限——列宽不够时两者一样，有富余时只有 `minWidth`
 * 会把剩下的空间摊进来。写死 `width` 的列在宽屏上会让表格右边空一块。
 * 只有真正不该变宽的（图标、单个开关）才用 `width`。
 *
 * ⚠️ **先加 32px，不是 24px**。EP 默认 `.el-table .cell { padding: 0 12px }`，
 * 但 `styles/index.css` 把它改成了 `0 16px`（行高留白那一版调的）。
 * 照 EP 文档按 24px 算，每列都会差 8px——差得不多，正好卡在
 * 「大部分值能显示、偏长的那个截断」，最难发现。第一轮修列宽就是这么返的工。
 *
 * 定长内容的常用值（**实测**，不是估的，新列照抄即可）：
 *
 * | 内容 | 文字宽 | +32 | 建议 |
 * |---|---|---|---|
 * | `2026-08-28 09:47:23` | 144 | 176 | `minWidth: 190` |
 * | 归属地 `中国广东省深圳市【电信】` | 157 | 189 | `minWidth: 200` |
 * | 权限标识 `sys:role:member:remove` | 159 | 191 | `minWidth: 210` |
 * | 角色编码 `ROLE_DEPT_MANAGER` | 154 | 186 | `minWidth: 200` |
 * | `TRC-` + 12 位十六进制 | 128 | 160 | `minWidth: 170` |
 * | `255.255.255.255` | 111 | 143 | `minWidth: 150` |
 * | 模块 `系统管理/菜单权限` | 119 | 151 | `minWidth: 160` |
 * | 姓名 5 字 `系统管理员` | 70 | 101 | `minWidth: 120` |
 * | 手机号 11 位 | 89 | 121 | `minWidth: 140` |
 *
 * 单字宽：汉字 13.9px、数字 8.05px、`- : 空格` 各 6.3px。
 * 另外要加的：`<DictTag>` 等标签 +20px（标签自身内边距与边框）、
 * **可排序的表头 +24px**（排序箭头），表头字重是 600 比正文宽一点。
 * 最终取「表头所需」与「正文最坏值所需」的较大者，再往上取整到十位留点余量。
 *
 * 重新量不必开浏览器，正文与表头两种字重都能量：
 *
 *   swift -e 'import AppKit
 *             let f = NSFont.systemFont(ofSize: 14)              // 表头用 weight: .semibold
 *             print(("2026-08-28 09:47:23" as NSString).size(withAttributes: [.font: f]).width)'
 *
 * 这张表是补出来的。原先日期列写的 160 / 165 只差几个像素，
 * 表现是秒被吃掉一半、显示成 `2026-08-28 09:4…`；而 `show-overflow-tooltip`
 * 默认开着，鼠标移上去看得到完整值，于是很久没人当成 bug。
 * 同类的还有「姓名」（100 装不下 `系统管理员`）、操作日志的「模块」与「操作人」、
 * 「IP」（装不下三位段的 IPv4）、个人中心的「登录地址」（120，直接砍一半）。
 *
 * 注意这些是**常见值的下限**，不是保证。姓名、部门这类用户自己填的字段没有上界，
 * 真填了十个字仍然会走 tooltip——那是可接受的降级，但产品自带的种子数据
 * （`系统管理员`、`技术负责人`）必须一个不截。
 *
 * ## 对齐：默认居中，左对齐是例外
 *
 * 表头已经在模板里写死居中。**正文也一律 `align: 'center'`**——
 * 后台列表的字段绝大多数是短的（账号、姓名、部门、编码、时间、状态），
 * 表头居中而正文靠左时，两者错开半个格子，一屏扫下来是歪的。
 *
 * 只有两类列**保持左对齐**，加列时对着这两条判断，别的一律居中：
 *
 * 1. **树形表格的名称列**（部门、菜单的「名称」）。这是硬约束不是审美：
 *    展开箭头和层级缩进都画在这一列上，居中之后缩进量被两侧的空白吃掉，
 *    父子层级就看不出来了。这两处的 `align: 'left'` 是显式写的，别当成漏改删掉
 * 2. **会被 tooltip 截断的自由文本**：备注、登录地址（`United StatesCalifornia
 *    【Google LLC】` 35 字符 vs `回环地址` 4 字符）、操作对象、接口路径、组件路径、
 *    失败说明。这类列每行长度差好几倍，居中会让首尾都参差，比靠左更难扫
 *
 * 数字列也不单独右对齐：全表就一两列数字时右对齐反而像漏改了。
 */
export interface ProColumn<Row = Record<string, unknown>> {
  prop: string
  label: string
  /** 死宽度。除非这一列不该随窗口变宽，否则用 minWidth */
  width?: number | string
  /** 宽度下限，有富余时按比例摊开——绝大多数列该用这个 */
  minWidth?: number | string
  /** 不写即左对齐。定长内容用 'center'，见上面的对齐约定 */
  align?: 'left' | 'center' | 'right'
  fixed?: boolean | 'left' | 'right'
  sortable?: boolean
  /** 用同名具名插槽渲染单元格 */
  slot?: string
  /** 直接按数据字典渲染标签 */
  dict?: string
  formatter?: (row: Row) => string
  showOverflowTooltip?: boolean
  /** 默认隐藏，可在「列设置」里打开 */
  hidden?: boolean
}

const props = withDefaults(
  defineProps<{
    /** 取数函数。列表模式返回分页结构，树形模式返回数组 */
    request: (params: TableQuery) => Promise<PageResult<T> | T[]>
    columns: ProColumn<T>[]
    /**
     * 筛选条件，变化时不自动请求，由页面显式调用 reload()。
     * 用 `v-model:params` 绑定才能在刷新后把 URL 里的条件还原回页面
     */
    params?: Record<string, unknown>
    /**
     * 从 URL 还原筛选值时的类型转换。
     * URL 里的东西永远是字符串，而 el-select 的选项常常是数字，
     * 不转的话下拉框会显示空白——所以数字型字段必须在这里登记：
     *   :param-parsers="{ status: Number, dept_id: Number }"
     */
    paramParsers?: Record<string, (raw: string) => unknown>
    /** 把分页、排序、筛选同步到 URL，刷新与页签切换后可还原 */
    syncUrl?: boolean
    rowKey?: string
    selection?: boolean
    /** 挂载时是否立即取数 */
    immediate?: boolean
    pageSize?: number
    /**
     * 首列显示主键 ID
     *
     * 不是序号列。序号（第几行）翻一页就变，指认不了任何东西——
     * 排查问题时说「第 3 行」没用，说「ID 12」才对得上库里的记录、日志里的
     * 操作对象、以及接口返回的主键。
     */
    idColumn?: boolean
    /**
     * 树形模式：不分页，request 直接返回数组
     *
     * 树必须一次给全——只给一页的树是断的，父节点被翻到下一页就成了孤儿。
     * 部门、菜单这类数据量本来就有限，全量返回反而更简单。
     */
    tree?: boolean
    defaultExpandAll?: boolean
  }>(),
  {
    rowKey: 'id',
    selection: false,
    immediate: true,
    /*
     * 每页 20 条
     *
     * 表体是定高的（见下面的 measure），一屏大约放得下 11 行。
     * 每页 10 条填不满，表格下方会空出一块；20 条正好让表体滚起来，
     * 也和接口的默认值对上（docs/api.md §1.3、Paginator::DEFAULT_SIZE）。
     */
    pageSize: 20,
    idColumn: false,
    syncUrl: true,
    tree: false,
    defaultExpandAll: true
  }
)

const emit = defineEmits<{
  'selection-change': [rows: T[]]
  loaded: [result: PageResult<T>]
  'update:params': [value: Record<string, unknown>]
}>()

const route = useRoute()
const router = useRouter()

/**
 * keep-alive 下组件失活后仍然活着，此时绝不能写 URL——
 * 那会把当前正在看的另一个页签的地址栏改掉。
 */
let alive = true
onActivated(() => (alive = true))
onDeactivated(() => (alive = false))

const loading = ref(false)
const rows = ref([]) as Ref<T[]>
const total = ref(0)
const selected = ref([]) as Ref<T[]>
/**
 * 表格密度，必须跟着 main.ts 的全局尺寸走（现在是 default）
 *
 * 两边一旦不一致，同一屏就会出现两套字号，表格看着像从别处贴过来的。
 * 用户仍可从工具栏的密度下拉调紧或调松。
 */
const size = ref<'large' | 'default' | 'small'>('default')

// 组件内部状态，用小驼峰；发请求时映射成接口的 snake_case
const pager = reactive({
  pageNum: 1,
  pageSize: props.pageSize,
  sortField: '' as string | undefined,
  sortOrder: undefined as 'asc' | 'desc' | undefined
})

/** 列显隐，初始值取列定义里的 hidden */
const visibleMap = reactive<Record<string, boolean>>({})
watch(
  () => props.columns,
  (columns) => {
    columns.forEach((col) => {
      if (visibleMap[col.prop] === undefined) visibleMap[col.prop] = !col.hidden
    })
  },
  { immediate: true, deep: true }
)

const shownColumns = computed(() => props.columns.filter((col) => visibleMap[col.prop] !== false))

/** 当前是否带着筛选条件——决定空态给「新建」还是「清空筛选」 */
const hasFilter = computed(() =>
  Object.values(props.params ?? {}).some(
    (v) => v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && !v.length)
  )
)

/** 空态文案里回显用户搜的词，比干巴巴一句「无结果」有用 */
const filterKeyword = computed(() => {
  const value = props.params?.keyword
  return typeof value === 'string' && value ? value : undefined
})

/**
 * 清空筛选并重新取数
 *
 * 把每个键置空而不是整个换成 {}：页面声明过的键必须保留下来，
 * 否则 URL 同步那边认不出「这个键被清空了」，地址栏里的旧值会留在那儿。
 */
function clearFilters() {
  const cleared: Record<string, unknown> = {}
  for (const key of Object.keys(props.params ?? {})) {
    cleared[key] = Array.isArray(props.params?.[key]) ? [] : ''
  }
  emit('update:params', cleared)
  reload()
}

/**
 * 首次取数用的筛选值
 *
 * 从 URL 还原时会 emit 给父组件，但 prop 回流要等父组件重新渲染，
 * 而首次 fetch 就在下一个 tick——直接读 props.params 会读到还没更新的旧值。
 * 所以把还原结果先留在这里，第一次请求用完即弃。
 */
let pendingParams: Record<string, unknown> | null = null

async function fetch() {
  loading.value = true
  const filters = pendingParams ?? props.params ?? {}
  pendingParams = null

  try {
    writeUrlFromState(filters)

    const raw = await props.request({
      ...filters,
      // 树形模式不传分页参数，后端也不该按分页处理
      ...(props.tree
        ? {}
        : {
            page_num: pager.pageNum,
            page_size: pager.pageSize,
            sort_field: pager.sortField || undefined,
            sort_order: pager.sortOrder
          })
    } as TableQuery)

    // 树接口直接返回数组，列表接口返回分页结构，在这里抹平差异
    const result: PageResult<T> = Array.isArray(raw)
      ? { list: raw, total: raw.length, page_num: 1, page_size: raw.length }
      : raw

    rows.value = result.list ?? []
    total.value = result.total ?? 0
    emit('loaded', result)
  } catch {
    // 错误提示已由 utils/request 的拦截器统一处理，这里只需清空避免展示旧数据
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
    // 搜索栏展开/收起会把表格顶部推上推下，取完数重算一次
    scheduleMeasure()
  }
}

/**
 * 回到第 1 页重新取数：筛选条件变化时用
 *
 * ⚠️ 这里的 `await nextTick()` 不能删。
 * 调用方通常是「改完 params 紧接着 reload()」：
 *
 *   query.value = { ...query.value, dept_id: 2 }
 *   tableRef.value?.reload()
 *
 * 而 params 是 prop，整体赋值后要等父组件重新渲染才会传进来。
 * 少了这一拍，fetch() 读到的是上一次的筛选值——
 * 表现为点部门树时数据总慢一拍，来回点两个部门就完全对调了。
 *
 * 修在这里而不是让每个页面自己 await：七个模块每个都记着这件事，迟早有人忘。
 */
async function reload() {
  pager.pageNum = 1
  await nextTick()

  return fetch()
}

/** 保持当前页刷新：删除、编辑后用 */
function refresh() {
  return fetch()
}

/**
 * 排序变化
 *
 * `prop` 会是 `null`——el-table 在「升序 → 降序 → 取消」的第三下把它清空。
 * 原来的签名写死 `prop: string`，一直没报错只是因为 el-table 当时没类型；
 * 接了按需导入拿到真类型后立刻暴露。逻辑本身是对的（order 为空时不取 prop），
 * 这里只是把签名改成事实。
 */
function onSortChange({ prop, order }: { prop: string | null; order: string | null }) {
  pager.sortField = order && prop ? prop : undefined
  pager.sortOrder = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : undefined
  reload()
}

function onSelectionChange(value: T[]) {
  selected.value = value
  emit('selection-change', value)
}

/**
 * 按列名取单元格值
 *
 * `T` 只约束到 `object`，直接 `row[prop]` 过不了类型检查。约束不能收紧到
 * `Record<string, unknown>`——那样 `interface UserRow { id: number }` 这种
 * 普通接口就传不进来了（TS 不给接口隐式索引签名，这是它与 type 别名的一个差异）。
 * 所以在这里做一次断言，全组件只此一处。
 */
function cellValue(row: T, prop: string): unknown {
  return (row as Record<string, unknown>)[prop]
}

function cellText(row: T, col: ProColumn<T>): string {
  if (col.formatter) return col.formatter(row)
  const value = cellValue(row, col.prop)
  return value === null || value === undefined || value === '' ? '-' : String(value)
}

/** URL → 组件状态。只认页面在 params 里声明过的筛选键，其余 query 一概不管 */
function restoreFromUrl() {
  if (!props.syncUrl) return

  const q = route.query

  pager.pageNum  = Math.max(1, Number(q.page_num) || 1)
  pager.pageSize = Number(q.page_size) || props.pageSize
  pager.sortField = (q.sort_field as string) || undefined
  pager.sortOrder = q.sort_order === 'asc' ? 'asc' : q.sort_order === 'desc' ? 'desc' : undefined

  if (!props.params) return

  const restored: Record<string, unknown> = {}
  let hit = false

  for (const key of Object.keys(props.params)) {
    const raw = q[key]
    if (raw === undefined) continue
    const parser = props.paramParsers?.[key]
    restored[key] = parser ? parser(String(raw)) : raw
    hit = true
  }

  if (hit) {
    pendingParams = { ...props.params, ...restored }
    emit('update:params', pendingParams)
  }
}

/** 组件状态 → URL。默认值不写进去，保持地址栏干净 */
function writeUrlFromState(filters: Record<string, unknown>) {
  if (!props.syncUrl || !alive) return

  const query: Record<string, string> = {}

  for (const [key, value] of Object.entries(filters)) {
    if (value === '' || value === null || value === undefined) continue
    if (Array.isArray(value)) {
      if (value.length) query[key] = value.join(',')
      continue
    }
    query[key] = String(value)
  }

  if (pager.pageNum > 1) query.page_num = String(pager.pageNum)
  if (pager.pageSize !== props.pageSize) query.page_size = String(pager.pageSize)
  if (pager.sortField) {
    query.sort_field = pager.sortField
    query.sort_order = pager.sortOrder ?? 'desc'
  }

  // 没变就不写：replace 会触发路由更新，不加这道判断容易绕成死循环
  const current = route.query
  const same =
    Object.keys(query).length === Object.keys(current).length &&
    Object.entries(query).every(([k, v]) => String(current[k]) === v)
  if (same) return

  // 用 replace 而不是 push：翻十页就往历史里塞十条，返回键会变得很难用
  router.replace({ path: route.path, query })
}

/* ---------------------------------------------------------------- 表体滚动
 * 表格自己撑到视口底部，行数变了只有表体滚，工具栏与分页不动
 *
 * 不这么做的话，每页从 10 切到 20，整块面板变高、分页被推到屏幕外，
 * 用户得先把页面滚到底才能点下一页——而滚动时表头也跟着滚走了。
 *
 * 为什么用 JS 量而不是纯 CSS 的 flex 链：那需要从 .content 到 .page 到面板
 * 一路都是定高 flex，而 `.page` 这个类同时用在概览、个人中心、表单页、详情页上，
 * 那些页面本来就该整页滚。给它们统一加高度约束会把内容压没。
 * 量一次高度只影响 ProTable 自己，别的页面一行不用改。
 *
 * 触发点：挂载、窗口尺寸变化、keep-alive 切回来、每次取数之后
 * （搜索栏展开/收起会把表格顶部推下去，那时 top 变了要重算）。
 *
 * ⚠️ 用 `height` 不是 `max-height`。max-height 只封顶不定高：10 条时表格
 * 自然高度没到顶（实测 462 < 514），换成 20 条才长到 514——中间这 52px
 * 是长出来的，分页条就被一路推下去，正是要避免的那个现象。
 * 定高之后行数再怎么变，表体永远是同一个高度，分页条一格不动；
 * 数据不足时表体下方留白，那是 EP 的既有行为。
 */
const tableWrapRef = ref<HTMLElement>()
const pagerRef = ref<HTMLElement>()
const tableHeight = ref<number>()

/** 与 layout/index.vue 的响应式断点一致：窄屏是整页滚，不锁高度 */
const NARROW = 900

const GAP = 12 // --keel-gap
const PANEL_PAD = 16 // --keel-panel-pad
const CONTENT_PAD = 16 // layout 的 .content padding
const SAFETY = 2 // 面板与表格的描边余量

function measure() {
  const el = tableWrapRef.value
  if (!el) return

  if (window.innerWidth <= NARROW) {
    tableHeight.value = undefined
    return
  }

  const top = el.getBoundingClientRect().top

  /*
   * 表格下面要留多少
   *
   * ⚠️ GAP 只在**有分页条**时才算：那 12px 是 `.pagination` 的 margin-top，
   * 没有分页条就没有这段外边距。树形模式（部门、菜单）走的正是这一支——
   * 无脑加 GAP 会让这两个页面底部比别的模块多空 12px，肉眼看得出来。
   *
   * SAFETY 那 2px 是实测补的：不留的话内容区会多出 1px 溢出，
   * 只为这 1px 长出一根滚动条，比表体不滚还难看。
   */
  const pagerH = pagerRef.value?.offsetHeight ?? 0
  const reserve = (pagerH ? pagerH + GAP : 0) + PANEL_PAD + CONTENT_PAD + SAFETY
  // 低于这个高度就没有滚动的意义了，还不如让它自然撑开
  tableHeight.value = Math.max(180, window.innerHeight - top - reserve)
}

function scheduleMeasure() {
  nextTick(measure)
}

onMounted(() => {
  restoreFromUrl()
  if (props.immediate) nextTick(fetch)
  scheduleMeasure()
  window.addEventListener('resize', measure)
  /*
   * 进出全屏也要重算
   *
   * 全屏改变的是视口高度，按说 resize 会跟着发，不必单独监听。
   * 这里仍然显式挂上，是因为「按说会发」一旦不成立，症状是表格高度停在
   * 上一次的值——表体要么滚不到底、要么下面空一截，而且只在用过全屏后出现，
   * 极难联想到根因。多一个监听器的成本远低于这个。
   * 全屏事件先于 resize 到达时 nextTick 会等到布局稳定再量。
   */
  document.addEventListener('fullscreenchange', scheduleMeasure)
  document.addEventListener('webkitfullscreenchange', scheduleMeasure)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', measure)
  document.removeEventListener('fullscreenchange', scheduleMeasure)
  document.removeEventListener('webkitfullscreenchange', scheduleMeasure)
})
onActivated(scheduleMeasure)

defineExpose({ reload, refresh, selected, loading })
</script>

<template>
  <div class="panel pro-table">
    <div class="toolbar">
      <div class="left">
        <slot name="toolbar" :selected="selected" />
      </div>
      <div class="right">
        <el-tooltip content="刷新">
          <el-button :icon="Refresh" circle @click="refresh()" />
        </el-tooltip>

        <el-tooltip content="密度">
          <el-dropdown @command="(cmd: any) => (size = cmd)">
            <el-button :icon="Sort" circle />
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="large">宽松</el-dropdown-item>
                <el-dropdown-item command="default">适中</el-dropdown-item>
                <el-dropdown-item command="small">紧凑</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </el-tooltip>

        <el-popover placement="bottom-end" trigger="click" :width="180">
          <template #reference>
            <el-button :icon="Setting" circle />
          </template>
          <div class="col-settings">
            <el-checkbox
              v-for="col in columns"
              :key="col.prop"
              v-model="visibleMap[col.prop]"
              :label="col.label"
            />
          </div>
        </el-popover>
      </div>
    </div>

    <div ref="tableWrapRef">
      <el-table
        v-loading="loading"
        :data="rows"
        :height="tableHeight"
        :row-key="rowKey"
        :size="size"
        :default-expand-all="tree && defaultExpandAll"
        :tree-props="{ children: 'children' }"
        border
        stripe
        @sort-change="onSortChange"
        @selection-change="onSelectionChange"
      >
      <!-- 与 ID 列一起固定：只固定其中一个的话，固定列会被拉到非固定列左边，
           表头就成了「ID | ☑ | 名称」，勾选框跑到第二个去了 -->
      <el-table-column
        v-if="selection"
        type="selection"
        width="46"
        align="center"
        fixed="left"
        :reserve-selection="true"
      />
      <!--
        ID 列固定在左侧

        `fixed` 不只是为了横向滚动时还能看见——它同时保证 ID 真的是**第一列**：
        用户页的「账号」是 fixed:'left'，非固定列会被它挤到右边去，
        表头就变成「账号 | ID | 姓名」了。固定列之间按模板顺序排，这一列写在
        v-for 之前，于是稳定在最左。

        ⚠️ 树形模式下不出这一列（`&& !tree`）。EP 把展开箭头与层级缩进画在
        **第一个 type=default 的列**上（源码 table-body/render-helper 的
        firstDefaultColumnIndex）。原先那个序号列是 type="index"，会被跳过；
        而 ID 是普通列，一加上去箭头和缩进就从「名称」搬到 ID 上，树看着就散了。
        树形表的第一列本来就该是承载层级的那一列，ID 挪到普通列里去显示。
      -->
      <el-table-column
        v-if="idColumn && !tree"
        prop="id"
        label="ID"
        width="80"
        align="center"
        header-align="center"
        fixed="left"
      />

      <!--
        表头一律居中，正文的对齐仍由列自己的 align 决定。
        表头是标签、正文是数据，两者对齐方式本来就不必一致——
        文字列左对齐读起来顺，而表头居中之后一排看下来是整齐的，
        不会因为「状态」两个字缩在 90px 格子的左边而显得歪。
      -->
      <el-table-column
        v-for="col in shownColumns"
        :key="col.prop"
        :prop="col.prop"
        :label="col.label"
        :width="col.width"
        :min-width="col.minWidth"
        :align="col.align"
        header-align="center"
        :fixed="col.fixed"
        :sortable="col.sortable ? 'custom' : false"
        :show-overflow-tooltip="col.showOverflowTooltip ?? true"
      >
        <template #default="scope">
          <!--
            el-table 的插槽把行给成 DefaultRow（约等于 any），到这里断言回 T：
            断言只此一处，页面拿到的 row 就是真实类型了
          -->
          <slot v-if="col.slot" :name="col.slot" :row="(scope.row as T)" :index="scope.$index" />
          <DictTag v-else-if="col.dict" :code="col.dict" :value="cellValue(scope.row as T, col.prop)" />
          <span v-else>{{ cellText(scope.row as T, col) }}</span>
        </template>
      </el-table-column>

      <template #empty>
        <!--
          区分「一条都没有」和「筛出来是空的」——对用户是两件事：
          前者要的是新建入口，后者要的是把筛选条件清掉。
          清空筛选这件事 ProTable 自己就能做（params 是它 v-model 来的），
          但「新建」它不可能知道，所以默认不给动作，
          需要的页面用 #empty 插槽覆盖：
            <template #empty><EmptyState @action="onCreate" /></template>
        -->
        <slot name="empty" :has-filter="hasFilter">
          <EmptyState
            v-if="hasFilter"
            scene="search"
            :keyword="filterKeyword"
            @action="clearFilters"
          />
          <EmptyState v-else scene="empty" :action="false" />
        </slot>
      </template>
      </el-table>
    </div>

    <div v-if="!tree" ref="pagerRef" class="pagination">
      <el-pagination
        v-model:current-page="pager.pageNum"
        v-model:page-size="pager.pageSize"
        :total="total"
        :page-sizes="[10, 20, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        background
        @current-change="fetch"
        @size-change="reload"
      />
    </div>
  </div>
</template>

<style scoped>
/* 面板外观走全局 .panel（styles/index.css），这里只管排布 */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--keel-gap);
  margin-bottom: var(--keel-gap);
}

.toolbar .left,
.toolbar .right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.col-settings {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.pagination {
  display: flex;
  justify-content: flex-end;
  /* 与工具栏到表格的距离取同一个令牌，上下留白才对称 */
  margin-top: var(--keel-gap);
}

/*
 * 去掉表格底部那条收尾线
 *
 * EP 用 `.el-table__inner-wrapper::before` 在容器最底部画一条 1px 横线。
 * 表格自然高度时它就是最后一行的下边框，没问题；但 ProTable 是**定高**的
 * （见上面「表体滚动」那段），数据不足一页时这条线落在留白的**下方**，
 * 等于把一片空白框成了一个盒子——最后一页只有几条数据时，
 * 看到的是「几行内容 + 一个空盒子 + 分页」，而不是「列表到此为止」。
 *
 * 去掉之后留白直接连到分页条，视觉上是内容自然结束。最后一行自己的
 * `td` 下边框还在，列表末尾照样有收口；外层 `.panel` 的边框也已经
 * 把整块围住了，这条线本来就是重复的。
 *
 * 表体滚动时它同样多余：底部露出半行才是「下面还有」的正确暗示，
 * 压一条实线上去反而像是到底了。
 */
:deep(.el-table__inner-wrapper::before) {
  display: none;
}
</style>
