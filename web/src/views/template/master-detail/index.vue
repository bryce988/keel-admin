<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import type { FormDrawerInstance, ProColumn, ProTableInstance } from '@/components'
// ⛔ 复制后把这一行换成你自己的 api/xxx.ts
import {
  createDemoChild,
  deleteDemoChild,
  fetchDemoChildren,
  fetchDemoList,
  updateDemoChild,
  type DemoChild,
  type DemoRow
} from '../_demo'

/**
 * 【模板 ③】主从页 —— 左主右从，选中主记录带出从记录
 *
 * 一对多、且从表离开主表没有意义时用它：字典类型 + 字典项、
 * 订单 + 订单行、模板 + 模板字段（PROJECT.md §9.3）。
 * 如果从表自己也需要独立查询，那它就不是「从」，应该拆成两个列表页。
 *
 * 复制清单：
 *   1. 换掉 `../_demo`
 *   2. 主区列表通常量不大，这里用的是一次拉全量 + 前端搜索；
 *      主记录上千条就改成 ProTable 分页
 *   3. 删主记录前要检查从记录（后端走 Guard::notReferenced），提示里写明条数
 */
const masters = ref<DemoRow[]>([])
const masterLoading = ref(false)
const keyword = ref('')
const currentId = ref(0)

const childTableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

/** 从区的筛选条件里必须带 master_id，否则切主记录时取的还是上一条的从数据 */
const childQuery = ref<Record<string, unknown>>({ master_id: 0 })

const childColumns: ProColumn[] = [
  { prop: 'label', label: '标签', minWidth: 140 },
  { prop: 'value', label: '值', minWidth: 120 },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'actions', label: '操作', width: 120, align: 'center', fixed: 'right', slot: 'actions' }
]

async function loadMasters() {
  masterLoading.value = true
  try {
    const result = await fetchDemoList({ keyword: keyword.value, page_size: 100 })
    masters.value = result.list

    /*
     * 选中项不在列表里就重选第一条
     *
     * 两种情况都会走到：首次进页面（currentId 还是 0）、
     * 以及搜索之后原来选中的那条被筛掉了。后者不处理的话，
     * 左边没有任何高亮、右边却还显示着上一条的明细——
     * 用户会以为右边这些就是搜出来那条的数据
     */
    const stillThere = masters.value.some((row) => row.id === currentId.value)
    if (!stillThere) {
      if (masters.value.length) {
        select(masters.value[0])
      } else {
        currentId.value = 0
      }
    }
  } finally {
    masterLoading.value = false
  }
}

/**
 * 选中主记录
 *
 * 先改 master_id 再 reload。ProTable 的 reload() 内部 await 了 nextTick，
 * 所以这里不用自己等——但**顺序不能反**，反了就是取上一条的从数据。
 */
function select(row: DemoRow) {
  currentId.value = row.id
  childQuery.value.master_id = row.id
  childTableRef.value?.reload()
}

// ---------------------------------------------------------------- 从记录增改删
const editingChildId = ref(0)

const rules: FormRules = {
  label: [{ required: true, message: '请输入标签', trigger: 'blur' }],
  value: [{ required: true, message: '请输入值', trigger: 'blur' }]
}

function onCreateChild() {
  editingChildId.value = 0
  drawerRef.value?.open({
    title: '新增明细',
    data: { label: '', value: '', sort: 0, status: 1 }
  })
}

function onEditChild(row: DemoChild) {
  editingChildId.value = row.id
  drawerRef.value?.open({ title: '编辑明细', data: { ...row } })
}

function submitChild(form: Record<string, any>) {
  const payload = {
    master_id: currentId.value,
    label: form.label,
    value: form.value,
    sort: form.sort ?? 0,
    status: form.status ?? 1
  }

  return editingChildId.value ? updateDemoChild(editingChildId.value, payload) : createDemoChild(payload)
}

async function onDeleteChild(row: DemoChild) {
  await ElMessageBox.confirm(`确定删除明细「${row.label}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteDemoChild(row.id)
  ElMessage.success('已删除')
  childTableRef.value?.refresh()
}

/*
 * 从区的增删改**不刷新主区**——除非主区展示了从表的聚合值（如「明细数」），
 * 那时才需要 loadMasters()。无脑刷主区会让用户的滚动位置和选中态一起跳掉。
 */

onMounted(loadMasters)
</script>

<template>
  <div class="page master-detail-page">
    <!-- 主区 -->
    <el-card v-loading="masterLoading" class="master-panel" shadow="never">
      <div class="panel-header">
        <span class="panel-title">主记录</span>
        <el-button link type="primary" :icon="Plus">新增</el-button>
      </div>

      <el-input
        v-model="keyword"
        placeholder="搜索名称"
        clearable
        class="master-search"
        @keyup.enter="loadMasters"
        @clear="loadMasters"
      />

      <ul class="master-list">
        <li
          v-for="row in masters"
          :key="row.id"
          :class="{ active: row.id === currentId }"
          @click="select(row)"
        >
          <span class="name">{{ row.name }}</span>
          <span class="code">{{ row.code }}</span>
        </li>
      </ul>

      <EmptyState
        v-if="!masterLoading && !masters.length"
        scene="empty"
        :size="60"
        action-text="新增"
      />
    </el-card>

    <!-- 从区 -->
    <section class="detail-panel">
      <!-- 未选中主记录时给空状态，而不是一个空表格：空表格看着像「查询无结果」 -->
      <el-card v-if="!currentId" shadow="never">
        <EmptyState description="请先在左侧选择一条主记录" :action="false" />
      </el-card>

      <ProTable
        v-else
        ref="childTableRef"
        v-model:params="childQuery"
        :request="fetchDemoChildren"
        :columns="childColumns"
        :sync-url="false"
        index
      >
        <template #toolbar>
          <el-button type="primary" :icon="Plus" @click="onCreateChild">新增明细</el-button>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button link type="primary" @click="onEditChild(row)">编辑</el-button>
            <el-button link type="danger" @click="onDeleteChild(row)">删除</el-button>
          </div>
        </template>
      </ProTable>
    </section>

    <FormDrawer
      ref="drawerRef"
      :submit="submitChild"
      :rules="rules"
      size="460px"
      @success="childTableRef?.refresh()"
    >
      <template #default="{ form }">
        <el-form-item label="标签" prop="label">
          <el-input v-model="form.label" maxlength="64" />
        </el-form-item>
        <el-form-item label="值" prop="value">
          <el-input v-model="form.value" maxlength="64" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
  /* 面板之间的间距横竖一致，否则同一屏里网格看着比堆叠更挤 */
.master-detail-page {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  gap: var(--keel-gap-lg);
  align-items: start;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.panel-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.master-panel :deep(.el-card__body) {
  padding: 12px;
}

.master-search {
  margin-top: 12px;
}

.master-list {
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
  max-height: calc(100vh - 300px);
  overflow-y: auto;
}

.master-list li {
  padding: 8px 10px;
  border-radius: 4px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.master-list li:hover {
  background: var(--el-fill-color-light);
}

/* 选中态用主色的浅底，不写死颜色（§10.1） */
.master-list li.active {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.master-list .name {
  font-size: 13px;
}

.master-list .code {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.detail-panel {
  min-width: 0;
}

@media (max-width: 900px) {
  .master-detail-page {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
