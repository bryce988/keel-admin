<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchOverview, type Overview } from '@/api/dashboard'
import { useDictStore } from '@/stores/dict'
import { useUserStore } from '@/stores/user'

/**
 * 系统概览
 *
 * 只汇总**系统本身已有的模块**：用户、组织、角色权限、字典参数、日志。
 * 不摆「今日订单」「转化率」这类假指标——脚手架不含业务逻辑，
 * 编出来的数字对接业务的人没有任何价值，第一件事还是得全删掉。
 *
 * 所有数字都由服务端在**数据权限之内**算好：部门主管看到的「用户 2 人」
 * 就是他管得到的那两个。前端不做任何过滤，也就不会与列表页的口径打架。
 */
const router = useRouter()
const userStore = useUserStore()
const dictStore = useDictStore()

const data = ref<Overview | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await fetchOverview()
  } finally {
    loading.value = false
  }
}

/** 有权限才跳，没权限的卡片只是不可点，而不是藏起来——藏了反而看不出规模 */
function go(to: string, perm: string) {
  if (!perm || userStore.can(perm)) router.push(to)
}

// ---------------------------------------------------------------- 趋势图
/**
 * 不引图表库
 *
 * 一个七根柱子的图去背 1MB 的 echarts 不划算，而且颜色要跟着
 * Element Plus 的令牌走（CLAUDE.md：不写死颜色），自己用 div 画反而更好控制。
 */
const trendMax = computed(() => Math.max(1, ...(data.value?.trend ?? []).map((t) => t.total)))

function barHeight(value: number): string {
  if (!value) return '0'

  // 最矮也留 4%，否则「有 1 次登录」和「一次都没有」在图上长得一样
  return `${Math.max(4, (value / trendMax.value) * 100)}%`
}

const trendTotal = computed(() => (data.value?.trend ?? []).reduce((s, t) => s + t.total, 0))
const trendFailed = computed(() => (data.value?.trend ?? []).reduce((s, t) => s + t.failed, 0))

/**
 * 概览是按权限过滤的，权限很少的账号可能一张卡都拿不到
 *
 * 那时候不能只剩一排空卡片——要明确告诉他「不是坏了，是你没有这些模块的权限」。
 * 运行状态对所有登录用户都给，所以它不参与这个判断。
 */
const nothingVisible = computed(
  () => !!data.value && !data.value.stats.length && !data.value.modules.length
)

onMounted(() => {
  dictStore.preload(['log_action'])
  load()
})
</script>

<template>
  <div v-loading="loading" class="page">
    <el-alert
      v-if="nothingVisible"
      type="info"
      :closable="false"
      show-icon
      title="当前账号没有任何系统管理模块的权限"
      description="概览只展示你有权限访问的模块，所以这里是空的。需要查看更多内容请联系管理员分配角色。"
    />

    <!-- 指标卡 -->
    <div v-if="data?.stats.length" class="stat-grid">
      <el-card
        v-for="s in data?.stats ?? []"
        :key="s.key"
        shadow="never"
        :class="['stat-card', { clickable: userStore.can(s.perm) }]"
        @click="go(s.to, s.perm)"
      >
        <span class="label">{{ s.label }}</span>
        <span class="value num">
          {{ s.value }}<small>{{ s.unit }}</small>
        </span>
        <!-- 卡脚贴底：只有「今日登录」有 extra，它把整行撑高，
             不贴底的话另外三张卡下面会各留一截无意义的空白 -->
        <div class="foot">
          <el-tag :type="s.tone" size="small" effect="plain">{{ s.hint }}</el-tag>
          <span v-if="s.extra" class="extra">
            今日操作 {{ s.extra.op_today }} 次<template v-if="s.extra.op_failed">
              ，其中被拒 {{ s.extra.op_failed }} 次</template>
          </span>
        </div>
      </el-card>
    </div>

    <div class="main-grid">
      <!-- 登录趋势 -->
      <el-card v-if="data?.trend.length" shadow="never">
        <template #header>
          <div class="card-head">
            <b>近 7 天登录</b>
            <span class="legend">
              <i class="dot success" />成功
              <i class="dot failed" />失败
            </span>
            <span class="desc">共 {{ trendTotal }} 次，失败 {{ trendFailed }} 次</span>
          </div>
        </template>

        <div class="chart">
          <div v-for="t in data?.trend ?? []" :key="t.day" class="col">
            <el-tooltip
              :content="`${t.day}　成功 ${t.success} · 失败 ${t.failed}`"
              placement="top"
            >
              <div class="bar-wrap">
                <!-- 失败堆在上面：一眼就能看出哪天不对劲 -->
                <div class="bar failed" :style="{ height: barHeight(t.failed) }" />
                <div class="bar success" :style="{ height: barHeight(t.success) }" />
              </div>
            </el-tooltip>
            <span class="x num">{{ t.label }}</span>
          </div>
        </div>
      </el-card>

      <!-- 系统状态：只报真的测得到的东西 -->
      <el-card shadow="never">
        <template #header>
          <div class="card-head">
            <b>运行状态</b>
            <span class="desc">{{ data?.system.server_time }}</span>
          </div>
        </template>

        <el-descriptions :column="1" size="small" border>
          <el-descriptions-item label="PHP">{{ data?.system.php_version }}</el-descriptions-item>
          <el-descriptions-item label="Workerman">{{ data?.system.workerman }}</el-descriptions-item>
          <el-descriptions-item label="进程内存">
            <span class="num">{{ data?.system.memory_mb }} MB</span>
            <span class="desc">（峰值 {{ data?.system.memory_peak_mb }} MB）</span>
          </el-descriptions-item>
          <el-descriptions-item label="数据库">
            <el-tag :type="data?.system.db ? 'success' : 'danger'" size="small">
              {{ data?.system.db ? '连通' : '异常' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="缓存">
            <el-tag :type="data?.system.redis ? 'success' : 'danger'" size="small">
              {{ data?.system.redis ? '连通' : '异常' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="慢查询阈值">
            <span class="num">{{ data?.system.slow_query_ms }} ms</span>
          </el-descriptions-item>
        </el-descriptions>
        <p class="foot-note">
          CPU 与磁盘没有列：容器里读到的不是你以为的那台机器，给个会误导的数字不如不给
        </p>
      </el-card>
    </div>

    <div class="main-grid">
      <!-- 最近操作 -->
      <el-card v-if="data?.recent.length || userStore.can('sys:log:operation:list')" shadow="never">
        <template #header>
          <div class="card-head">
            <b>最近操作</b>
            <span class="desc">越权被拒的尝试同样在列</span>
            <el-button
              v-permission="'sys:log:operation:list'"
              link
              type="primary"
              @click="router.push('/log/operation')"
            >
              全部日志
            </el-button>
          </div>
        </template>

        <div v-if="!data?.recent.length" class="empty">还没有操作记录</div>
        <ul v-else class="recent">
          <li v-for="r in data.recent" :key="r.id">
            <DictTag code="log_action" :value="r.action" />
            <div class="body">
              <div class="title">
                {{ r.title }}
                <span class="target">{{ r.target || '—' }}</span>
              </div>
              <div class="meta">
                {{ r.username }} · {{ r.module }} · {{ r.created_at }}
              </div>
            </div>
            <el-tooltip v-if="!r.status" :content="r.error_msg || '失败'">
              <el-tag type="danger" size="small">失败</el-tag>
            </el-tooltip>
          </li>
        </ul>
      </el-card>

      <!-- 模块规模 -->
      <el-card v-if="data?.modules.length" shadow="never">
        <template #header>
          <div class="card-head">
            <b>模块</b>
            <span class="desc">当前系统装了这些</span>
          </div>
        </template>

        <ul class="modules">
          <li
            v-for="m in data?.modules ?? []"
            :key="m.name"
            :class="{ clickable: userStore.can(m.perm) }"
            @click="go(m.to, m.perm)"
          >
            <span class="name">{{ m.name }}</span>
            <span class="count num">{{ m.count }}</span>
          </li>
        </ul>
      </el-card>
    </div>
  </div>
</template>

<style scoped>
/* 标题区用全局那一份（styles/index.css），这里不再覆盖，
   否则概览与其余九个页面会长出两种标题样式 */
.desc {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

  /* 面板之间的间距横竖一致，否则同一屏里网格看着比堆叠更挤 */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--keel-gap-lg);
}

.stat-card :deep(.el-card__body) {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
}

.stat-card.clickable {
  cursor: pointer;
  transition: border-color 0.2s;
}

.stat-card.clickable:hover {
  border-color: var(--el-color-primary);
}

.stat-card .label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.stat-card .value {
  font-size: 26px;
  font-weight: 600;
  line-height: 1.2;
  color: var(--el-text-color-primary);
}

.stat-card .value small {
  margin-left: 4px;
  font-size: 13px;
  font-weight: 400;
  color: var(--el-text-color-secondary);
}

/*
 * 卡脚贴底
 *
 * 四张指标卡在同一个 grid 行里被拉成等高，而只有「今日登录」多一行附注。
 * 不贴底的话内容全部顶对齐，另外三张卡下方各空出一截，看着像没做完。
 * `margin-top: auto` 把卡脚推到底，四张卡的底边就对齐了。
 */
.stat-card .foot {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  margin-top: auto;
}

.stat-card .extra {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

  /* 面板之间的间距横竖一致，否则同一屏里网格看着比堆叠更挤 */
.main-grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: var(--keel-gap-lg);
}

@media (max-width: 1100px) {
  .main-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

.card-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-head .desc,
.card-head .el-button {
  margin-left: auto;
}

.legend {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.legend .dot {
  width: 8px;
  height: 8px;
  border-radius: 2px;
}

.legend .dot.success {
  background: var(--el-color-primary);
}

.legend .dot.failed {
  background: var(--el-color-danger);
}

/* ---------------------------------------------------------------- 趋势图 */
.chart {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  height: 190px;
}

.chart .col {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  height: 100%;
}

.bar-wrap {
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: flex-end;
  width: 100%;
  max-width: 46px;
  /* 段与段之间留一道缝，堆叠的两色不会糊成一片 */
  gap: 2px;
  cursor: default;
}

.bar {
  width: 100%;
  border-radius: 3px 3px 0 0;
  transition: height 0.3s;
}

.bar.success {
  background: var(--el-color-primary-light-3);
  border-radius: 0;
}

.bar-wrap .bar.success:first-child {
  border-radius: 3px 3px 0 0;
}

.bar.failed {
  background: var(--el-color-danger);
}

.chart .x {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* ---------------------------------------------------------------- 最近操作 */
.recent {
  margin: 0;
  padding: 0;
  list-style: none;
}

.recent li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.recent li:last-child {
  border-bottom: none;
}

.recent .body {
  flex: 1;
  min-width: 0;
}

.recent .title {
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.recent .target {
  margin-left: 6px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.recent .meta {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

/* ---------------------------------------------------------------- 模块 */
.modules {
  margin: 0;
  padding: 0;
  list-style: none;
}

.modules li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 8px;
  border-radius: 4px;
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.modules li.clickable {
  cursor: pointer;
}

.modules li.clickable:hover {
  background: var(--el-fill-color-light);
  color: var(--el-color-primary);
}

.modules .count {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.empty {
  padding: 28px 0;
  text-align: center;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.foot-note {
  margin: 10px 0 0;
  font-size: 12px;
  line-height: 1.6;
  color: var(--el-text-color-secondary);
}
</style>
