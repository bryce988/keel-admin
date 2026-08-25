<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { Key, Plus, User } from '@element-plus/icons-vue'
import {
  createRole,
  deleteRole,
  fetchRoleOptions,
  fetchRoles,
  updateRole,
  type RoleRow,
  type RolePayload
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import GrantDrawer from './GrantDrawer.vue'
import MemberDrawer from './MemberDrawer.vue'
import { BizCode } from '@/constants/bizCode'

/**
 * 角色管理（RBAC 的授权层）
 *
 * 三层职责分离：定义（菜单权限）→ 授权（本页） → 分配（用户管理）。
 * 本页只回答「这个角色能干什么」；「谁是这个角色」在用户管理里分配，
 * 成员 tab 是同一件事的反向入口，走的是同一套校验。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance<RolePayload> | null>(null)
/**
 * 子组件的 ref 用 `InstanceType<typeof X>`，不要手写 `{ open: ... }`
 *
 * 手写等于跟 TS 打包票「这个组件有 open」，而 TS 无从核对子组件到底
 * `defineExpose` 了什么——MemberDrawer 漏了那一句，类型检查照样通过，
 * 直到点下「成员」才 TypeError。用 InstanceType 才是真的去核对。
 */
const grantRef = ref<InstanceType<typeof GrantDrawer> | null>(null)
const memberRef = ref<InstanceType<typeof MemberDrawer> | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '', data_scope: '' })
const paramParsers = { status: Number, data_scope: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'data_scope', label: '数据范围', type: 'dict', dict: 'data_scope', numeric: true },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn<RoleRow>[] = [
  { prop: 'name', label: '角色名称', minWidth: 140, slot: 'name' },
  { prop: 'code', label: '角色编码', minWidth: 160 },
  { prop: 'data_scope', label: '数据范围', width: 130, align: 'center', dict: 'data_scope' },
  { prop: 'member_count', label: '成员', width: 80, align: 'center' },
  { prop: 'sort', label: '排序', width: 100, align: 'center', sortable: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'remark', label: '备注', minWidth: 200, hidden: true },
  { prop: 'actions', label: '操作', width: 250, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)
const parentOptions = ref<Array<{ id: number; name: string }>>([])

const rules: FormRules = {
  name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入角色编码', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_:.-]+$/, message: '只能包含字母、数字与 _ : . -', trigger: 'blur' }
  ]
}

const errorFields = { [BizCode.ROLE_CODE_EXISTS]: 'code' }

async function loadParentOptions(excludeId = 0) {
  const roles = await fetchRoleOptions()
  parentOptions.value = [
    { id: 0, name: '不继承' },
    ...roles.filter((r) => r.id !== excludeId).map((r) => ({ id: r.id, name: r.name }))
  ]
}

async function onCreate() {
  editingId.value = 0
  await loadParentOptions()
  drawerRef.value?.open({
    title: '新增角色',
    data: { name: '', code: '', parent_id: 0, data_scope: 4, sort: 0, status: 1, remark: '' }
  })
}

async function onEdit(row: RoleRow) {
  editingId.value = row.id
  await loadParentOptions(row.id)
  drawerRef.value?.open({ title: '编辑角色', data: { ...row } })
}

function onView(row: RoleRow) {
  editingId.value = 0
  drawerRef.value?.open({ title: '角色详情', data: { ...row }, mode: 'view' })
}

function submit(form: RolePayload) {
  const payload = {
    name: form.name,
    code: form.code,
    parent_id: form.parent_id ?? 0,
    data_scope: form.data_scope ?? 4,
    sort: form.sort ?? 0,
    status: form.status ?? 1,
    remark: form.remark ?? ''
  }

  return editingId.value ? updateRole(editingId.value, payload) : createRole(payload)
}

async function onDelete(row: RoleRow) {
  await ElMessageBox.confirm(
    `确定删除角色「${row.name}」吗？角色下还有成员时无法删除。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteRole(row.id)
  ElMessage.success('已删除')
  tableRef.value?.refresh()
}

onMounted(() => dictStore.preload(['data_scope', 'enable_status']))
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
      :request="fetchRoles"
      :param-parsers="paramParsers"
      :columns="columns"
      index
    >
      <template #toolbar>
        <el-button v-permission="'sys:role:create'" type="primary" :icon="Plus" @click="onCreate">
          新增角色
        </el-button>
      </template>

      <template #name="{ row }">
        {{ row.name }}
        <el-tag v-if="row.is_builtin" size="small" type="warning" effect="plain">内置</el-tag>
      </template>

      <template #actions="{ row }">
        <div class="table-actions">
          <el-button link type="primary" @click="onView(row)">详情</el-button>
          <el-button
            v-permission.any="['sys:role:grantPerm', 'sys:role:grantData']"
            link
            type="primary"
            :icon="Key"
            @click="grantRef?.open(row)"
          >
            授权
          </el-button>
          <el-button link type="primary" :icon="User" @click="memberRef?.open(row)">成员</el-button>
          <el-button
            v-permission="'sys:role:update'"
            link
            type="primary"
            :disabled="row.is_builtin"
            @click="onEdit(row)"
          >
            编辑
          </el-button>
          <el-button
            v-permission="'sys:role:delete'"
            link
            type="danger"
            :disabled="row.is_builtin"
            @click="onDelete(row)"
          >
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
      <template #default="{ form, errors, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="角色名称">{{ form.name }}</el-descriptions-item>
          <el-descriptions-item label="角色编码">{{ form.code }}</el-descriptions-item>
          <el-descriptions-item label="数据范围">
            <DictTag code="data_scope" :value="form.data_scope" />
          </el-descriptions-item>
          <el-descriptions-item label="成员数">{{ form.member_count }}</el-descriptions-item>
          <el-descriptions-item label="内置">{{ form.is_builtin ? '是' : '否' }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="enable_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="备注">{{ form.remark || '—' }}</el-descriptions-item>
        </el-descriptions>

        <template v-else>
          <el-form-item label="角色名称" prop="name">
            <el-input v-model="form.name" maxlength="64" show-word-limit />
          </el-form-item>
          <el-form-item label="角色编码" prop="code" :error="errors.code">
            <el-input v-model="form.code" maxlength="64" placeholder="如 ROLE_AUDITOR" />
          </el-form-item>
          <el-form-item label="继承自" prop="parent_id">
            <el-select v-model="form.parent_id" style="width: 100%">
              <el-option v-for="o in parentOptions" :key="o.id" :label="o.name" :value="o.id" />
            </el-select>
            <div class="tip">只支持单继承一层。继承来的权限在授权页里置灰，取消不掉</div>
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
          <el-alert type="info" :closable="false" show-icon>
            数据范围与功能权限在「授权」里配置，这里只维护角色本身的基本信息。
          </el-alert>
        </template>
      </template>
    </FormDrawer>

    <GrantDrawer ref="grantRef" @saved="tableRef?.refresh()" />
    <MemberDrawer ref="memberRef" @saved="tableRef?.refresh()" />
  </div>
</template>

<style scoped>
.tip {
  margin-top: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
