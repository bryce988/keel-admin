<script setup lang="ts">
import { computed, ref } from 'vue'
import { fetchTaskLogs, type QueueTaskRow, type TaskLogRow } from '@/api/queue'
import type { TableQuery } from '@/types/api'
import { useDictStore } from '@/stores/dict'

/**
 * 单个定时任务的历史执行记录
 *
 * 执行记录是**跟着任务走**的：它回答的是「log-cleanup 昨天那次跑没跑、删了多少行」，
 * 只有在盯着某个任务时才有意义。做成页面上平铺的一大块，等于让人先在一张混着
 * 所有任务的列表里筛出自己要看的那个——多一步，且大部分时候用不上。
 *
 * 抽屉里不用 ProTable：它自带 URL 同步与定高测量，两样在抽屉里都用不上
 * （抽屉的开合不该改 URL，也没有「表头固定、表体滚」的需求）。
 * 一次取 50 条 + 「加载更多」，比塞一个分页器轻。
 */
const dictStore = useDictStore()

const visible = ref(false)
const task = ref<QueueTaskRow | null>(null)
const rows = ref<TaskLogRow[]>([])
const total = ref(0)
const loading = ref(false)
/** DictSelect 在 numeric 下给的是 number，清空时是 undefined */
const status = ref<string | number>('')

const PAGE_SIZE = 50
const pageNum = ref(1)

const hasMore = computed(() => rows.value.length < total.value)

function open(target: QueueTaskRow) {
  task.value = target
  visible.value = true
  status.value = ''
  reload()
}

async function reload() {
  pageNum.value = 1
  rows.value = []
  await load()
}

async function load() {
  if (!task.value) return

  loading.value = true
  try {
    const result = await fetchTaskLogs({
      task_name: task.value.name,
      status: status.value ?? '',
      page_num: pageNum.value,
      page_size: PAGE_SIZE
    } as unknown as TableQuery)

    // 追加而不是覆盖：「加载更多」要接在已有记录后面
    rows.value = pageNum.value === 1 ? result.list : [...rows.value, ...result.list]
    total.value = result.total
  } finally {
    loading.value = false
  }
}

function loadMore() {
  pageNum.value += 1
  load()
}

/** 最近一次执行的结果，抽屉标题下面直接给——多数时候人就是来看这一条的 */
const latest = computed(() => rows.value[0] ?? null)

dictStore.preload(['task_log_status'])

defineExpose({ open })
</script>

<template>
  <el-drawer
    v-model="visible"
    :title="task ? `运行日志 · ${task.name}` : '运行日志'"
    size="720px"
    direction="rtl"
  >
    <div v-loading="loading">
      <div class="meta">
        <span class="desc">{{ task?.desc }}</span>
        <span class="rule">
          <code>{{ task?.rule }}</code> → {{ task?.queue }}
        </span>
      </div>

      <div class="bar">
        <span class="count">
          共 {{ total }} 次执行<template v-if="latest">
            · 最近一次
            <DictTag code="task_log_status" :value="latest.status" />
          </template>
        </span>
        <DictSelect
          v-model="status"
          code="task_log_status"
          numeric
          clearable
          placeholder="全部状态"
          class="filter"
          @change="reload"
        />
      </div>

      <el-table :data="rows" size="small" border>
        <el-table-column label="状态" width="90" align="center">
          <template #default="scope">
            <DictTag code="task_log_status" :value="(scope.row as TaskLogRow).status" />
          </template>
        </el-table-column>
        <el-table-column label="耗时" width="90" align="center">
          <template #default="scope">
            <!-- 没跑完时耗时是 0，显示 0ms 会让人以为它瞬间就完了 -->
            {{ (scope.row as TaskLogRow).finished_at ? `${(scope.row as TaskLogRow).duration_ms} ms` : '—' }}
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="投递时间" width="160" align="center" />
        <el-table-column label="结果" min-width="220">
          <template #default="scope">
            <span v-if="!(scope.row as TaskLogRow).message" class="pending">等待消费进程处理</span>
            <code v-else class="payload">{{ (scope.row as TaskLogRow).message }}</code>
          </template>
        </el-table-column>

        <template #empty>
          <EmptyState
            :description="status ? '该状态下没有执行记录' : '这个任务还没有执行过'"
            :action="false"
          />
        </template>
      </el-table>

      <div v-if="hasMore" class="more">
        <el-button link type="primary" @click="loadMore">
          加载更多（已显示 {{ rows.length }} / {{ total }}）
        </el-button>
      </div>
    </div>
  </el-drawer>
</template>

<style scoped>
.meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: var(--keel-gap);
}

.meta .desc {
  color: var(--el-text-color-primary);
}

.meta .rule {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: var(--keel-gap);
}

/* 13px 是页签专用的一档，这里是辅助说明，走 12px */
.bar .count {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.filter {
  width: 160px;
}

.pending {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.payload {
  font-size: 12px;
  color: var(--el-text-color-regular);
  word-break: break-all;
}

.more {
  margin-top: var(--keel-gap);
  text-align: center;
}
</style>
