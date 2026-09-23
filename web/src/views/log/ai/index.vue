<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { View } from '@element-plus/icons-vue'
import {
  fetchAiRun,
  fetchAiRuns,
  fetchAiSummary,
  type AiRunDetail,
  type AiRunRow,
  type AiUsageSummary,
} from '@/api/ai'
import { splitDateRange } from '@/api/log'
import type { ProColumn, ProTableInstance, SearchField } from '@/components'
import type { TableQuery } from '@/types/api'
import { useDictStore } from '@/stores/dict'

/**
 * AI 调用记录（只读，docs/ai-prd.md §8.4）
 *
 * 回答两件事：**花了多少**（次数、token、估算费用、缓存命中率）与**查了什么**
 * （每一次工具调用：以谁的身份、参数、返回几行、有没有因为权限被拒）。
 *
 * **看不到对话内容**，这是刻意的：提问与回答原文在提问人自己的小k 会话里，
 * 审计员要看得走与聊天审计一样的单独流程，而不是在这里点一下就能读别人问了什么。
 */
const dictStore = useDictStore()
const tableRef = ref<ProTableInstance | null>(null)

const query = ref<Record<string, unknown>>({
  keyword: '',
  status: '',
  rating: '',
  date_range: [] as string[],
})

const paramParsers = {
  status: Number,
  rating: Number,
  date_range: (raw: string) => raw.split(',').filter(Boolean),
}

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '提问人', placeholder: '账号 / 姓名' },
  { prop: 'date_range', label: '时间范围', type: 'daterange' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'ai_run_status', numeric: true },
  { prop: 'rating', label: '评价', type: 'dict', dict: 'ai_rating', numeric: true },
]

const columns: ProColumn<AiRunRow>[] = [
  { prop: 'created_at', label: '提问时间', minWidth: 170, align: 'left' },
  { prop: 'user_name', label: '提问人', minWidth: 120, align: 'left', slot: 'user' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'ai_run_status' },
  { prop: 'steps', label: '查询次数', width: 90, align: 'center' },
  { prop: 'tokens', label: 'Token（命中 / 未命中 / 输出）', minWidth: 210, align: 'left', slot: 'tokens' },
  { prop: 'cost_usd', label: '估算费用', minWidth: 110, align: 'right', sortable: true, slot: 'cost' },
  { prop: 'duration_ms', label: '耗时', minWidth: 100, align: 'right', sortable: true, slot: 'duration' },
  { prop: 'rating', label: '评价', width: 80, align: 'center', dict: 'ai_rating' },
  { prop: 'model', label: '模型', minWidth: 130, align: 'left', hidden: true },
  { prop: 'error_msg', label: '失败原因', minWidth: 180, align: 'left', showOverflowTooltip: true, hidden: true },
  { prop: 'trace_id', label: 'TraceID', minWidth: 170, align: 'left', hidden: true },
  { prop: 'actions', label: '操作', width: 90, align: 'center', fixed: 'right', slot: 'actions' },
]

function requestRuns(params: TableQuery) {
  return fetchAiRuns(splitDateRange(params))
}

// ---------------------------------------------------------------- 用量汇总

const summary = ref<AiUsageSummary | null>(null)

async function loadSummary() {
  summary.value = await fetchAiSummary()
}

function onSearch() {
  tableRef.value?.reload()
  void loadSummary()
}

// ---------------------------------------------------------------- 详情

const detailVisible = ref(false)
const detail = ref<AiRunDetail | null>(null)
const detailLoading = ref(false)

async function onView(row: AiRunRow) {
  detailVisible.value = true
  detail.value = null
  detailLoading.value = true
  try {
    detail.value = await fetchAiRun(row.id)
  } finally {
    detailLoading.value = false
  }
}

function fmtMs(ms: number): string {
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${ms} ms`
}

function fmtUsd(v: string): string {
  return `$${Number(v).toFixed(4)}`
}

onMounted(() => {
  void dictStore.preload(['ai_run_status', 'ai_rating'])
  void loadSummary()
})
</script>

<template>
  <div class="page">
    <!-- 需要管理员处理的故障放最上面：余额不足时全员的小k 都在失败，这是这一页最该被看到的东西 -->
    <el-alert
      v-if="summary?.alert"
      type="error"
      :closable="false"
      show-icon
      class="alert"
      :title="`${summary.alert.message}（${summary.alert.at}）`"
      description="在「系统配置 / 参数配置 / 集成」里检查 DeepSeek 的密钥与余额，改完点「测试连接」确认。"
    />

    <div v-if="summary" class="usage">
      <div class="usage__card">
        <div class="usage__label">今天</div>
        <div class="usage__value">{{ summary.today.runs }} <small>次</small></div>
        <div class="usage__hint">
          失败 {{ summary.today.failed }} · 约 {{ fmtUsd(summary.today.cost_usd) }}
        </div>
      </div>
      <div class="usage__card">
        <div class="usage__label">本月</div>
        <div class="usage__value">{{ summary.month.runs }} <small>次</small></div>
        <div class="usage__hint">
          约 {{ fmtUsd(summary.month.cost_usd) }}
          <template v-if="summary.budget > 0"> / 预算 ${{ summary.budget }}</template>
        </div>
      </div>
      <div class="usage__card">
        <div class="usage__label">本月缓存命中率</div>
        <div class="usage__value">
          {{ summary.month.cache_hit_rate === null ? '—' : summary.month.cache_hit_rate }}<small v-if="summary.month.cache_hit_rate !== null">%</small>
        </div>
        <div class="usage__hint">越高越省钱；长期为 0 说明提示词前缀里混进了变量</div>
      </div>
    </div>

    <SearchForm v-model="query" :fields="searchFields" @search="onSearch" @reset="onSearch" />

    <ProTable
      ref="tableRef"
      v-model:params="query"
      :request="requestRuns"
      :param-parsers="paramParsers"
      :columns="columns"
      id-column
    >
      <template #toolbar>
        <span class="hint">费用按 DeepSeek 单价估算（区分高峰），对账以 DeepSeek 控制台为准；这里不含对话内容</span>
      </template>

      <template #user="{ row }">
        <span>{{ row.user_name }}</span>
        <span class="sub">{{ row.username }}</span>
      </template>

      <template #tokens="{ row }">
        <span class="mono">{{ row.cache_hit_tokens }} / {{ row.cache_miss_tokens }} / {{ row.output_tokens }}</span>
      </template>

      <template #cost="{ row }">
        <span class="mono">{{ fmtUsd(row.cost_usd) }}</span>
      </template>

      <template #duration="{ row }">
        <span class="mono">{{ row.duration_ms ? fmtMs(row.duration_ms) : '—' }}</span>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button v-permission="'ai:log:detail'" :icon="View" link type="primary" @click="onView(row)">
            详情
          </el-button>
        </div>
      </template>
    </ProTable>

    <el-drawer v-model="detailVisible" title="AI 调用详情" size="680px">
      <div v-loading="detailLoading">
        <template v-if="detail">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="提问时间">{{ detail.created_at }}</el-descriptions-item>
            <el-descriptions-item label="提问人">{{ detail.user_name }}（{{ detail.username }}）</el-descriptions-item>
            <el-descriptions-item label="状态">
              <DictTag code="ai_run_status" :value="detail.status" />
            </el-descriptions-item>
            <el-descriptions-item label="评价">
              <DictTag code="ai_rating" :value="detail.rating" />
            </el-descriptions-item>
            <el-descriptions-item label="模型">{{ detail.model }}（思考强度 {{ detail.reasoning_effort || '—' }}）</el-descriptions-item>
            <el-descriptions-item label="轮数 / 查询">{{ detail.rounds }} 轮 / {{ detail.steps }} 次</el-descriptions-item>
            <el-descriptions-item label="首字延迟">{{ detail.first_token_ms ? fmtMs(detail.first_token_ms) : '—' }}</el-descriptions-item>
            <el-descriptions-item label="总耗时">{{ detail.duration_ms ? fmtMs(detail.duration_ms) : '—' }}</el-descriptions-item>
            <el-descriptions-item label="Token" :span="2">
              缓存命中 {{ detail.cache_hit_tokens }} · 未命中 {{ detail.cache_miss_tokens }} ·
              输出 {{ detail.output_tokens }}（其中思考 {{ detail.reasoning_tokens }}）
            </el-descriptions-item>
            <el-descriptions-item label="估算费用">{{ fmtUsd(detail.cost_usd) }}</el-descriptions-item>
            <el-descriptions-item label="TraceID"><code>{{ detail.trace_id }}</code></el-descriptions-item>
            <el-descriptions-item v-if="detail.error_msg" label="失败原因" :span="2">
              <span class="error">{{ detail.error_msg }}</span>
              <span v-if="detail.http_status" class="sub">HTTP {{ detail.http_status }}</span>
            </el-descriptions-item>
            <el-descriptions-item v-if="detail.feedback" label="反馈" :span="2">{{ detail.feedback }}</el-descriptions-item>
          </el-descriptions>

          <!-- 这一页最重要的部分：小k 以谁的身份、查了什么 -->
          <h4>查询了什么（{{ detail.tool_calls.length }}）</h4>
          <el-table v-if="detail.tool_calls.length" :data="detail.tool_calls" size="small" border>
            <el-table-column label="查询" min-width="200">
              <template #default="{ row }">
                <div>{{ row.label || row.tool }}</div>
                <code class="sub">{{ row.tool }}</code>
              </template>
            </el-table-column>
            <el-table-column label="参数" min-width="180">
              <template #default="{ row }">
                <code class="args">{{ row.args ? JSON.stringify(row.args) : '—' }}</code>
              </template>
            </el-table-column>
            <el-table-column label="结果" width="130">
              <template #default="{ row }">
                <el-tag v-if="row.denied" type="danger" size="small">无权限</el-tag>
                <span v-else-if="row.error_msg" class="error">{{ row.error_msg }}</span>
                <span v-else>{{ row.result_total }} 条<span class="sub">（给了 {{ row.result_rows }}）</span></span>
              </template>
            </el-table-column>
            <el-table-column label="执行身份" width="90" align="center">
              <template #default="{ row }">
                <!-- 与提问人不一致 = 消费进程里身份串号了，这是必须立刻处理的事故 -->
                <el-tag v-if="row.acting_user_id !== detail.user_id" type="danger" size="small">异常 #{{ row.acting_user_id }}</el-tag>
                <span v-else class="sub">提问人</span>
              </template>
            </el-table-column>
          </el-table>
          <EmptyState v-else description="这次问答没有查询数据" :size="60" :action="false" />
          <p class="hint">提问与回答原文不在审计范围内，只保存在提问人自己的小k 会话里</p>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.alert {
  margin-bottom: 12px;
}

.usage {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.usage__card {
  padding: 14px 16px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  background: var(--el-bg-color);
}

.usage__label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.usage__value {
  margin: 4px 0;
  font-size: 22px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.usage__value small {
  margin-left: 2px;
  font-size: 13px;
  font-weight: 400;
  color: var(--el-text-color-secondary);
}

.usage__hint {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.sub {
  margin-left: 6px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.mono {
  font-variant-numeric: tabular-nums;
}

.args {
  font-size: 12px;
  word-break: break-all;
}

.error {
  color: var(--el-color-danger);
}

h4 {
  margin: 18px 0 8px;
  font-size: 14px;
}
</style>
