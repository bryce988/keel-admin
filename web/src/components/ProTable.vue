<script setup lang="ts">
import { computed, nextTick, onActivated, onDeactivated, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Refresh, Setting, Sort } from '@element-plus/icons-vue'
import DictTag from './DictTag.vue'

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
 */
export interface ProColumn {
  prop: string
  label: string
  width?: number | string
  minWidth?: number | string
  align?: 'left' | 'center' | 'right'
  fixed?: boolean | 'left' | 'right'
  sortable?: boolean
  /** 用同名具名插槽渲染单元格 */
  slot?: string
  /** 直接按数据字典渲染标签 */
  dict?: string
  formatter?: (row: Record<string, any>) => string
  showOverflowTooltip?: boolean
  /** 默认隐藏，可在「列设置」里打开 */
  hidden?: boolean
}

/** 接口结构，字段名与后端逐字一致（docs/api.md §1.3） */
export interface PageResult<T = Record<string, any>> {
  list: T[]
  total: number
  page_num: number
  page_size: number
}

export interface TableQuery {
  page_num: number
  page_size: number
  sort_field?: string
  sort_order?: 'asc' | 'desc'
  [key: string]: unknown
}

const props = withDefaults(
  defineProps<{
    /** 取数函数。列表模式返回分页结构，树形模式返回数组 */
    request: (params: TableQuery) => Promise<PageResult | Record<string, any>[]>
    columns: ProColumn[]
    /**
     * 筛选条件，变化时不自动请求，由页面显式调用 reload()。
     * 用 `v-model:params` 绑定才能在刷新后把 URL 里的条件还原回页面
     */
    params?: Record<string, unknown>
    /**
     * 从 URL 还原筛选值时的类型转换。
     * URL 里的东西**永远是字符串**，而 el-select 的选项常常是数字，
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
    /** 序号列 */
    index?: boolean
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
    pageSize: 20,
    index: false,
    syncUrl: true,
    tree: false,
    defaultExpandAll: true
  }
)

const emit = defineEmits<{
  'selection-change': [rows: Record<string, any>[]]
  loaded: [result: PageResult]
  'update:params': [value: Record<string, unknown>]
}>()

const route = useRoute()
const router = useRouter()

/**
 * keep-alive 下组件失活后仍然活着，此时**绝不能写 URL**——
 * 那会把当前正在看的另一个页签的地址栏改掉。
 */
let alive = true
onActivated(() => (alive = true))
onDeactivated(() => (alive = false))

const loading = ref(false)
const rows = ref<Record<string, any>[]>([])
const total = ref(0)
const selected = ref<Record<string, any>[]>([])
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
    const result: PageResult = Array.isArray(raw)
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
 * 少了这一拍，fetch() 读到的是**上一次**的筛选值——
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

function onSortChange({ prop, order }: { prop: string; order: string | null }) {
  pager.sortField = order ? prop : undefined
  pager.sortOrder = order === 'ascending' ? 'asc' : order === 'descending' ? 'desc' : undefined
  reload()
}

function onSelectionChange(value: Record<string, any>[]) {
  selected.value = value
  emit('selection-change', value)
}

function cellText(row: Record<string, any>, col: ProColumn): string {
  if (col.formatter) return col.formatter(row)
  const value = row[col.prop]
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

onMounted(() => {
  restoreFromUrl()
  if (props.immediate) nextTick(fetch)
})

defineExpose({ reload, refresh, selected, loading })
</script>

<template>
  <div class="pro-table">
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
                <el-dropdown-item command="default">默认</el-dropdown-item>
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

    <el-table
      v-loading="loading"
      :data="rows"
      :row-key="rowKey"
      :size="size"
      :default-expand-all="tree && defaultExpandAll"
      :tree-props="{ children: 'children' }"
      border
      stripe
      @sort-change="onSortChange"
      @selection-change="onSelectionChange"
    >
      <el-table-column v-if="selection" type="selection" width="46" align="center" :reserve-selection="true" />
      <el-table-column v-if="index" type="index" label="#" width="56" align="center" />

      <el-table-column
        v-for="col in shownColumns"
        :key="col.prop"
        :prop="col.prop"
        :label="col.label"
        :width="col.width"
        :min-width="col.minWidth"
        :align="col.align"
        :fixed="col.fixed"
        :sortable="col.sortable ? 'custom' : false"
        :show-overflow-tooltip="col.showOverflowTooltip ?? true"
      >
        <template #default="scope">
          <slot v-if="col.slot" :name="col.slot" :row="scope.row" :index="scope.$index" />
          <DictTag v-else-if="col.dict" :code="col.dict" :value="scope.row[col.prop]" />
          <span v-else>{{ cellText(scope.row, col) }}</span>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无数据" :image-size="90" />
      </template>
    </el-table>

    <div v-if="!tree" class="pagination">
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
.pro-table {
  padding: 16px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: var(--el-border-radius-base);
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
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
  margin-top: 16px;
}
</style>
