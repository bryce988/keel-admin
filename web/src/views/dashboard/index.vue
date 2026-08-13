<script setup lang="ts">
import { computed } from 'vue'
import { useUserStore } from '@/stores/user'

const userStore = useUserStore()

/** 指标卡：接业务时只换取数逻辑，结构与样式不动 */
const stats = [
  { label: '今日访问量', value: '12,486', unit: '', trend: 'up', delta: '8.4%', bar: 68, tone: '' },
  { label: '在线用户', value: '326', unit: '', trend: 'up', delta: '2.1%', bar: 42, tone: 'success' },
  { label: '待处理任务', value: '18', unit: '', trend: '', delta: '6 项即将超时', bar: 36, tone: 'warning' },
  { label: '异常告警', value: '3', unit: '', trend: '', delta: '1 项严重', bar: 12, tone: 'danger' }
]

const systemStatus = [
  { label: 'CPU 使用率', value: 38, text: '38%', tone: 'success' },
  { label: '内存使用率', value: 64, text: '64%', tone: 'warning' },
  { label: '磁盘占用', value: 82, text: '82%', tone: 'exception' },
  { label: '接口成功率', value: 99.6, text: '99.6%', tone: 'success' }
]

const todos = [
  { type: 'danger', tag: '待审批', title: '数据导出申请', desc: '申请导出用户表 2,486 条', time: '09:12' },
  { type: 'warning', tag: '待确认', title: '角色权限变更', desc: '「部门主管」新增 3 个权限点', time: '08:40' },
  { type: 'primary', tag: '通知', title: '本周六 02:00 例行维护', desc: '预计 30 分钟', time: '昨天' },
  { type: 'info', tag: '已完成', title: '新用户账号开通', desc: '王强 · 运营部', time: '昨天' }
]

const scopeText = computed(
  () => ['', '全部数据', '本部门及下属', '本部门', '仅本人', '自定义'][userStore.profile?.dataScope ?? 4]
)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <h1>系统概览</h1>
      <span class="desc">示例数据 · 指标卡与图表按实际业务替换</span>
      <div class="actions">
        <el-button>刷新</el-button>
        <el-button type="primary">+ 新建</el-button>
      </div>
    </div>

    <!-- 指标卡 -->
    <el-row :gutter="16">
      <el-col v-for="s in stats" :key="s.label" :xs="24" :sm="12" :lg="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat">
            <span class="label">{{ s.label }}</span>
            <span class="value">{{ s.value }}<small v-if="s.unit">{{ s.unit }}</small></span>
            <span class="foot">
              <span v-if="s.trend === 'up'" class="up">▲ {{ s.delta }} 较昨日</span>
              <el-tag v-else-if="s.tone === 'danger'" type="danger" size="small">{{ s.delta }}</el-tag>
              <el-tag v-else-if="s.tone === 'warning'" type="warning" size="small">{{ s.delta }}</el-tag>
              <span v-else>{{ s.delta }}</span>
            </span>
            <el-progress
              :percentage="s.bar"
              :stroke-width="6"
              :show-text="false"
              :status="s.tone === 'danger' ? 'exception' : s.tone === 'success' ? 'success' : undefined"
            />
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16">
      <!-- 当前登录态：M1 验证用，接业务后替换为趋势图 -->
      <el-col :xs="24" :lg="16">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <b>当前登录信息</b>
              <span class="desc">登录 → 鉴权 → 权限下发链路已打通</span>
            </div>
          </template>

          <el-descriptions :column="3" border>
            <el-descriptions-item label="账号">{{ userStore.profile?.user.username }}</el-descriptions-item>
            <el-descriptions-item label="姓名">{{ userStore.profile?.user.realName }}</el-descriptions-item>
            <el-descriptions-item label="部门">{{ userStore.profile?.user.deptName || '—' }}</el-descriptions-item>
            <el-descriptions-item label="角色">
              <el-tag v-for="r in userStore.profile?.roles" :key="r" size="small" class="mr">{{ r }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="数据范围">{{ scopeText }}</el-descriptions-item>
            <el-descriptions-item label="超级管理员">
              <el-tag :type="userStore.isSuper ? 'success' : 'info'" size="small">
                {{ userStore.isSuper ? '是' : '否' }}
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="权限点" :span="3">
              <el-tag v-for="p in userStore.profile?.permissions" :key="p" type="info" size="small" class="mr">
                {{ p }}
              </el-tag>
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>

      <!-- 系统状态 -->
      <el-col :xs="24" :lg="8">
        <el-card shadow="never">
          <template #header>
            <div class="card-head"><b>系统状态</b><span class="desc">实时</span></div>
          </template>
          <div class="status-list">
            <div v-for="s in systemStatus" :key="s.label" class="status-item">
              <span class="name">{{ s.label }}</span>
              <el-progress
                :percentage="s.value"
                :stroke-width="6"
                :show-text="false"
                :status="s.tone === 'exception' ? 'exception' : s.tone === 'success' ? 'success' : undefined"
                class="bar"
              />
              <span class="num val">{{ s.text }}</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 待办 -->
    <el-card shadow="never">
      <template #header>
        <div class="card-head">
          <b>待办事项</b>
          <span class="desc">来自审批、通知、系统事件三类</span>
        </div>
      </template>
      <el-table :data="todos" size="default">
        <el-table-column width="110">
          <template #default="{ row }">
            <el-tag :type="row.type" size="small">{{ row.tag }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="标题">
          <template #default="{ row }">
            <div class="cell-title">{{ row.title }}</div>
            <div class="cell-sub">{{ row.desc }}</div>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="120" prop="time" class-name="num" />
        <el-table-column label="操作" width="100" align="right">
          <template #default>
            <el-button size="small">处理</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.stat-card {
  margin-bottom: 16px;
}

.card-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-head .desc {
  margin-left: auto;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.mr {
  margin: 2px 6px 2px 0;
}

.status-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.status-item {
  display: flex;
  align-items: center;
  gap: 12px;
}

.status-item .name {
  width: 90px;
  font-size: 13px;
  color: var(--el-text-color-regular);
}

.status-item .bar {
  flex: 1;
}

.status-item .val {
  width: 52px;
  text-align: right;
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.cell-title {
  font-weight: 500;
  color: var(--el-text-color-primary);
}

.cell-sub {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
