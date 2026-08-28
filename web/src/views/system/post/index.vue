<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Delete, EditPen, Plus, View } from '@element-plus/icons-vue'
import {
  batchDeletePosts,
  createPost,
  deletePost,
  fetchDeptTree,
  fetchPosts,
  fetchRoleOptions,
  updatePost,
  type DeptNode,
  type PostRow,
  type PostPayload
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import { BizCode } from '@/constants/bizCode'

/**
 * 岗位管理
 *
 * 岗位是 HR 概念，不是角色：只在新建用户时带出默认角色作为初始值，
 * 之后改岗位不会动已有账号的授权。界面上也要体现这个边界，
 * 别让人以为改岗位就等于改权限。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance<PostPayload> | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '', dept_id: '' })
const paramParsers = { status: Number, dept_id: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn<PostRow>[] = [
  { prop: 'name', label: '岗位名称', minWidth: 150, align: 'center' },
  { prop: 'code', label: '岗位编码', minWidth: 150, align: 'center' },
  { prop: 'dept_name', label: '所属部门', minWidth: 130, align: 'center' },
  { prop: 'sort', label: '排序', width: 100, align: 'center', sortable: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'remark', label: '备注', minWidth: 180, hidden: true },
  { prop: 'created_at', label: '创建时间', minWidth: 190, align: 'center', sortable: true, hidden: true },
  { prop: 'actions', label: '操作', width: 210, align: 'center', fixed: 'right', slot: 'actions' }
]

const selected = ref<PostRow[]>([])
const deptTree = ref<DeptNode[]>([])

/** 岗位可以是「全公司通用」，所以选择器要有 dept_id = 0 这一项 */
const deptOptions = ref<Array<{ id: number; name: string; depth: number }>>([])

async function loadDepts() {
  deptTree.value = await fetchDeptTree()
  deptOptions.value = [{ id: 0, name: '全公司通用', depth: 0 }, ...flatten(deptTree.value)]
}

/**
 * 默认角色的候选
 *
 * 只是「新人入职时的初始值」，不是这个岗位的权限——所以允许留空（0 = 不带角色）。
 * 表单里那句说明必须留着：不写的话很容易被理解成「这个岗位就有这些权限」。
 */
const roleOptions = ref<Array<{ id: number; name: string }>>([])

async function loadRoles() {
  roleOptions.value = await fetchRoleOptions()
}

/**
 * 下拉候选要显式带上 0
 *
 * 0 是「不带角色」这个**取值**，不是「没选」。原来靠 clearable + placeholder 表达，
 * 但 placeholder 只在值为空时才出现，而这一项的初始值就是 0——
 * el-select 找不到 id 为 0 的选项时会把原始值直接渲染出来，
 * 于是新增岗位时「默认角色」显示成一个光秃秃的 `0`。
 *
 * 同一个表单里的「所属部门」早就是这么处理的（0 = 全公司通用），
 * 角色的「继承自」、部门与菜单的上级选择器也都是，这里是唯一漏掉的一处。
 */
const roleChoices = computed(() => [{ id: 0, name: '不带角色' }, ...roleOptions.value])

/**
 * 详情里把角色 id 显示成名字
 *
 * 0 是「不带角色」，是合法取值不是缺失，所以给明确文案而不是「—」。
 * 查不到则说明角色被删了——直接显示这句，比显示一个孤零零的 id 有用。
 */
function roleName(id?: number): string {
  if (!id) return '不带角色'
  return roleOptions.value.find((r) => r.id === id)?.name ?? `角色已删除（#${id}）`
}

/** 树压平成下拉选项，用全角空格做层级缩进——比再塞一个树选择器轻 */
/** depth 只用于下拉项的缩进渲染，不进 name（否则选中后输入框里带缩进） */
function flatten(nodes: DeptNode[], depth = 0): Array<{ id: number; name: string; depth: number }> {
  return nodes.flatMap((n) => [
    { id: n.id, name: n.name, depth },
    ...(n.children?.length ? flatten(n.children, depth + 1) : [])
  ])
}

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)

const rules: FormRules = {
  name: [{ required: true, message: '请输入岗位名称', trigger: 'blur' }]
}

const errorFields = {
  [BizCode.DATA_SCOPE_DENIED]: 'dept_id'
}

function onCreate() {
  editingId.value = 0
  drawerRef.value?.open({
    title: '新增岗位',
    data: { name: '', dept_id: 0, default_role_id: 0, sort: 0, status: 1, remark: '' }
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

function submit(form: PostPayload) {
  // 不传 code：编码由后端按主键生成，校验器里根本没有这个字段
  const payload = {
    name: form.name,
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
  loadRoles()
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
      id-column
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
          <el-button :icon="View" link type="primary" @click="onView(row)">详情</el-button>
          <el-button :icon="EditPen" v-permission="'sys:post:update'" link type="primary" @click="onEdit(row)">
            编辑
          </el-button>
          <el-button :icon="Delete" v-permission="'sys:post:delete'" link type="danger" @click="onDelete(row)">
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
          <el-descriptions-item label="默认角色">{{ roleName(form.default_role_id) }}</el-descriptions-item>
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
        <el-form-item label="所属部门" prop="dept_id" :error="errors.dept_id">
          <el-select v-model="form.dept_id" style="width: 100%">
            <el-option
              v-for="opt in deptOptions"
              :key="opt.id"
              :label="opt.name"
              :value="opt.id"
            >
              <span :style="{ paddingLeft: opt.depth * 16 + 'px' }">{{ opt.name }}</span>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="默认角色" prop="default_role_id">
          <el-select v-model="form.default_role_id" style="width: 100%">
            <el-option v-for="r in roleChoices" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
          <div class="tip">
            仅在<strong>新建用户</strong>选中此岗位时带出，作为角色的初始值。
            之后改岗位不会改动已有账号的角色——岗位不是权限。
          </div>
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
