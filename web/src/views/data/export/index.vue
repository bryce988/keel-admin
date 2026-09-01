<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Download, Refresh } from '@element-plus/icons-vue'
import {
  deleteExportTask,
  downloadExportTask,
  fetchExportTasks,
  type ExportTaskRow
} from '@/api/export'
import type { ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 数据导出
 *
 * 导出改成异步之后，用户点「导出」拿到的不再是文件而是一条任务，
 * 这一页就是任务的落点：看进度、下载、删。
 *
 * 页面本身只是标准列表页，唯一特别的是**自动轮询**：任务在队列里跑，
 * 状态是后端改的，不刷新的话界面会永远停在「排队中」，
 * 而用户刚点完导出，最想知道的就是好没好。
 */
const dictStore = useDictStore()
const tableRef = ref<ProTableInstance | null>(null)

const query = ref<Record<string, unknown>>({ biz: '', status: '' })
const paramParsers = { status: Number }

const searchFields: SearchField[] = [
  { prop: 'biz', label: '业务类型', type: 'dict', dict: 'export_biz' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'export_status', numeric: true }
]

const columns: ProColumn<ExportTaskRow>[] = [
  { prop: 'biz', label: '业务类型', width: 120, align: 'center', dict: 'export_biz' },
  { prop: 'status', label: '状态', width: 100, align: 'center', slot: 'status' },
  { prop: 'row_count', label: '行数', width: 90, align: 'center' },
  { prop: 'file_size', label: '大小', width: 100, align: 'center', slot: 'size' },
  { prop: 'creator_name', label: '发起人', width: 110, align: 'center' },
  { prop: 'created_at', label: '发起时间', minWidth: 170, align: 'center', sortable: true },
  { prop: 'finished_at', label: '完成时间', minWidth: 170, align: 'center', sortable: true },
  { prop: 'expired_at', label: '过期时间', minWidth: 170, align: 'center', hidden: true },
  { prop: 'actions', label: '操作', width: 160, align: 'center', fixed: 'right', slot: 'actions' }
]

const rows = ref<ExportTaskRow[]>([])

/** 还没跑完的任务数：决定要不要继续轮询 */
const pendingCount = computed(() => rows.value.filter((r) => r.status <= 1).length)

async function request(params: Record<string, unknown>) {
  const result = await fetchExportTasks(params as never)
  rows.value = result.list
  return result
}

/*
 * 轮询
 *
 * 只在「当前这页有没跑完的任务」时才发请求——一个只有历史记录的页面
 * 每 3 秒打一次接口，纯粹是给服务器加压。任务全部完成后自动停下，
 * 用户重新发起导出、或切筛选把排队中的任务翻出来时又会自己启动。
 */
const POLL_MS = 3000
let timer = 0 as ReturnType<typeof setInterval> | 0

function tick() {
  if (pendingCount.value === 0) return
  if (document.visibilityState !== 'visible') return

  // refresh 而不是 reload：轮询不该把用户翻到的页码重置回第一页
  tableRef.value?.refresh()
}

onMounted(() => {
  dictStore.preload(['export_status', 'export_biz'])
  timer = setInterval(tick, POLL_MS)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  timer = 0
})

// ---------------------------------------------------------------- 动作
const downloading = ref(0)

async function onDownload(row: ExportTaskRow) {
  downloading.value = row.id
  try {
    await downloadExportTask(row)
  } finally {
    downloading.value = 0
  }
}

async function onDelete(row: ExportTaskRow) {
  await ElMessageBox.confirm(
    `确定删除这条导出记录吗？文件会一并删除，不可恢复。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteExportTask(row.id)
  ElMessage.success('已删除')
  tableRef.value?.refresh()
}

/** 字节数转可读大小。列表里给的是数字，直接显示 1048576 没人看得懂 */
function formatSize(bytes: number): string {
  if (!bytes) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
</script>

<template>
  <div class="page">
    <SearchForm
      v-model="query"
      :fields="searchFields"
      @search="tableRef?.reload()"
      @reset="tableRef?.reload()"
    />

    <ProTable
      ref="tableRef"
      v-model:params="query"
      :request="request"
      :param-parsers="paramParsers"
      :columns="columns"
      id-column
    >
      <template #toolbar>
        <el-button :icon="Refresh" @click="tableRef?.refresh()">刷新</el-button>
        <!-- 有任务在跑时给一句说明：否则用户不知道这一页会自己变 -->
        <span v-if="pendingCount" class="polling-tip">
          {{ pendingCount }} 个任务处理中，页面会自动刷新
        </span>
      </template>

      <template #status="{ row }">
        <div class="status-cell">
          <DictTag code="export_status" :value="row.status" />
          <!-- 失败原因就地给出：让人为了看一句话再点进详情是没必要的 -->
          <el-tooltip v-if="row.error_msg" :content="row.error_msg" placement="top">
            <span class="error-msg">{{ row.error_msg }}</span>
          </el-tooltip>
        </div>
      </template>

      <template #size="{ row }">{{ formatSize(row.file_size) }}</template>

      <template #actions="{ row }">
        <div class="table-actions">
          <!--
            下载按钮的可用性看 downloadable，不看 status
            ——文件可能已经被回收，而状态仍是「已完成」
          -->
          <el-button
            :icon="Download"
            link
            type="primary"
            :disabled="!row.downloadable"
            :loading="downloading === row.id"
            @click="onDownload(row)"
          >
            下载
          </el-button>
          <el-button
            v-permission="'sys:export:delete'"
            :icon="Delete"
            link
            type="danger"
            @click="onDelete(row)"
          >
            删除
          </el-button>
        </div>
      </template>
    </ProTable>
  </div>
</template>

<style scoped>
.polling-tip {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.status-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.error-msg {
  max-width: 100%;
  font-size: 12px;
  color: var(--el-color-danger);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
</style>
