<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
// ⛔ 复制后把这一行换成你自己的 api/xxx.ts
import { createDemo, deleteDemo, fetchDemoList, updateDemo, type DemoRow } from '../_demo'

/**
 * 【模板 ①】标准列表页 —— 单一实体的增删改查
 *
 * 结构：搜索区 → 工具栏 → 表格 → 分页（PROJECT.md §9.1）。
 * 系统管理里的岗位、角色就是这个形状，改改列定义和接口就能用。
 *
 * 复制清单：
 *   1. 把 `../_demo` 换成你的 api 模块
 *   2. 改 searchFields / columns / rules / 表单插槽里的字段
 *   3. 每个按钮补上 v-permission，权限点要与后端 route.php 的 perm 声明一致
 *   4. 删掉本注释块
 */
const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

/**
 * 筛选条件
 *
 * 这里声明过的键才会被 ProTable 同步进 URL，漏写的字段刷新后就丢了。
 * 初值一律给空串（而不是 undefined），否则「重置」清不干净。
 */
const query = ref<Record<string, unknown>>({ keyword: '', status: '' })

/**
 * 数字型字段**必须**在这里登记
 *
 * URL 里的值永远是字符串，不转的话刷新后 el-select 显示空白、
 * 树的选中态也对不上——这是最容易漏的一条。
 */
const paramParsers = { status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'name', label: '名称', minWidth: 160 },
  { prop: 'code', label: '编码', minWidth: 140 },
  { prop: 'owner', label: '负责人', width: 100 },
  { prop: 'sort', label: '排序', width: 80, align: 'center', sortable: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  // 默认列不超过 8 列，多出来的用 hidden 收进列设置里（§9.1）
  { prop: 'remark', label: '备注', minWidth: 160, hidden: true },
  { prop: 'created_at', label: '创建时间', width: 165, sortable: true },
  { prop: 'actions', label: '操作', width: 160, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)

const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入编码', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_]+$/, message: '只能包含字母、数字与下划线', trigger: 'blur' }
  ]
}

/** 业务码 → 字段名：409 这类只有一句 message 的错误靠它落到具体输入框上 */
const errorFields = { 10409: 'code' }

function onCreate() {
  editingId.value = 0
  drawerRef.value?.open({
    title: '新增',
    data: { name: '', code: '', status: 1, sort: 0, remark: '' }
  })
}

function onEdit(row: DemoRow) {
  editingId.value = row.id
  drawerRef.value?.open({ title: '编辑', data: { ...row } })
}

function onView(row: DemoRow) {
  editingId.value = 0
  drawerRef.value?.open({ title: '详情', data: { ...row }, mode: 'view' })
}

/** 抛异常即视为失败，抽屉不关、用户可以直接改 */
function submit(form: Record<string, any>) {
  const payload = {
    name: form.name,
    code: form.code,
    status: form.status ?? 1,
    sort: form.sort ?? 0,
    remark: form.remark ?? ''
  }

  return editingId.value ? updateDemo(editingId.value, payload) : createDemo(payload)
}

/** 危险操作二次确认，弹窗里写明影响范围而不是只问一句「确定吗」（§9.1） */
async function onDelete(row: DemoRow) {
  await ElMessageBox.confirm(`确定删除「${row.name}」吗？删除后不可恢复。`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteDemo(row.id)
  ElMessage.success('已删除')
  // 删除用 refresh（留在当前页），新增/编辑成功也是 refresh；
  // 只有改了筛选条件才用 reload（回到第 1 页）
  tableRef.value?.refresh()
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
      :request="fetchDemoList"
      :param-parsers="paramParsers"
      :columns="columns"
      index
    >
      <template #toolbar>
        <el-button type="primary" :icon="Plus" @click="onCreate">新增</el-button>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button link type="primary" @click="onView(row)">详情</el-button>
          <el-button link type="primary" @click="onEdit(row)">编辑</el-button>
          <el-button link type="danger" @click="onDelete(row)">删除</el-button>
        </div>
      </template>
    </ProTable>

    <FormDrawer
      ref="drawerRef"
      :submit="submit"
      :rules="rules"
      :error-fields="errorFields"
      @success="tableRef?.refresh()"
    >
      <!-- 只读场景用 descriptions 而不是一堆禁用的输入框：后者既占地方又误导 -->
      <template #default="{ form, errors, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="名称">{{ form.name }}</el-descriptions-item>
          <el-descriptions-item label="编码">{{ form.code }}</el-descriptions-item>
          <el-descriptions-item label="负责人">{{ form.owner }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="enable_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="备注">{{ form.remark || '—' }}</el-descriptions-item>
        </el-descriptions>

        <template v-else>
          <el-form-item label="名称" prop="name">
            <el-input v-model="form.name" maxlength="64" show-word-limit />
          </el-form-item>
          <el-form-item label="编码" prop="code" :error="errors.code">
            <el-input v-model="form.code" maxlength="64" placeholder="如 DEMO_001" />
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
          <el-form-item label="备注" prop="remark">
            <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" />
          </el-form-item>
        </template>
      </template>
    </FormDrawer>
  </div>
</template>
