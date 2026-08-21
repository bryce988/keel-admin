<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Delete, Plus } from '@element-plus/icons-vue'
import {
  batchDeletePosts,
  createPost,
  deletePost,
  fetchDeptTree,
  fetchPosts,
  updatePost,
  type DeptNode,
  type PostRow
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import { BizCode } from '@/constants/bizCode'

/**
 * 岗位管理
 *
 * 岗位是 HR 概念，**不是角色**：只在新建用户时带出默认角色作为初始值，
 * 之后改岗位不会动已有账号的授权。界面上也要体现这个边界，
 * 别让人以为改岗位就等于改权限。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '', dept_id: '' })
const paramParsers = { status: Number, dept_id: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'name', label: '岗位名称', minWidth: 150 },
  { prop: 'code', label: '岗位编码', minWidth: 150 },
  { prop: 'dept_name', label: '所属部门', minWidth: 130 },
  { prop: 'sort', label: '排序', width: 80, align: 'center', sortable: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'remark', label: '备注', minWidth: 180, hidden: true },
  { prop: 'created_at', label: '创建时间', minWidth: 160, sortable: true, hidden: true },
  { prop: 'actions', label: '操作', width: 180, align: 'center', fixed: 'right', slot: 'actions' }
]

const selected = ref<PostRow[]>([])
const deptTree = ref<DeptNode[]>([])

/** 岗位可以是「全公司通用」，所以选择器要有 dept_id = 0 这一项 */
const deptOptions = ref<Array<{ id: number; name: string }>>([])

async function loadDepts() {
  deptTree.value = await fetchDeptTree()
  deptOptions.value = [{ id: 0, name: '全公司通用' }, ...flatten(deptTree.value)]
}

/** 树压平成下拉选项，用全角空格做层级缩进——比再塞一个树选择器轻 */
function flatten(nodes: DeptNode[], depth = 0): Array<{ id: number; name: string }> {
  return nodes.flatMap((n) => [
    { id: n.id, name: '　'.repeat(depth) + n.name },
    ...(n.children?.length ? flatten(n.children, depth + 1) : [])
  ])
}

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)

const rules: FormRules = {
  name: [{ required: true, message: '请输入岗位名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入岗位编码', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_:.-]+$/, message: '只能包含字母、数字与 _ : . -', trigger: 'blur' }
  ]
}

const errorFields = {
  [BizCode.POST_CODE_EXISTS]: 'code',
  [BizCode.DATA_SCOPE_DENIED]: 'dept_id'
}

function onCreate() {
  editingId.value = 0
  drawerRef.value?.open({
    title: '新增岗位',
    data: { name: '', code: '', dept_id: 0, default_role_id: 0, sort: 0, status: 1, remark: '' }
  })
}

function onEdit(row: PostRow) {
  editingId.value = row.id
  drawerRef.value?.open({ title: '编辑岗位', data: { ...row } })
}

function onView(row: PostRow) {
  editingId.value = 0
  drawerRef.value?.open({ title: '岗位详情', data: { ...row }, mode: 'view' })
}

function submit(form: Record<string, any>) {
  const payload = {
    name: form.name,
    code: form.code,
    dept_id: form.dept_id ?? 0,
    default_role_id: form.default_role_id ?? 0,
    sort: form.sort ?? 0,
    status: form.status ?? 1,
    remark: form.remark ?? ''
  }

  return editingId.value ? updatePost(editingId.value, payload) : createPost(payload)
}

async function onDelete(row: PostRow) {
  await ElMessageBox.confirm(`确定删除岗位「${row.name}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deletePost(row.id)
  ElMessage.success('已删除')
  tableRef.value?.refresh()
}

/** 批量删除是「逐条尽力」的，所以要把失败明细如实告诉用户，而不是笼统一句成功 */
async function onBatchDelete() {
  const ids = selected.value.map((r) => r.id)
  if (!ids.length) return

  await ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个岗位吗？`, '批量删除', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  const result = await batchDeletePosts(ids)

  if (result.fail_count === 0) {
    ElMessage.success(`已删除 ${result.success_count} 个岗位`)
  } else {
    ElMessageBox.alert(
      result.failed.map((f) => `#${f.id}：${f.reason}`).join('<br>'),
      `成功 ${result.success_count} 个，失败 ${result.fail_count} 个`,
      { dangerouslyUseHTMLString: true, type: 'warning' }
    )
  }

  tableRef.value?.refresh()
}

onMounted(() => {
  dictStore.preload(['enable_status'])
  loadDepts()
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
      :request="fetchPosts"
      :param-parsers="paramParsers"
      :columns="columns"
      selection
      index
      @selection-change="selected = $event as PostRow[]"
    >
      <template #toolbar>
        <el-button v-permission="'sys:post:create'" type="primary" :icon="Plus" @click="onCreate">
          新增岗位
        </el-button>
        <el-button
          v-permission="'sys:post:delete'"
          type="danger"
          plain
          :icon="Delete"
          :disabled="!selected.length"
          @click="onBatchDelete"
        >
          批量删除{{ selected.length ? `（${selected.length}）` : '' }}
        </el-button>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button link type="primary" @click="onView(row)">详情</el-button>
          <el-button v-permission="'sys:post:update'" link type="primary" @click="onEdit(row)">
            编辑
          </el-button>
          <el-button v-permission="'sys:post:delete'" link type="danger" @click="onDelete(row)">
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
      @success="tableRef?.refresh()"
    >
      <!-- 详情用描述列表而不是一堆禁用的输入框：只读场景下输入框既占地方又误导 -->
      <template #default="{ form, errors, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="岗位名称">{{ form.name }}</el-descriptions-item>
          <el-descriptions-item label="岗位编码">{{ form.code }}</el-descriptions-item>
          <el-descriptions-item label="所属部门">{{ form.dept_name }}</el-descriptions-item>
          <el-descriptions-item label="排序">{{ form.sort }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="enable_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="备注">{{ form.remark || '—' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ form.created_at }}</el-descriptions-item>
        </el-descriptions>

        <template v-else>
        <el-form-item label="岗位名称" prop="name">
          <el-input v-model="form.name" maxlength="64" show-word-limit />
        </el-form-item>
        <el-form-item label="岗位编码" prop="code" :error="errors.code">
          <el-input v-model="form.code" maxlength="64" placeholder="如 POST-DEV" />
        </el-form-item>
        <el-form-item label="所属部门" prop="dept_id" :error="errors.dept_id">
          <el-select v-model="form.dept_id" style="width: 100%">
            <el-option
              v-for="opt in deptOptions"
              :key="opt.id"
              :label="opt.name"
              :value="opt.id"
            />
          </el-select>
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
