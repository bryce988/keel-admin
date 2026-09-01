<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download } from '@element-plus/icons-vue'
import { exportLoginLogs, fetchLoginLogs, splitDateRange, type LoginLogRow } from '@/api/log'
import type { ProColumn, ProTableInstance, SearchField, TableQuery } from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 登录日志（只读）
 *
 * 登录失败也要记（含失败原因）——连续失败锁定的审计依据就在这里，
 * 排查「我的账号是不是被人试密码了」也只能靠它。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)

const query = ref<Record<string, unknown>>({
  keyword: '',
  type: '',
  status: '',
  date_range: [] as string[]
})

const paramParsers = {
  type: Number,
  status: Number,
  date_range: (raw: string) => raw.split(',').filter(Boolean)
}

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '账号 / IP / 登录地址' },
  { prop: 'date_range', label: '时间范围', type: 'daterange' },
  { prop: 'type', label: '类型', type: 'dict', dict: 'login_type', numeric: true },
  { prop: 'status', label: '结果', type: 'dict', dict: 'log_status', numeric: true }
]

const columns: ProColumn<LoginLogRow>[] = [
  { prop: 'created_at', label: '时间', minWidth: 190, align: 'center', sortable: true },
  { prop: 'username', label: '账号', minWidth: 130, align: 'center' },
  { prop: 'ip', label: 'IP', minWidth: 150, align: 'center' },
  { prop: 'location', label: '登录地址', minWidth: 200, showOverflowTooltip: true },
  { prop: 'browser', label: '浏览器', minWidth: 110, align: 'center' },
  { prop: 'os', label: '操作系统', minWidth: 110, align: 'center' },
  { prop: 'type', label: '类型', width: 90, align: 'center', dict: 'login_type' },
  { prop: 'status', label: '结果', width: 90, align: 'center', dict: 'log_status' },
  { prop: 'msg', label: '说明', minWidth: 160, slot: 'msg' }
]

function requestLogs(params: TableQuery) {
  return fetchLoginLogs(splitDateRange(params))
}

const exporting = ref(false)

async function onExport() {
  exporting.value = true
  try {
    // 导出是异步的：这里拿到的是任务回执，不是文件
    const { message } = await exportLoginLogs(splitDateRange(query.value))
    ElMessage.success(message)
  } finally {
    exporting.value = false
  }
}

onMounted(() => dictStore.preload(['login_type', 'log_status']))
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
      :request="requestLogs"
      :param-parsers="paramParsers"
      :columns="columns"
      id-column
    >
      <template #toolbar>
        <el-button
          v-permission="'sys:log:login:export'"
          :icon="Download"
          :loading="exporting"
          @click="onExport"
        >
          导出
        </el-button>
        <span class="hint">不选时间范围时默认查最近 7 天</span>
      </template>

      <template #msg="{ row }">
        <span :class="{ error: !row.status }">{{ row.msg || '—' }}</span>
      </template>
    </ProTable>
  </div>
</template>

<style scoped>
.hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.error {
  color: var(--el-color-danger);
}
</style>
