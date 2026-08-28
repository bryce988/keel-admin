<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, View } from '@element-plus/icons-vue'
import {
  exportOperationLogs,
  fetchOperationLog,
  fetchOperationLogs,
  splitDateRange,
  type OperationLogDetail,
  type OperationLogRow
} from '@/api/log'
import type { ProColumn, ProTableInstance, SearchField, TableQuery } from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 操作日志（只读）
 *
 * 所有写操作的审计留痕。越权被拒的尝试同样在这里——
 * 「谁试图做什么但被拒了」和「谁做成了什么」在审计上一样重要。
 *
 * 日志本身也受数据权限约束：部门主管只看得到本部门的记录，
 * 这是后端全局 Scope 干的事，前端不做任何过滤。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)

const query = ref<Record<string, unknown>>({
  keyword: '',
  action: '',
  status: '',
  module: '',
  trace_id: '',
  date_range: [] as string[]
})

const paramParsers = {
  action: Number,
  status: Number,
  // URL 里区间是 "2026-08-10,2026-08-17" 这样一个串，还原时要拆回数组
  date_range: (raw: string) => raw.split(',').filter(Boolean)
}

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '操作人 / 描述 / 对象' },
  { prop: 'date_range', label: '时间范围', type: 'daterange' },
  { prop: 'action', label: '操作类型', type: 'dict', dict: 'log_action', numeric: true },
  { prop: 'status', label: '执行结果', type: 'dict', dict: 'log_status', numeric: true },
  { prop: 'module', label: '模块', placeholder: '如 系统管理/用户' },
  { prop: 'trace_id', label: 'TraceID', placeholder: '报错弹窗里的那串' }
]

const columns: ProColumn<OperationLogRow>[] = [
  { prop: 'created_at', label: '时间', minWidth: 190, align: 'center', sortable: true },
  { prop: 'username', label: '操作人', minWidth: 130, align: 'center' },
  { prop: 'module', label: '模块', minWidth: 160, align: 'center' },
  { prop: 'action', label: '类型', width: 90, align: 'center', dict: 'log_action' },
  { prop: 'title', label: '描述', minWidth: 140, align: 'center' },
  { prop: 'target', label: '操作对象', minWidth: 180, showOverflowTooltip: true },
  { prop: 'status', label: '结果', width: 90, align: 'center', slot: 'status' },
  { prop: 'duration', label: '耗时', minWidth: 100, align: 'center', sortable: true, slot: 'duration' },
  { prop: 'ip', label: 'IP', minWidth: 150, align: 'center', hidden: true },
  { prop: 'api_path', label: '接口', minWidth: 200, hidden: true, slot: 'api' },
  { prop: 'trace_id', label: 'TraceID', minWidth: 170, align: 'center', hidden: true },
  { prop: 'actions', label: '操作', width: 120, align: 'center', fixed: 'right', slot: 'actions' }
]

function requestLogs(params: TableQuery) {
  return fetchOperationLogs(splitDateRange(params))
}

const exporting = ref(false)

async function onExport() {
  exporting.value = true
  try {
    await exportOperationLogs(splitDateRange(query.value))
    ElMessage.success('导出完成')
  } finally {
    exporting.value = false
  }
}

// ---------------------------------------------------------------- 详情
const detailVisible = ref(false)
const detail = ref<OperationLogDetail | null>(null)
const detailLoading = ref(false)

async function onView(row: OperationLogRow) {
  detailVisible.value = true
  detail.value = null
  detailLoading.value = true

  try {
    // 列表不带 params / changes 两个大 JSON 字段，详情才单独取
    detail.value = await fetchOperationLog(row.id)
  } finally {
    detailLoading.value = false
  }
}

/** 值可能是对象/数组/null，统一转成可读文本，不让界面上出现 [object Object] */
function displayValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '（空）'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)

  return String(value)
}

function prettyJson(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

onMounted(() => dictStore.preload(['log_action', 'log_status']))
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
          v-permission="'sys:log:operation:export'"
          :icon="Download"
          :loading="exporting"
          @click="onExport"
        >
          导出
        </el-button>
        <span class="hint">不选时间范围时默认查最近 7 天</span>
      </template>

      <!-- 失败的那一行把原因直接挂在标签后面，不用点进详情才知道为什么失败 -->
      <template #status="{ row }">
        <el-tooltip v-if="!row.status" :content="row.error_msg || '失败'">
          <el-tag type="danger" size="small">失败</el-tag>
        </el-tooltip>
        <el-tag v-else type="success" size="small">成功</el-tag>
      </template>

      <template #duration="{ row }">
        <span :class="{ slow: row.duration >= 1000 }">{{ row.duration }} ms</span>
      </template>

      <template #api="{ row }">
        <code>{{ row.api_method }} {{ row.api_path }}</code>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button :icon="View" link type="primary" @click="onView(row)">
            详情
            <el-badge v-if="row.change_count" :value="row.change_count" class="chg" />
          </el-button>
        </div>
      </template>
    </ProTable>

    <el-drawer v-model="detailVisible" title="操作详情" size="640px">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="时间">{{ detail.created_at }}</el-descriptions-item>
            <el-descriptions-item label="操作人">{{ detail.username }}</el-descriptions-item>
            <el-descriptions-item label="模块">{{ detail.module }}</el-descriptions-item>
            <el-descriptions-item label="类型">
              <DictTag code="log_action" :value="detail.action" />
            </el-descriptions-item>
            <el-descriptions-item label="描述">{{ detail.title }}</el-descriptions-item>
            <el-descriptions-item label="结果">
              <DictTag code="log_status" :value="detail.status" />
            </el-descriptions-item>
            <el-descriptions-item label="操作对象" :span="2">
              {{ detail.target || '—' }}
            </el-descriptions-item>
            <el-descriptions-item label="接口" :span="2">
              <code>{{ detail.api_method }} {{ detail.api_path }}</code>
            </el-descriptions-item>
            <el-descriptions-item label="IP">{{ detail.ip }}</el-descriptions-item>
            <el-descriptions-item label="耗时">{{ detail.duration }} ms</el-descriptions-item>
            <el-descriptions-item label="TraceID" :span="2">
              <code>{{ detail.trace_id }}</code>
            </el-descriptions-item>
            <el-descriptions-item v-if="!detail.status" label="失败原因" :span="2">
              <span class="error">{{ detail.error_msg }}</span>
            </el-descriptions-item>
            <el-descriptions-item label="客户端" :span="2">
              <span class="ua">{{ detail.user_agent || '—' }}</span>
            </el-descriptions-item>
          </el-descriptions>

          <!-- 字段级变更：日志最有价值的部分，所以放在参数前面 -->
          <h4>字段变更<span v-if="detail.changes.length">（{{ detail.changes.length }}）</span></h4>
          <el-table v-if="detail.changes.length" :data="detail.changes" size="small" border>
            <el-table-column prop="field" label="字段" width="180" />
            <el-table-column label="修改前">
              <template #default="{ row }">
                <span class="old">{{ displayValue(row.old) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="修改后">
              <template #default="{ row }">
                <span class="new">{{ displayValue(row.new) }}</span>
              </template>
            </el-table-column>
          </el-table>
          <EmptyState v-else description="这次操作没有字段级变更" :size="60" :action="false" />

          <h4>请求参数</h4>
          <pre class="json">{{ prettyJson(detail.params) }}</pre>
          <p class="hint">密码、密钥、验证码等字段在落库前已替换为掩码，日志里不存明文</p>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.slow {
  color: var(--el-color-warning);
}

.chg {
  margin-left: 2px;
}

h4 {
  margin: 20px 0 8px;
  font-size: 14px;
  font-weight: 500;
  color: var(--el-text-color-primary);
}

.json {
  margin: 0;
  padding: 12px;
  border-radius: 4px;
  background: var(--el-fill-color-light);
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 320px;
  overflow: auto;
}

.old {
  color: var(--el-text-color-secondary);
  text-decoration: line-through;
}

.new {
  color: var(--el-color-success);
}

.error {
  color: var(--el-color-danger);
}

.ua {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  word-break: break-all;
}

code {
  padding: 0 4px;
  border-radius: 3px;
  background: var(--el-fill-color-light);
  font-size: 12px;
}
</style>
