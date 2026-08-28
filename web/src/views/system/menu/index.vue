<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { resolveIconOrNone } from '@/utils/icons'
import { Plus } from '@element-plus/icons-vue'
import IconPicker from '@/components/IconPicker.vue'
import {
  createMenu,
  deleteMenu,
  fetchMenuTree,
  updateMenu,
  type MenuNodeRow,
  type MenuType,
  type MenuPayload
} from '@/api/system'
import type { FormDrawerInstance, ProColumn, ProTableInstance, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'
import { BizCode } from '@/constants/bizCode'

/**
 * 菜单与权限点（RBAC 的定义层）
 *
 * 这里只定义「系统里存在哪些权限」，不做授权——把权限给谁是角色管理的事。
 * 菜单的 path 与 component 直接驱动前端动态路由，改完用户刷新页面就生效，不用发版；
 * 反过来说填错了会让整个页面打不开，所以表单按类型收紧了可填字段。
 */
const dictStore = useDictStore()

const tableRef = ref<ProTableInstance | null>(null)
const drawerRef = ref<FormDrawerInstance<MenuPayload> | null>(null)

const query = ref<Record<string, unknown>>({ keyword: '', type: '', status: '' })
const paramParsers = { type: Number, status: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 权限标识' },
  { prop: 'type', label: '类型', type: 'dict', dict: 'perm_type', numeric: true },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn<MenuNodeRow>[] = [
  // 树的名称列必须左对齐：展开箭头与层级缩进画在这一列上，居中会把缩进吃掉
  { prop: 'name', label: '名称', minWidth: 200, align: 'left' },
  // 放在名称之后：树形表格的展开箭头在第一列，图标列插到最前会把层级压没
  { prop: 'icon', label: '图标', width: 70, align: 'center', slot: 'icon' },
  { prop: 'type', label: '类型', width: 90, align: 'center', dict: 'perm_type' },
  { prop: 'perm_code', label: '权限标识', minWidth: 210, align: 'center' },
  { prop: 'path', label: '路由路径', minWidth: 160, align: 'center', slot: 'path' },
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

/**
 * 后端存的是 EP 图标名（如 Odometer），列表与详情按名解析成组件。
 * 解析不到就当没图标——手填错的名字、或 EP 升级后被移除的图标都会走到这里，
 * 让它显示成「—」，而不是抛渲染错误把整行搞白
 */
const iconComp = resolveIconOrNone

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

const errorFields = { [BizCode.PERM_CODE_EXISTS]: 'perm_code' }

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
    visible: true,
    keep_alive: true,
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
    data: { ...row, children: undefined }
  })
}

function onView(row: MenuNodeRow) {
  editingId.value = 0
  formType.value = row.type
  drawerRef.value?.open({ title: '权限点详情', data: { ...row, children: undefined }, mode: 'view' })
}

function submit(form: MenuPayload) {
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

      <!-- 只有目录和菜单挂图标，其余类型留白 -->
      <template #icon="{ row }">
        <el-icon v-if="iconComp(row.icon)" :size="16">
          <component :is="iconComp(row.icon)" />
        </el-icon>
        <span v-else class="muted">—</span>
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
          <el-descriptions-item label="图标">
            <span v-if="iconComp(form.icon)" class="icon-inline">
              <el-icon><component :is="iconComp(form.icon)" /></el-icon>
              {{ form.icon }}
            </span>
            <span v-else>—</span>
          </el-descriptions-item>
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
            <el-radio-group v-model="form.type" @change="formType = form.type ?? TYPE_MENU">
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
              <IconPicker v-model="form.icon" />
            </el-form-item>
            <el-form-item label="显示" prop="visible">
              <!--
                绑布尔而不是 0/1：接口返回的就是布尔（模型里 casts 成 boolean，
                实测 /admin/menus/tree 回的是 true/false）。原来这里在打开表单时
                把布尔转成 1/0、提交时又原样送回数字，靠 PHP 的隐式转换兜住。
                类型收紧后这处不一致立刻暴露——改成两边都用布尔，中间不做转换
              -->
              <el-radio-group v-model="form.visible">
                <el-radio :value="true">显示</el-radio>
                <el-radio :value="false">隐藏</el-radio>
              </el-radio-group>
              <span class="tip inline">详情页这类不进侧边栏的路由选隐藏</span>
            </el-form-item>
            <el-form-item v-if="formType === 2" label="页面缓存" prop="keep_alive">
              <el-radio-group v-model="form.keep_alive">
                <el-radio :value="true">缓存</el-radio>
                <el-radio :value="false">不缓存</el-radio>
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

.icon-inline {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
