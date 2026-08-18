<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import * as ElIcons from '@element-plus/icons-vue'
import { Plus } from '@element-plus/icons-vue'
import {
  createMenu,
  deleteMenu,
  fetchMenuTree,
  updateMenu,
  type MenuNodeRow,
  type MenuType
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import PermissionMatrix from './PermissionMatrix.vue'

/**
 * 菜单与权限点（RBAC 的**定义**层）
 *
 * 这里只定义「系统里存在哪些权限」，**不做授权**——把权限给谁是角色管理的事。
 * 菜单的 path 与 component 直接驱动前端动态路由，改完用户刷新页面就生效，不用发版；
 * 反过来说填错了会让整个页面打不开，所以表单按类型收紧了可填字段。
 */
const dictStore = useDictStore()

const tab = ref<'tree' | 'matrix'>('tree')
const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', type: '', status: '' })
const paramParsers = { type: Number, status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 权限标识' },
  { prop: 'type', label: '类型', type: 'dict', dict: 'perm_type', numeric: true },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'name', label: '名称', minWidth: 200, align: 'left' },
  { prop: 'type', label: '类型', width: 90, align: 'center', dict: 'perm_type' },
  { prop: 'perm_code', label: '权限标识', minWidth: 190 },
  { prop: 'path', label: '路由路径', minWidth: 160, slot: 'path' },
  { prop: 'component', label: '组件', minWidth: 200, hidden: true },
  { prop: 'sort', label: '排序', width: 80, align: 'center' },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'actions', label: '操作', width: 210, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 类型驱动的表单
const TYPE_DIR = 1
const TYPE_MENU = 2
const TYPE_API = 4

/** 当前编辑的节点类型，决定表单显示哪些字段 */
const formType = ref<MenuType>(TYPE_MENU)
const isRoute = computed(() => formType.value === TYPE_DIR || formType.value === TYPE_MENU)
const isApi = computed(() => formType.value === TYPE_API)

/** 图标选择：EP 图标近 300 个，用可搜索的下拉，选项里直接画出来 */
const iconNames = Object.keys(ElIcons)
function iconComp(name: string) {
  return (ElIcons as Record<string, unknown>)[name]
}

// ---------------------------------------------------------------- 上级选择
const treeData = ref<MenuNodeRow[]>([])
const editingId = ref(0)

async function loadTreeOptions() {
  treeData.value = await fetchMenuTree()
}

function collectSubtreeIds(nodes: MenuNodeRow[], target: number, found = false): number[] {
  let ids: number[] = []
  for (const node of nodes) {
    const hit = found || node.id === target
    if (hit) ids.push(node.id)
    if (node.children?.length) ids = ids.concat(collectSubtreeIds(node.children, target, hit))
  }
  return ids
}

const parentOptions = computed(() => {
  const banned = editingId.value ? collectSubtreeIds(treeData.value, editingId.value) : []

  const decorate = (nodes: MenuNodeRow[]): any[] =>
    nodes.map((n) => ({
      ...n,
      // 按钮、接口、数据类节点不能当爹：它们不是容器
      disabled: banned.includes(n.id) || n.type > TYPE_MENU,
      children: n.children?.length ? decorate(n.children) : undefined
    }))

  return [{ id: 0, name: '顶级节点', disabled: false, children: decorate(treeData.value) }]
})

// ---------------------------------------------------------------- 增改删
const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }],
  perm_code: [
    { required: true, message: '请输入权限标识', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_:.-]+$/, message: '只能包含字母、数字与 _ : . -', trigger: 'blur' }
  ]
}

const errorFields = { 20401: 'perm_code' }

function blank(parentId = 0, type: MenuType = TYPE_MENU) {
  return {
    parent_id: parentId,
    name: '',
    type,
    perm_code: '',
    path: '',
    component: '',
    icon: '',
    api_method: 'GET',
    api_path: '',
    visible: 1,
    keep_alive: 1,
    sort: 0,
    status: 1
  }
}

function onCreate(parentId = 0, type: MenuType = TYPE_MENU) {
  editingId.value = 0
  formType.value = type
  drawerRef.value?.open({
    title: parentId ? '新增下级节点' : '新增顶级节点',
    data: blank(parentId, type)
  })
}

function onEdit(row: MenuNodeRow) {
  editingId.value = row.id
  formType.value = row.type
  drawerRef.value?.open({
    title: '编辑权限点',
    // 布尔要转回 0/1：后端字段是 TINYINT，表单用单选按钮绑数字更直观
    data: {
      ...row,
      children: undefined,
      visible: row.visible ? 1 : 0,
      keep_alive: row.keep_alive ? 1 : 0
    }
  })
}

function onView(row: MenuNodeRow) {
  editingId.value = 0
  formType.value = row.type
  drawerRef.value?.open({ title: '权限点详情', data: { ...row, children: undefined }, mode: 'view' })
}

function submit(form: Record<string, any>) {
  return editingId.value ? updateMenu(editingId.value, form) : createMenu(form)
}

async function onSaved() {
  await loadTreeOptions()
  tableRef.value?.refresh()
}

async function onDelete(row: MenuNodeRow) {
  await ElMessageBox.confirm(
    `确定删除「${row.name}」吗？被角色引用的权限点无法删除，只能停用。`,
    '删除确认',
    { type: 'warning', confirmButtonText: '删除', confirmButtonClass: 'el-button--danger' }
  )

  await deleteMenu(row.id)
  ElMessage.success('已删除')
  await loadTreeOptions()
  tableRef.value?.refresh()
}

onMounted(() => {
  dictStore.preload(['perm_type', 'enable_status'])
  loadTreeOptions()
})
</script>

<template>
  <div class="page">
    <div class="page-head">
      <h1>菜单与权限</h1>
      <span class="desc">只定义权限点，不做授权。新增接口不在这里登记就是 403</span>
    </div>

    <el-tabs v-model="tab" class="menu-tabs">
      <el-tab-pane label="权限点定义" name="tree" />
      <el-tab-pane label="角色 × 权限矩阵" name="matrix" />
    </el-tabs>

    <template v-if="tab === 'tree'">
      <SearchForm
        v-model="query"
        :fields="searchFields"
        @search="tableRef?.reload()"
        @reset="tableRef?.reload()"
      />

      <ProTable
        ref="tableRef"
        v-model:params="query"
        :request="fetchMenuTree"
        :param-parsers="paramParsers"
        :columns="columns"
        tree
      >
        <template #toolbar>
          <el-button
            v-permission="'sys:menu:create'"
            type="primary"
            :icon="Plus"
            @click="onCreate(0, 1)"
          >
            新增顶级目录
          </el-button>
        </template>

        <!-- 只有目录和菜单才有路由，按钮/接口/数据类节点这一列留白比显示空串清楚 -->
        <template #path="{ row }">
          <span v-if="row.path">{{ row.path }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button link type="primary" @click="onView(row)">详情</el-button>
            <el-button
              v-if="row.type <= 2"
              v-permission="'sys:menu:create'"
              link
              type="primary"
              @click="onCreate(row.id, row.type === 1 ? 2 : 3)"
            >
              新增下级
            </el-button>
            <el-button v-permission="'sys:menu:update'" link type="primary" @click="onEdit(row)">
              编辑
            </el-button>
            <el-button v-permission="'sys:menu:delete'" link type="danger" @click="onDelete(row)">
              删除
            </el-button>
          </div>
        </template>
      </ProTable>
    </template>

    <PermissionMatrix v-else />

    <FormDrawer
      ref="drawerRef"
      :submit="submit"
      :rules="rules"
      :error-fields="errorFields"
      size="600px"
      @success="onSaved"
    >
      <template #default="{ form, errors, readonly }">
        <el-descriptions v-if="readonly" :column="1" border>
          <el-descriptions-item label="名称">{{ form.name }}</el-descriptions-item>
          <el-descriptions-item label="类型">
            <DictTag code="perm_type" :value="form.type" />
          </el-descriptions-item>
          <el-descriptions-item label="权限标识">{{ form.perm_code }}</el-descriptions-item>
          <el-descriptions-item label="路由路径">{{ form.path || '—' }}</el-descriptions-item>
          <el-descriptions-item label="组件">{{ form.component || '—' }}</el-descriptions-item>
          <el-descriptions-item label="绑定接口">
            {{ form.api_path ? `${form.api_method} ${form.api_path}` : '—' }}
          </el-descriptions-item>
          <el-descriptions-item label="排序">{{ form.sort }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <DictTag code="enable_status" :value="form.status" />
          </el-descriptions-item>
        </el-descriptions>

        <template v-else>
          <el-form-item label="节点类型" prop="type">
            <el-radio-group v-model="form.type" @change="formType = form.type">
              <el-radio-button :value="1">目录</el-radio-button>
              <el-radio-button :value="2">菜单</el-radio-button>
              <el-radio-button :value="3">按钮</el-radio-button>
              <el-radio-button :value="4">接口</el-radio-button>
              <el-radio-button :value="5">数据</el-radio-button>
            </el-radio-group>
            <div class="tip">
              目录只分组不承载页面 · 菜单驱动前端路由 · 按钮/接口/数据只做权限校验
            </div>
          </el-form-item>

          <el-form-item label="上级节点" prop="parent_id">
            <el-tree-select
              v-model="form.parent_id"
              :data="parentOptions"
              :props="{ label: 'name', children: 'children', disabled: 'disabled' }"
              node-key="id"
              check-strictly
              default-expand-all
              style="width: 100%"
            />
          </el-form-item>

          <el-form-item label="名称" prop="name">
            <el-input v-model="form.name" maxlength="64" show-word-limit />
          </el-form-item>

          <el-form-item label="权限标识" prop="perm_code" :error="errors.perm_code">
            <el-input v-model="form.perm_code" maxlength="128" placeholder="如 sys:user:create" />
            <div class="tip">命名规范 模块:资源:操作，增删改分开，不用笼统的 edit</div>
          </el-form-item>

          <!-- 只有目录与菜单才有路由相关字段 -->
          <template v-if="isRoute">
            <el-form-item label="路由路径" prop="path">
              <el-input v-model="form.path" placeholder="如 /system/user" />
            </el-form-item>
            <el-form-item label="组件路径" prop="component">
              <el-input
                v-model="form.component"
                :placeholder="formType === 1 ? 'Layout' : 'views/system/user/index.vue'"
              />
              <div class="tip">目录填 Layout；菜单填相对 src 的组件路径，写错会导致页面打不开</div>
            </el-form-item>
            <el-form-item label="图标" prop="icon">
              <el-select v-model="form.icon" filterable clearable style="width: 100%">
                <el-option v-for="name in iconNames" :key="name" :label="name" :value="name">
                  <span class="icon-option">
                    <el-icon><component :is="iconComp(name)" /></el-icon>
                    {{ name }}
                  </span>
                </el-option>
              </el-select>
            </el-form-item>
            <el-form-item label="显示" prop="visible">
              <el-radio-group v-model="form.visible">
                <el-radio :value="1">显示</el-radio>
                <el-radio :value="0">隐藏</el-radio>
              </el-radio-group>
              <span class="tip inline">详情页这类不进侧边栏的路由选隐藏</span>
            </el-form-item>
            <el-form-item v-if="formType === 2" label="页面缓存" prop="keep_alive">
              <el-radio-group v-model="form.keep_alive">
                <el-radio :value="1">缓存</el-radio>
                <el-radio :value="0">不缓存</el-radio>
              </el-radio-group>
              <span class="tip inline">多页签切回来是否保留筛选与滚动位置</span>
            </el-form-item>
          </template>

          <!-- 接口类节点绑定具体的后端接口 -->
          <template v-if="isApi">
            <el-form-item label="接口方法" prop="api_method">
              <el-select v-model="form.api_method" style="width: 140px">
                <el-option v-for="m in ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']" :key="m" :label="m" :value="m" />
              </el-select>
            </el-form-item>
            <el-form-item label="接口路径" prop="api_path">
              <el-input v-model="form.api_path" placeholder="如 /admin/users" />
            </el-form-item>
          </template>

          <el-form-item label="排序" prop="sort">
            <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
          </el-form-item>

          <el-form-item label="状态" prop="status">
            <el-radio-group v-model="form.status">
              <el-radio :value="1">启用</el-radio>
              <el-radio :value="0">停用</el-radio>
            </el-radio-group>
            <span class="tip inline">被角色引用的权限点不能删，只能停用</span>
          </el-form-item>
        </template>
      </template>
    </FormDrawer>
  </div>
</template>

<style scoped>
.menu-tabs {
  margin-bottom: 4px;
}

.muted {
  color: var(--el-text-color-placeholder);
}

.tip {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--el-text-color-secondary);
}

.tip.inline {
  margin-top: 0;
  margin-left: 12px;
}

.icon-option {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
