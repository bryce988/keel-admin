<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
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
    request: (params: TableQuery) => Promise<PageResult>
    columns: ProColumn[]
    /** 筛选条件，变化时不自动请求，由页面显式调用 reload() */
    params?: Record<string, unknown>
    rowKey?: string
    selection?: boolean
    /** 挂载时是否立即取数 */
    immediate?: boolean
    pageSize?: number
    /** 序号列 */
    index?: boolean
  }>(),
  { rowKey: 'id', selection: false, immediate: true, pageSize: 20, index: false }
)

const emit = defineEmits<{
  'selection-change': [rows: Record<string, any>[]]
  loaded: [result: PageResult]
}>()

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

async function fetch() {
  loading.value = true
  try {
    const result = await props.request({
      ...(props.params ?? {}),
      page_num: pager.pageNum,
      page_size: pager.pageSize,
      sort_field: pager.sortField || undefined,
      sort_order: pager.sortOrder
    })

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

/** 回到第 1 页重新取数：筛选条件变化时用 */
function reload() {
  pager.pageNum = 1
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

onMounted(() => {
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

    <div class="pagination">
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
