<script setup lang="ts">
import { computed, onActivated, onDeactivated, onMounted, onUnmounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Refresh, RefreshRight, Tickets } from '@element-plus/icons-vue'
import {
  discardFailedJob,
  fetchFailedJobs,
  fetchQueueOverview,
  retryFailedJob,
  type FailedJobRow,
  type QueueOverview,
  type QueueTaskRow
} from '@/api/queue'
import TaskLogDrawer from './TaskLogDrawer.vue'
import type { ProColumn, ProTableInstance, SearchField } from '@/components'

/**
 * 队列监控
 *
 * 回答三个问题：跑着几个队列、积压多少、失败的那些怎么办。
 *
 * 页面分两截，取数也是两条独立的链路：
 * - 上半截「总览」是 Redis 的实时快照，没有分页，自己定时刷新
 * - 下半截「失败任务」是标准列表页，走 ProTable（筛选条件同步进 URL）
 *
 * 没有「立即执行」按钮：定时任务进程是 `count => 1` 的独占设计，
 * 手动再触发一次等于让同一个清理任务并发跑两遍。
 */
const overview = ref<QueueOverview | null>(null)
const loading = ref(false)
const tableRef = ref<ProTableInstance | null>(null)

/*
 * 总览定时刷新
 *
 * 积压数是「现在」的值，停在十分钟前的快照上会让人对着一个早就消化完的数字排查。
 *
 * 倒计时不只是装饰：这一页的数字会自己变，不给个「几秒后刷新」，
 * 用户分不清「刚看到的 0 是最新的」还是「页面停在十分钟前」，
 * 也不知道该不该手动点刷新。
 */
const POLL_SECONDS = 15
const countdown = ref(POLL_SECONDS)
let ticker = 0 as ReturnType<typeof setInterval> | 0

async function loadOverview() {
  // 每次取数都把倒计时归位：手动点刷新之后再等 3 秒就自动刷一次会很怪
  countdown.value = POLL_SECONDS
  loading.value = true
  try {
    overview.value = await fetchQueueOverview()
  } finally {
    loading.value = false
  }
}

/** 浏览器标签在后台时倒计时**停住**而不是继续走：不发请求，也就不该显示还在倒数 */
const paused = ref(false)

function tick() {
  paused.value = document.visibilityState !== 'visible'
  if (paused.value || loading.value) return

  if (countdown.value > 1) {
    countdown.value--
    return
  }
  loadOverview()
}

/**
 * 标签页切回前台时立刻刷一次
 *
 * 暂停期间数据一直没动，回来还让人对着旧数字等满 15 秒没有道理。
 */
function onVisibility() {
  paused.value = document.visibilityState !== 'visible'
  if (!paused.value) loadOverview()
}

function startPolling() {
  stopPolling()
  ticker = setInterval(tick, 1000)
  document.addEventListener('visibilitychange', onVisibility)
}

function stopPolling() {
  if (ticker) clearInterval(ticker)
  ticker = 0
  document.removeEventListener('visibilitychange', onVisibility)
}

onMounted(() => {
  loadOverview()
  startPolling()
})

/*
 * ⚠️ keep-alive 下 onUnmounted 不会触发
 *
 * 菜单页是缓存的（`keep_alive`），切到别的页签这个组件只是被停用，不会销毁——
 * 只写 onUnmounted 的话定时器一直在跑，用户在别的页面上干活，
 * 这一页还在每 15 秒打一次接口，而且他根本看不到结果。
 * 所以启停挂在 activated/deactivated 上，onUnmounted 只是真正销毁时的兜底。
 */
let firstActivate = true

onActivated(() => {
  // 首次渲染时 mounted 先于 activated 触发，两个都干活就会连发两次请求
  if (firstActivate) {
    firstActivate = false
    return
  }
  loadOverview()
  startPolling()
})

onDeactivated(stopPolling)
onUnmounted(stopPolling)

// ---------------------------------------------------------------- 总览派生值
const redis = computed(() => overview.value?.redis)
const queues = computed(() => overview.value?.queues ?? [])

const waitingTotal = computed(() => queues.value.reduce((s, q) => s + q.waiting, 0))

/** 没有消费者的队列：消息投进去就没人接，积压只会一直涨 */
const orphanQueues = computed(() => queues.value.filter((q) => q.orphan).map((q) => q.queue))

// ---------------------------------------------------------------- 执行记录
/*
 * 执行记录**跟着任务走**：它回答的是「log-cleanup 昨天那次跑没跑、删了多少行」，
 * 只有盯着某个任务时才有意义。所以入口是任务表上的「运行日志」，
 * 而不是页面上平铺一张混着所有任务的列表——那样每次都得先筛出自己要看的那个。
 */
const logDrawerRef = ref<InstanceType<typeof TaskLogDrawer> | null>(null)

// ---------------------------------------------------------------- 失败任务
const query = ref<Record<string, unknown>>({ queue: '' })

/** 下拉的选项跟着总览走：不写死队列名，业务方新增队列这里自动就有 */
const searchFields = computed<SearchField[]>(() => [
  {
    prop: 'queue',
    label: '队列',
    type: 'select',
    options: queues.value.map((q) => ({ label: q.queue, value: q.queue }))
  }
])

const columns: ProColumn<FailedJobRow>[] = [
  { prop: 'queue', label: '队列', width: 190 },
  { prop: 'attempts', label: '已重试', width: 100, align: 'center', slot: 'attempts' },
  { prop: 'created_at', label: '投递时间', width: 180, align: 'center' },
  { prop: 'data', label: '参数', minWidth: 260, slot: 'data' },
  { prop: 'actions', label: '操作', width: 160, align: 'center', fixed: 'right', slot: 'actions' }
]

async function request(params: Record<string, unknown>) {
  return await fetchFailedJobs(params as never)
}

const acting = ref('')

async function onRetry(row: FailedJobRow) {
  await ElMessageBox.confirm(
    `将这条消息重新投回 ${row.queue}？重试次数会归零，消费者会当成新消息再跑一遍。`,
    '重投确认',
    { type: 'warning', confirmButtonText: '重投' }
  )

  acting.value = row.id
  try {
    await retryFailedJob(row.id)
    ElMessage.success('已重新入队')
    tableRef.value?.refresh()
    loadOverview()
  } finally {
    acting.value = ''
  }
}

async function onDiscard(row: FailedJobRow) {
  await ElMessageBox.confirm(
    '丢弃后这条任务不会再执行，且 Redis 之外没有第二份，不可恢复。',
    '丢弃确认',
    { type: 'warning', confirmButtonText: '丢弃', confirmButtonClass: 'el-button--danger' }
  )

  acting.value = row.id
  try {
    await discardFailedJob(row.id)
    ElMessage.success('已丢弃')
    tableRef.value?.refresh()
    loadOverview()
  } finally {
    acting.value = ''
  }
}
</script>

<template>
  <div class="page">
    <!-- 总览 -->
    <section v-loading="loading" class="panel">
      <div class="panel-head">
        <h3>运行状态</h3>
        <span class="desc">由 worker #{{ overview?.processes.pid ?? '—' }} 应答</span>
        <!--
          倒计时用一个环形进度表示剩余时间（conic-gradient 画的，不引图表库）。
          暂停态给的是文字而不是停住的环——一个不动的环看不出是暂停还是卡住了。
        -->
        <span class="countdown" :class="{ paused }">
          <template v-if="paused">已暂停 · 切回本页自动刷新</template>
          <template v-else-if="loading">刷新中…</template>
          <template v-else>
            <i class="ring" :style="{ '--progress': `${(countdown / POLL_SECONDS) * 100}%` }" />
            {{ countdown }} 秒后刷新
          </template>
        </span>
        <el-button :icon="Refresh" link type="primary" @click="loadOverview">刷新</el-button>
      </div>

      <div class="stat-grid">
        <div class="stat">
          <span class="label">队列</span>
          <span class="value num">{{ queues.length }}</span>
          <span class="foot">消费进程 {{ overview?.processes.consumer ?? '—' }} 个</span>
        </div>
        <div class="stat">
          <span class="label">待消费</span>
          <span class="value num" :class="{ warn: waitingTotal > 0 }">{{ waitingTotal }}</span>
          <span class="foot">各队列 waiting 之和</span>
        </div>
        <div class="stat">
          <span class="label">延迟中</span>
          <span class="value num">{{ redis?.delayed_total ?? '—' }}</span>
          <span class="foot">含重试等待，共 {{ redis?.max_attempts ?? '—' }} 次机会</span>
        </div>
        <div class="stat">
          <span class="label">已失败</span>
          <span class="value num" :class="{ danger: (redis?.failed_total ?? 0) > 0 }">
            {{ redis?.failed_total ?? '—' }}
          </span>
          <span class="foot">重试用尽，等人处理</span>
        </div>
        <div class="stat">
          <span class="label">Redis</span>
          <span class="value">
            <el-tag :type="redis?.connected ? 'success' : 'danger'" size="small" disable-transitions>
              {{ redis?.connected ? '已连接' : '连不上' }}
            </el-tag>
          </span>
          <span class="foot">
            {{ redis?.connected ? `db ${redis.db} · 占用 ${redis.used_memory}` : redis?.error }}
          </span>
        </div>
        <div class="stat">
          <span class="label">进程编制</span>
          <span class="value num">
            {{ overview?.processes.http ?? '—' }} + {{ overview?.processes.task ?? '—' }} +
            {{ overview?.processes.consumer ?? '—' }}
          </span>
          <span class="foot">HTTP + 定时 + 消费</span>
        </div>
      </div>

      <!--
        两条要人动手的提醒。放在最显眼的位置，因为它们的共同点是
        「界面上其他一切看着都正常」：没有消费者的队列不会报错，只是永远不消化
      -->
      <el-alert
        v-if="orphanQueues.length"
        type="warning"
        show-icon
        :closable="false"
        class="tip"
        title="有队列没有消费者"
        :description="`${orphanQueues.join('、')} —— 消息投得进去但没人消费。检查 app/queue/ 下的消费者是否被删，或投递方的队列名是否拼错。`"
      />
      <el-alert
        v-if="redis?.sampled"
        type="info"
        show-icon
        :closable="false"
        class="tip"
        title="积压已超出采样窗口"
        :description="`延迟与失败是所有队列共用一条 Redis 结构，下表每行只统计最近 ${redis.scan_limit} 条；上方的总数是准确值。两者对不上说明积压确实很大。`"
      />
    </section>

    <!-- 队列明细 -->
    <section class="panel">
      <div class="panel-head">
        <h3>队列</h3>
        <span class="desc">
          消费者按目录扫描（{{ overview?.processes.consumer_dir }}），无需注册；说明由消费者的
          <code>$desc</code> 声明
        </span>
      </div>

      <el-table :data="queues" size="small" empty-text="还没有队列">
        <el-table-column prop="queue" label="队列名" min-width="190" />
        <!-- 说明由消费者自己声明；没声明的（含没有消费者的队列）显示占位 -->
        <el-table-column label="说明" min-width="220">
          <template #default="{ row }">{{ row.desc || '—' }}</template>
        </el-table-column>
        <el-table-column label="消费者" min-width="180">
          <template #default="{ row }">
            <span v-if="row.consumer">{{ row.consumer }}</span>
            <el-tag v-else type="warning" size="small" disable-transitions>无消费者</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="waiting" label="待消费" width="100" align="center" />
        <el-table-column prop="delayed" label="延迟中" width="100" align="center" />
        <el-table-column label="失败" width="100" align="center">
          <template #default="{ row }">
            <span :class="{ danger: row.failed > 0 }">{{ row.failed }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="connection" label="连接" width="110" align="center" />
      </el-table>
    </section>

    <!-- 定时任务 -->
    <section class="panel">
      <div class="panel-head">
        <h3>定时任务</h3>
        <span class="desc">
          跑在独立进程里，只负责按点投递，活由上面的队列干；每次执行的结果看「运行日志」
        </span>
      </div>

      <el-table :data="overview?.tasks ?? []" size="small" empty-text="没有定时任务">
        <el-table-column prop="name" label="任务" width="170" />
        <el-table-column prop="desc" label="说明" min-width="220" />
        <el-table-column prop="rule" label="cron" width="140" align="center" />
        <el-table-column label="投递到" min-width="180">
          <template #default="{ row }">
            {{ row.queue }}
            <el-tag v-if="row.orphan" type="warning" size="small" disable-transitions>
              无消费者
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="下次执行" width="170" align="center">
          <template #default="{ row }">{{ row.next_run ?? '两天内无' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130" align="center">
          <!-- 裸 el-table 的插槽把行给成 DefaultRow，断言回 QueueTaskRow（同 MemberDrawer 的写法） -->
          <template #default="scope">
            <el-button
              :icon="Tickets"
              link
              type="primary"
              @click="logDrawerRef?.open(scope.row as QueueTaskRow)"
            >
              运行日志
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <TaskLogDrawer ref="logDrawerRef" />

    <!--
      失败任务：标题、筛选、表格三者**平级**

      不能像上面三块那样套一层 .panel——`<SearchForm>` 与 `<ProTable>` 自己就是 panel，
      再包一层就是卡片套卡片，出现两圈边框和双份内边距。
    -->
    <section class="failed">
      <div class="panel-head">
        <h3>失败任务</h3>
        <span class="desc">
          重试 {{ redis?.max_attempts ?? '—' }} 次仍失败的消息，不会自动丢弃
        </span>
      </div>

      <SearchForm
        v-model="query"
        :fields="searchFields"
        @search="tableRef?.reload()"
        @reset="tableRef?.reload()"
      />

      <!--
        `lock-height="false"`：这一页上面还压着三块面板，ProTable 默认的定高
        （视口 - 表格顶部）会撞到下限，表体只剩一百多像素，既在整页滚里又套一条
        自己的滚动条，空状态插画还会溢出到表头线上。这里让表格自然撑开。
      -->
      <ProTable
        ref="tableRef"
        v-model:params="query"
        :request="request"
        :columns="columns"
        row-key="id"
        :lock-height="false"
      >
        <template #attempts="{ row }">{{ row.attempts }} / {{ row.max_attempts }}</template>

        <template #data="{ row }">
          <code class="payload">{{ row.data }}</code>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button
              v-permission="'sys:queue:retry'"
              :icon="RefreshRight"
              link
              type="primary"
              :loading="acting === row.id"
              @click="onRetry(row)"
            >
              重投
            </el-button>
            <el-button
              v-permission="'sys:queue:delete'"
              :icon="Delete"
              link
              type="danger"
              :loading="acting === row.id"
              @click="onDiscard(row)"
            >
              丢弃
            </el-button>
          </div>
        </template>
      </ProTable>
    </section>
  </div>
</template>

<style scoped>
/*
 * 容器不自己画：`.panel` 是全站统一的面板样式（`styles/index.css`），
 * 圆角、边框、内边距都跟着设计令牌走。这里再写一份 border + padding，
 * 换设计语言时就会漏改这一页。
 */
.failed {
  display: flex;
  flex-direction: column;
  gap: var(--keel-gap-lg);
}

.panel-head {
  display: flex;
  align-items: baseline;
  gap: var(--keel-gap);
  margin-bottom: var(--keel-gap);
}

/* 平级布局下标题自带 gap，再留 margin 会和筛选区拉开两倍距离 */
.failed > .panel-head {
  margin-bottom: 0;
}

.panel-head h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.panel-head .desc {
  flex: 1;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.countdown {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-variant-numeric: tabular-nums; /* 数字等宽：不等宽的话每秒跳一下文字宽度 */
  color: var(--el-text-color-secondary);
}

.countdown.paused {
  color: var(--el-text-color-placeholder);
}

.ring {
  width: 12px;
  height: 12px;
  background: conic-gradient(
    var(--el-color-primary) var(--progress),
    var(--el-border-color) var(--progress)
  );
  border-radius: 50%;
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: var(--keel-gap);
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  background: var(--el-fill-color-lighter);
  border-radius: var(--keel-radius);
}

.stat .label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.stat .value {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--el-text-color-primary);
}

.stat .foot {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.warn {
  color: var(--el-color-warning);
}

.danger {
  color: var(--el-color-danger);
}

.tip {
  margin-top: var(--keel-gap);
}

.payload {
  display: block;
  overflow: hidden;
  font-size: 12px;
  color: var(--el-text-color-regular);
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
