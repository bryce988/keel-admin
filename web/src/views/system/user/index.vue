<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { ArrowDown, Delete, Download, EditPen, Key, Plus, Upload, View } from '@element-plus/icons-vue'
import {
  createUser,
  deleteUser,
  fetchDeptTree,
  fetchPostOptions,
  fetchRoleOptions,
  fetchUser,
  fetchUsers,
  importUsers,
  resetUserPassword,
  setUserStatus,
  updateUser,
  type DeptNode,
  type PostOption,
  type UserRow,
  type UserPayload
} from '@/api/system'
import { download } from '@/utils/request'
import ImportDialog from '@/components/ImportDialog.vue'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import { useUserStore } from '@/stores/user'
import { BizCode } from '@/constants/bizCode'

/**
 * 用户管理（RBAC 的分配层）
 *
 * 这里只把已有角色分给人，不在用户身上单独授权——用户身上一旦能独立加权限，
 * 「这个人为什么能看到这个」就再也说不清了。
 */
const dictStore = useDictStore()
const userStore = useUserStore()

/**
 * 三个写权限。`update` 同时管着「编辑」按钮和状态列那个开关，
 * `resetPwd` / `remove` 是「更多」里的两项——两项都没有时整个下拉不渲染，
 * 免得点开是空的。
 */
const can = computed(() => ({
  resetPwd: userStore.can('sys:user:resetPwd'),
  update: userStore.can('sys:user:update'),
  remove: userStore.can('sys:user:delete')
}))
const canMore = computed(() => can.value.resetPwd || can.value.remove)

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance<UserPayload> | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', status: '', dept_id: '' })
const paramParsers = { status: Number, dept_id: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '账号 / 姓名 / 手机号' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'user_status', numeric: true }
]

const columns: ProColumn<UserRow>[] = [
  { prop: 'username', label: '账号', minWidth: 130, align: 'center', sortable: true, fixed: 'left' },
  { prop: 'real_name', label: '姓名', minWidth: 120, align: 'center' },
  { prop: 'dept_name', label: '部门', minWidth: 130, align: 'center' },
  { prop: 'post_name', label: '岗位', minWidth: 140, align: 'center' },
  { prop: 'phone', label: '手机号', minWidth: 140, align: 'center' },
  { prop: 'email', label: '邮箱', minWidth: 200, align: 'center', hidden: true },
  { prop: 'status', label: '状态', width: 110, align: 'center', slot: 'status' },
  { prop: 'last_login_at', label: '最后登录', minWidth: 190, align: 'center', sortable: true },
  { prop: 'actions', label: '操作', width: 210, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 部门树与选项
const deptTree = ref<DeptNode[]>([])
const deptLoading = ref(false)
const roleOptions = ref<Array<{ id: number; name: string }>>([])
const postOptions = ref<PostOption[]>([])

async function loadPosts() {
  postOptions.value = await fetchPostOptions()
}

/**
 * 下拉候选要显式带上 0
 *
 * 0 是「未设置」这个取值，不是「没选」。placeholder 只在值为空时出现，
 * 而初始值就是 0——el-select 找不到对应选项时会把原始值直接渲染出来，
 * 于是新增用户时「岗位」显示成一个光秃秃的 `0`（隔壁「部门」不会，
 * 因为它的候选里本来就有 0 = 未分配）。
 *
 * 只取 id 与 name：postOptions 上还挂着 default_role_id，
 * 那是给 onPostChange 带默认角色用的，与下拉渲染无关。
 */
const postChoices = computed(() => [
  { id: 0, name: '未设置' },
  ...postOptions.value.map((p) => ({ id: p.id, name: p.name }))
])

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

/** 部门树压平成下拉项，depth 只用于下拉项的缩进渲染，不进 label（否则选中后输入框里带缩进） */
const deptOptions = computed(() => {
  const flatten = (nodes: DeptNode[], depth = 0): Array<{ id: number; name: string; depth: number }> =>
    nodes.flatMap((n) => [
      { id: n.id, name: n.name, depth },
      ...(n.children?.length ? flatten(n.children, depth + 1) : [])
    ])

  return [{ id: 0, name: '未分配', depth: 0 }, ...flatten(deptTree.value)]
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

const errorFields = {
  [BizCode.ACCOUNT_EXISTS]: 'username',
  // 所选部门超出数据范围，红框标在部门选择器上而不是只弹一句
  [BizCode.DATA_SCOPE_DENIED]: 'dept_id'
}

/**
 * 岗位带出默认角色
 *
 * 只在**新增**时生效。编辑时绝不能动角色——那等于「改岗位就改权限」，
 * 正好撞上岗位与角色解耦这条设计红线（database.md §3.3）：
 * 调个岗结果一批人权限变了，是会上生产事故的。
 *
 * 换岗位时是否覆盖已选角色，取「用户没动过才跟着变」：
 *   - 角色为空 → 填上新岗位的默认值
 *   - 角色正好等于上一个岗位带出来的那份（说明用户没改过）→ 换成新的
 *   - 用户手动增删过 → 一律不动，他的选择优先
 * 直接覆盖会毁掉手动选的角色；完全不覆盖又会在换岗位后留着上一个岗位的默认值。
 */
const autoFilledRoles = ref<number[]>([])

function sameRoles(a: number[], b: number[]) {
  return a.length === b.length && a.every((v) => b.includes(v))
}

function onPostChange(form: UserPayload) {
  if (editingId.value) return

  const current = form.role_ids ?? []
  const touched = current.length > 0 && !sameRoles(current, autoFilledRoles.value)
  if (touched) return

  const post = postOptions.value.find((p) => p.id === form.post_id)
  const next = post?.default_role_id ? [post.default_role_id] : []
  form.role_ids = next
  autoFilledRoles.value = next
}

function onCreate() {
  editingId.value = 0
  autoFilledRoles.value = []
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

async function submit(form: UserPayload) {
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

/** 正在提交状态变更的那一行；开关的 loading 只点亮这一个，不锁整张表 */
const togglingId = ref(0)

/**
 * 切换启用 / 停用
 *
 * 挂在 el-switch 的 `before-change` 上，返回 `Promise<boolean>`：
 * **resolve(true) 开关才会翻**，resolve(false) 或 reject 都保持原样。
 * 开关是单击即生效的控件，误触成本比按钮高得多，所以确认框放在翻转之前——
 * 先翻再问「要撤销吗」的话，用户看到的已经是错误的状态。
 *
 * 成功后直接改 `row.status` 而不是 `refresh()` 整张表：翻一个开关就重拉一页，
 * 会把分页、滚动位置和多选一起重置，代价远大于同步一个字段。
 *
 * 两处 catch 都得吞掉再返回 false：取消确认框和接口报错都会 reject，
 * 而 EP 对 reject 的处理是 `debugWarn` 打一条控制台警告——那不是错误，
 * 「点了取消」是正常操作。接口失败的原因（不能停用自己、名下还有待交接的数据）
 * 由请求拦截器统一弹出，这里只负责别让开关翻过去。
 */
async function onToggleStatus(row: UserRow): Promise<boolean> {
  const next = row.status === 1 ? 0 : 1
  const word = next === 0 ? '停用' : '启用'

  try {
    await ElMessageBox.confirm(
      next === 0
        ? `停用后「${row.username}」将立即无法登录。若他名下还有未交接的数据，系统会拦下这次操作。`
        : `确定启用「${row.username}」吗？`,
      `${word}确认`,
      { type: 'warning', confirmButtonText: word }
    )
  } catch {
    return false
  }

  togglingId.value = row.id
  try {
    await setUserStatus(row.id, next)
  } catch {
    return false
  } finally {
    togglingId.value = 0
  }

  row.status = next
  ElMessage.success(`已${word}`)
  return true
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
const importRef = ref<InstanceType<typeof ImportDialog> | null>(null)
const exporting = ref(false)

/** 把当前筛选一并带上：导出的应该是「我现在看到的这批」，不是全表 */
async function onExport() {
  exporting.value = true
  try {
    await download('/admin/users/export', { ...query.value }, '用户列表.xlsx')
  } finally {
    exporting.value = false
  }
}

function onDownloadTemplate() {
  return download('/admin/users/import-template', undefined, '用户导入模板.xlsx')
}

onMounted(() => {
  dictStore.preload(['user_status'])
  loadDeptTree()
  loadPosts()
  fetchRoleOptions().then((roles) => (roleOptions.value = roles))
})
</script>

<template>
  <div class="page user-page">
    <aside class="panel dept-panel" v-loading="deptLoading">
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
        id-column
      >
        <template #toolbar>
          <el-button v-permission="'sys:user:create'" type="primary" :icon="Plus" @click="onCreate">
            新增
          </el-button>

          <!-- 模板下载收进了导入弹窗，工具栏不再为它占一个位置 -->
          <el-button
            v-permission="'sys:user:import'"
            :icon="Upload"
            @click="importRef?.open()"
          >
            导入
          </el-button>

          <el-button
            v-permission="'sys:user:export'"
            :icon="Download"
            :loading="exporting"
            @click="onExport"
          >
            导出
          </el-button>
        </template>

        <!--
          状态列是开关不是标签：这一列上唯一会做的事就是启用/停用，
          「看状态」和「改状态」用同一个控件，比标签旁边再放一个按钮少一次跳转。
          超管与无 sys:user:update 权限的人看到的是**灰掉的**开关——
          仍然读得出状态，只是按不动（真正的拦截在后端路由的 perm 声明上）。
        -->
        <template #status="{ row }">
          <el-switch
            class="status-switch"
            :model-value="row.status === 1"
            :loading="togglingId === row.id"
            :disabled="row.is_super || !can.update"
            inline-prompt
            active-text="启用"
            inactive-text="停用"
            :before-change="() => onToggleStatus(row)"
          />
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button :icon="View" link type="primary" @click="onView(row)">详情</el-button>

            <template v-if="!row.is_super">
              <el-button :icon="EditPen" v-permission="'sys:user:update'" link type="primary" @click="onEdit(row)">
                编辑
              </el-button>
              <!--
                下拉项用 v-if 而不是 v-permission：el-dropdown-item 的根节点是
                Fragment，运行时指令挂不上去（控制台会报 "Runtime directive used on
                component with non-element root node"），指令拿到的 el 是 Vue 用来
                定位片段的锚点文本节点，删掉它等于破坏 Vue 的 DOM 记账。
              -->
              <el-dropdown v-if="canMore">
                <!--
                  「更多」的箭头放在文字**右边**（`el-icon--right`），与其余按钮的
                  左置图标不同：左边的图标说「这个动作是什么」，而这里的向下箭头说的是
                  「点了会展开一层」，是方向提示，跟着文字走才读得通。
                -->
                <el-button link type="primary">
                  更多
                  <el-icon class="el-icon--right"><ArrowDown /></el-icon>
                </el-button>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item
                      v-if="can.resetPwd"
                      :icon="Key"
                      @click="onResetPassword(row)"
                    >
                      重置密码
                    </el-dropdown-item>
                    <el-dropdown-item v-if="can.remove" :icon="Delete" divided @click="onDelete(row)">
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
          <el-form-item label="部门" prop="dept_id" :error="errors.dept_id">
            <el-select v-model="form.dept_id" style="width: 100%">
              <el-option v-for="d in deptOptions" :key="d.id" :label="d.name" :value="d.id">
                <span :style="{ paddingLeft: d.depth * 16 + 'px' }">{{ d.name }}</span>
              </el-option>
            </el-select>
          </el-form-item>
          <el-form-item label="岗位" prop="post_id">
            <el-select v-model="form.post_id" style="width: 100%" @change="onPostChange(form)">
              <el-option v-for="p in postChoices" :key="p.id" :label="p.name" :value="p.id" />
            </el-select>
            <div v-if="!editingId" class="tip">选中岗位会带出它的默认角色，之后可以自行调整</div>
          </el-form-item>
          <el-form-item label="角色" prop="role_ids">
            <el-select v-model="form.role_ids" multiple style="width: 100%" placeholder="可多选">
              <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
            </el-select>
            <div class="tip">多角色时功能权限取并集，数据范围取最大的那个</div>
          </el-form-item>
          <el-form-item label="状态" prop="status">
            <el-switch
              v-model="form.status"
              :active-value="1"
              :inactive-value="0"
              inline-prompt
              active-text="启用"
              inactive-text="停用"
            />
            <div class="tip">停用后该账号立即无法登录，已签发的令牌下次请求即失效</div>
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

    <ImportDialog
      ref="importRef"
      title="导入用户"
      accept=".xlsx,.csv"
      :max-size="10"
      :download-template="onDownloadTemplate"
      :upload="importUsers"
      @success="tableRef?.refresh()"
    />
  </div>
</template>

<style scoped>
.user-page {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  /* 左树与右侧内容是两个面板，用面板之间的大间距 */
  gap: var(--keel-gap-lg);
  align-items: start;
}

/* 面板外观走全局 .panel（styles/index.css） */

.panel-title {
  margin-bottom: var(--keel-gap);
  font-size: 14px;
  font-weight: 500;
  color: var(--el-text-color-primary);
}

/* 搜索栏与表格是两个并列面板，间距由容器统一给。
   曾经靠 SearchForm 自带的 margin-bottom 撑开，但那样在 .page 这种
   本身有 gap 的容器里会叠加成两倍——间距只能有一个来源 */
.list-panel {
  display: flex;
  flex-direction: column;
  gap: var(--keel-gap-lg);
  min-width: 0;
}

/*
 * `width: 100%` 不能省：el-form-item 的内容区是 flex 容器，
 * 而状态那一项的控件是个 48px 宽的开关，右边剩的空间足够把说明拽到同一行去，
 * 于是这条说明的位置跟别处（控件本来就占满整行）对不上。占满一行才统一。
 */
.tip {
  width: 100%;
  margin-top: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/*
 * el-switch 的**外框**默认 32px 高（轨道本身只有 20px，其余是上下留白），
 * 直接放进表格会把行高从 40px 顶到 48px，只为一列多出 8px。
 * 压到 24px 与操作列的 link 按钮同高，轨道仍然放得下，
 * 点击区（整个 .el-switch）也还够 WCAG 2.5.8 要求的 24px。
 */
.status-switch {
  height: 24px;
}

@media (max-width: 900px) {
  .user-page {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
