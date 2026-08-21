<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  createDept,
  deleteDept,
  fetchDeptTree,
  updateDept,
  type DeptNode
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import { BizCode } from '@/constants/bizCode'

/**
 * 部门管理
 *
 * 组织架构树。这张表是数据权限的载体——`ancestors` 决定了「本部门及下属」
 * 能看到哪些数据，所以移动部门是个重操作，后端会同步刷新整棵子树的祖级路径。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '' })
const paramParsers = { status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'name', label: '部门名称', minWidth: 200, align: 'left' },
  { prop: 'code', label: '部门编码', minWidth: 140 },
  { prop: 'user_count', label: '用户数', width: 90, align: 'center' },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'created_at', label: '创建时间', minWidth: 160, hidden: true },
  { prop: 'actions', label: '操作', width: 230, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 上级部门选择
const treeData = ref<DeptNode[]>([])
/** 编辑时不能把自己或自己的子孙选成上级，直接在选择器里禁用，不等后端报错 */
const editingId = ref(0)

async function loadTreeOptions() {
  treeData.value = await fetchDeptTree()
}

function collectSubtreeIds(nodes: DeptNode[], target: number, found = false): number[] {
  let ids: number[] = []
  for (const node of nodes) {
    const hit = found || node.id === target
    if (hit) ids.push(node.id)
    if (node.children?.length) ids = ids.concat(collectSubtreeIds(node.children, target, hit))
  }
  return ids
}

const disabledIds = computed(() =>
  editingId.value ? collectSubtreeIds(treeData.value, editingId.value) : []
)

/** el-tree-select 用 disabled 字段控制禁用，这里按需加工一份 */
const parentOptions = computed(() => {
  const decorate = (nodes: DeptNode[]): any[] =>
    nodes.map((n) => ({
      ...n,
      disabled: disabledIds.value.includes(n.id),
      children: n.children?.length ? decorate(n.children) : undefined
    }))

  return [{ id: 0, name: '顶级部门', disabled: false, children: decorate(treeData.value) }]
})

// ---------------------------------------------------------------- 增改删
const rules: FormRules = {
  name: [{ required: true, message: '请输入部门名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入部门编码', trigger: 'blur' },
    {
      pattern: /^[A-Za-z0-9_:.-]+$/,
      message: '只能包含字母、数字与 _ : . -',
      trigger: 'blur'
    }
  ]
}

/** 业务码 → 字段，让「编码已存在」红框标在编码上而不是只弹一句 */
const errorFields = {
  [BizCode.DEPT_CODE_EXISTS]: 'code',
  // 上级部门超出数据范围，红框标在上级选择器上
  [BizCode.DATA_SCOPE_DENIED]: 'parent_id'
}

function onCreate(parentId = 0) {
  editingId.value = 0
  drawerRef.value?.open({
    title: parentId ? '新增下级部门' : '新增部门',
    data: { parent_id: parentId, name: '', code: '', sort: 0, status: 1 }
  })
}

function onEdit(row: DeptNode) {
  editingId.value = row.id
  drawerRef.value?.open({ title: '编辑部门', data: { ...row, children: undefined } })
}

function onView(row: DeptNode) {
  editingId.value = 0
  drawerRef.value?.open({
    title: '部门详情',
    data: { ...row, children: undefined },
    mode: 'view'
  })
}

function submit(form: Record<string, any>) {
  const payload = {
    parent_id: form.parent_id ?? 0,
    name: form.name,
    code: form.code,
    leader_id: form.leader_id ?? 0,
    sort: form.sort ?? 0,
    status: form.status ?? 1
  }

  return editingId.value ? updateDept(editingId.value, payload) : createDept(payload)
}

async function onSaved() {
  await loadTreeOptions()
  tableRef.value?.refresh()
}

async function onDelete(row: DeptNode) {
  await ElMessageBox.confirm(
    `确定删除部门「${row.name}」吗？删除后不可恢复。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteDept(row.id)
  ElMessage.success('已删除')
  await loadTreeOptions()
  tableRef.value?.refresh()
}

onMounted(() => {
  dictStore.preload(['enable_status'])
  loadTreeOptions()
})
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
      :request="fetchDeptTree"
      :param-parsers="paramParsers"
      :columns="columns"
      tree
    >
      <template #toolbar>
        <el-button v-permission="'sys:dept:create'" type="primary" :icon="Plus" @click="onCreate()">
          新增部门
        </el-button>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button link type="primary" @click="onView(row)">详情</el-button>
          <el-button v-permission="'sys:dept:create'" link type="primary" @click="onCreate(row.id)">
            新增下级
          </el-button>
          <el-button v-permission="'sys:dept:update'" link type="primary" @click="onEdit(row)">
            编辑
          </el-button>
          <el-button v-permission="'sys:dept:delete'" link type="danger" @click="onDelete(row)">
            删除
          </el-button>
        </div>
      </template>
    </ProTable>

    <FormDrawer
      ref="drawerRef"
      :submit="submit"
      :rules="rules"
      :error-fields="errorFields"
      size="560px"
      @success="onSaved"
    >
      <!-- 详情用描述列表而不是一堆禁用的输入框：只读场景下输入框既占地方又误导 -->
      <template #default="{ form, errors, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="部门名称">{{ form.name }}</el-descriptions-item>
          <el-descriptions-item label="部门编码">{{ form.code }}</el-descriptions-item>
          <el-descriptions-item label="用户数">{{ form.user_count }}</el-descriptions-item>
          <el-descriptions-item label="排序">{{ form.sort }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="enable_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ form.created_at }}</el-descriptions-item>
        </el-descriptions>

        <template v-else>
        <el-form-item label="上级部门" prop="parent_id" :error="errors.parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentOptions"
            :props="{ label: 'name', children: 'children', disabled: 'disabled' }"
            node-key="id"
            check-strictly
            default-expand-all
            placeholder="不选则为顶级部门"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="部门名称" prop="name">
          <el-input v-model="form.name" maxlength="64" show-word-limit />
        </el-form-item>
        <el-form-item label="部门编码" prop="code" :error="errors.code">
          <el-input v-model="form.code" maxlength="64" placeholder="如 DEPT-TECH" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
          <span class="hint">值越小越靠前</span>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        </template>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
.hint {
  margin-left: 12px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
