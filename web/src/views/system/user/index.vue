<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules, type UploadRequestOptions } from 'element-plus'
import { Download, Key, Plus, Upload } from '@element-plus/icons-vue'
import {
  createUser,
  deleteUser,
  fetchDeptTree,
  fetchRoleOptions,
  fetchUser,
  fetchUsers,
  importUsers,
  resetUserPassword,
  setUserStatus,
  updateUser,
  type DeptNode,
  type UserRow
} from '@/api/system'
import { download } from '@/utils/request'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 用户管理（RBAC 的**分配**层）
 *
 * 这里只把已有角色分给人，不在用户身上单独授权——用户身上一旦能独立加权限，
 * 「这个人为什么能看到这个」就再也说不清了。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '', dept_id: '' })
const paramParsers = { status: Number, dept_id: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '账号 / 姓名 / 手机号' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'user_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'username', label: '账号', minWidth: 120, sortable: true, fixed: 'left' },
  { prop: 'real_name', label: '姓名', minWidth: 100 },
  { prop: 'dept_name', label: '部门', minWidth: 110 },
  { prop: 'post_name', label: '岗位', minWidth: 120 },
  { prop: 'phone', label: '手机号', minWidth: 130 },
  { prop: 'email', label: '邮箱', minWidth: 180, hidden: true },
  { prop: 'status', label: '状态', width: 100, align: 'center', dict: 'user_status' },
  { prop: 'last_login_at', label: '最后登录', minWidth: 160, sortable: true },
  { prop: 'actions', label: '操作', width: 230, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 部门树与选项
const deptTree = ref<DeptNode[]>([])
const deptLoading = ref(false)
const roleOptions = ref<Array<{ id: number; name: string }>>([])

async function loadDeptTree() {
  deptLoading.value = true
  try {
    deptTree.value = await fetchDeptTree()
  } finally {
    deptLoading.value = false
  }
}

function onDeptClick(node: DeptNode) {
  // 再点一次已选中的部门 = 取消筛选
  query.value = { ...query.value, dept_id: query.value.dept_id === node.id ? '' : node.id }
  tableRef.value?.reload()
}

/** 部门树压平成下拉项，全角空格做层级缩进 */
const deptOptions = computed(() => {
  const flatten = (nodes: DeptNode[], depth = 0): Array<{ id: number; name: string }> =>
    nodes.flatMap((n) => [
      { id: n.id, name: '　'.repeat(depth) + n.name },
      ...(n.children?.length ? flatten(n.children, depth + 1) : [])
    ])

  return [{ id: 0, name: '未分配' }, ...flatten(deptTree.value)]
})

// ---------------------------------------------------------------- 增改删
const editingId = ref(0)

const rules: FormRules = {
  username: [
    { required: true, message: '请输入账号', trigger: 'blur' },
    { min: 2, max: 64, message: '长度 2~64 个字符', trigger: 'blur' }
  ],
  real_name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  phone: [{ pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }]
}

const errorFields = { 20101: 'username' }

function onCreate() {
  editingId.value = 0
  drawerRef.value?.open({
    title: '新增用户',
    data: {
      username: '',
      real_name: '',
      phone: '',
      email: '',
      dept_id: 0,
      post_id: 0,
      status: 1,
      remark: '',
      role_ids: []
    }
  })
}

async function onEdit(row: UserRow) {
  const detail = await fetchUser(row.id)
  editingId.value = row.id
  drawerRef.value?.open({ title: '编辑用户', data: { ...detail } })
}

async function onView(row: UserRow) {
  const detail = await fetchUser(row.id)
  editingId.value = 0
  drawerRef.value?.open({ title: '用户详情', data: { ...detail }, mode: 'view' })
}

async function submit(form: Record<string, any>) {
  const payload = {
    username: form.username,
    real_name: form.real_name,
    phone: form.phone ?? '',
    email: form.email ?? '',
    dept_id: form.dept_id ?? 0,
    post_id: form.post_id ?? 0,
    status: form.status ?? 1,
    remark: form.remark ?? '',
    role_ids: form.role_ids ?? []
  }

  if (editingId.value) {
    return updateUser(editingId.value, payload)
  }

  const created = await createUser(payload)

  // 初始密码只有这一次能拿到，用需要手动确认的弹窗而不是一闪而过的 toast
  await ElMessageBox.alert(
    `账号：${created.username}<br>初始密码：<b>${created.initial_password}</b><br><br>` +
      '这串密码只显示这一次，请立即转交本人。本人首次登录后会被提示修改。',
    '用户已创建',
    { dangerouslyUseHTMLString: true, confirmButtonText: '我已记录' }
  )

  return created
}

async function onDelete(row: UserRow) {
  await ElMessageBox.confirm(`确定删除用户「${row.real_name || row.username}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteUser(row.id)
  ElMessage.success('已删除')
  tableRef.value?.refresh()
}

async function onToggleStatus(row: UserRow) {
  const next = row.status === 0 ? 1 : 0
  const word = next === 0 ? '停用' : '启用'

  await ElMessageBox.confirm(
    next === 0
      ? `停用后「${row.username}」将立即无法登录。若他名下还有未交接的数据，系统会拦下这次操作。`
      : `确定启用「${row.username}」吗？`,
    `${word}确认`,
    { type: 'warning' }
  )

  await setUserStatus(row.id, next)
  ElMessage.success(`已${word}`)
  tableRef.value?.refresh()
}

async function onResetPassword(row: UserRow) {
  await ElMessageBox.confirm(
    `确定重置「${row.username}」的密码吗？重置后系统生成一串新密码，原密码立即失效。`,
    '重置密码',
    { type: 'warning' }
  )

  const { password } = await resetUserPassword(row.id)

  await ElMessageBox.alert(
    `新密码：<b>${password}</b><br><br>只显示这一次，请立即转交本人。`,
    '密码已重置',
    { dangerouslyUseHTMLString: true, confirmButtonText: '我已记录' }
  )
}

// ---------------------------------------------------------------- 导入导出
const importing = ref(false)

/** 把当前筛选一并带上：导出的应该是「我现在看到的这批」，不是全表 */
function onExport() {
  return download('/admin/users/export', { ...query.value }, '用户列表.xlsx')
}

function onDownloadTemplate() {
  return download('/admin/users/import-template', undefined, '用户导入模板.xlsx')
}

/** 接管 el-upload 的请求，走统一封装（带 token、共用错误处理） */
async function onImport(options: UploadRequestOptions) {
  importing.value = true
  try {
    const result = await importUsers(options.file)

    if (result.fail_count === 0) {
      ElMessage.success(`导入成功 ${result.success_count} 条`)
    } else {
      // 失败明细带行号，用户手上只有那个 Excel，得知道回去改哪一行
      await ElMessageBox.alert(
        result.failed.map((f) => f.reason).join('<br>'),
        `成功 ${result.success_count} 条，失败 ${result.fail_count} 条`,
        { dangerouslyUseHTMLString: true, type: 'warning' }
      )
    }

    tableRef.value?.refresh()
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  dictStore.preload(['user_status'])
  loadDeptTree()
  fetchRoleOptions().then((roles) => (roleOptions.value = roles))
})
</script>

<template>
  <div class="page user-page">
    <aside class="dept-panel" v-loading="deptLoading">
      <div class="panel-title">部门</div>
      <el-tree
        :data="deptTree"
        :props="{ label: 'name', children: 'children' }"
        node-key="id"
        default-expand-all
        :expand-on-click-node="false"
        :current-node-key="(query.dept_id as number) || undefined"
        highlight-current
        @node-click="onDeptClick"
      />
      <EmptyState
        v-if="!deptLoading && deptTree.length === 0"
        description="无可见部门"
        :size="60"
        :action="false"
      />
    </aside>

    <section class="list-panel">
      <SearchForm
        v-model="query"
        :fields="searchFields"
        @search="tableRef?.reload()"
        @reset="tableRef?.reload()"
      />

      <ProTable
        ref="tableRef"
        v-model:params="query"
        :request="fetchUsers"
        :param-parsers="paramParsers"
        :columns="columns"
        index
      >
        <template #toolbar>
          <el-button v-permission="'sys:user:create'" type="primary" :icon="Plus" @click="onCreate">
            新增
          </el-button>

          <el-upload
            v-permission="'sys:user:import'"
            :show-file-list="false"
            :http-request="onImport"
            accept=".xlsx,.csv"
          >
            <el-button :icon="Upload" :loading="importing">导入</el-button>
          </el-upload>

          <el-button v-permission="'sys:user:import'" link type="primary" @click="onDownloadTemplate">
            下载模板
          </el-button>

          <el-button v-permission="'sys:user:export'" :icon="Download" @click="onExport">
            导出
          </el-button>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button link type="primary" @click="onView(row)">详情</el-button>

            <template v-if="!row.is_super">
              <el-button v-permission="'sys:user:update'" link type="primary" @click="onEdit(row)">
                编辑
              </el-button>
              <el-dropdown>
                <el-button link type="primary">更多</el-button>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item
                      v-permission="'sys:user:resetPwd'"
                      :icon="Key"
                      @click="onResetPassword(row)"
                    >
                      重置密码
                    </el-dropdown-item>
                    <el-dropdown-item v-permission="'sys:user:update'" @click="onToggleStatus(row)">
                      {{ row.status === 0 ? '启用' : '停用' }}
                    </el-dropdown-item>
                    <el-dropdown-item v-permission="'sys:user:delete'" divided @click="onDelete(row)">
                      删除
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </template>

            <!-- 超管的写操作服务端一律拒绝，这里不给入口，省得白点一次才知道 -->
            <el-tag v-else size="small" type="warning" effect="plain">超管不可操作</el-tag>
          </div>
        </template>
      </ProTable>
    </section>

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
          <el-descriptions-item label="账号">{{ form.username }}</el-descriptions-item>
          <el-descriptions-item label="姓名">{{ form.real_name }}</el-descriptions-item>
          <el-descriptions-item label="手机号">{{ form.phone || '—' }}</el-descriptions-item>
          <el-descriptions-item label="邮箱">{{ form.email || '—' }}</el-descriptions-item>
          <el-descriptions-item label="部门">{{ form.dept_name || '未分配' }}</el-descriptions-item>
          <el-descriptions-item label="岗位">{{ form.post_name || '—' }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="user_status" :value="form.status" />
          </el-descriptions-item>
          <el-descriptions-item label="最后登录">
            {{ form.last_login_at || '从未登录' }}
          </el-descriptions-item>
          <el-descriptions-item label="备注">{{ form.remark || '—' }}</el-descriptions-item>
        </el-descriptions>

        <template v-else>
          <el-form-item label="账号" prop="username" :error="errors.username">
            <el-input v-model="form.username" :disabled="!!editingId" maxlength="64" />
            <div v-if="editingId" class="tip">账号是登录凭据，创建后不允许修改</div>
          </el-form-item>
          <el-form-item label="姓名" prop="real_name">
            <el-input v-model="form.real_name" maxlength="64" />
          </el-form-item>
          <el-form-item label="手机号" prop="phone">
            <el-input v-model="form.phone" maxlength="20" />
          </el-form-item>
          <el-form-item label="邮箱" prop="email">
            <el-input v-model="form.email" maxlength="128" />
          </el-form-item>
          <el-form-item label="部门" prop="dept_id">
            <el-select v-model="form.dept_id" style="width: 100%">
              <el-option v-for="d in deptOptions" :key="d.id" :label="d.name" :value="d.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="角色" prop="role_ids">
            <el-select v-model="form.role_ids" multiple style="width: 100%" placeholder="可多选">
              <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
            </el-select>
            <div class="tip">多角色时功能权限取并集，数据范围取最大的那个</div>
          </el-form-item>
          <el-form-item label="状态" prop="status">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">在职</el-radio>
              <el-radio :value="2">试用期</el-radio>
              <el-radio :value="0">停用</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="备注" prop="remark">
            <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255" />
          </el-form-item>
          <el-alert v-if="!editingId" type="info" :closable="false" show-icon>
            不填密码时系统会生成一串随机初始密码，保存后只显示一次。
          </el-alert>
        </template>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
.user-page {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 12px;
  align-items: start;
}

.dept-panel {
  padding: 16px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: var(--el-border-radius-base);
}

.panel-title {
  margin-bottom: 12px;
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.list-panel {
  min-width: 0;
}

.tip {
  margin-top: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

@media (max-width: 900px) {
  .user-page {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
