<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { ElMessage, type ElTree } from 'element-plus'
import {
  fetchDeptTree,
  fetchMenuTree,
  fetchRole,
  fetchRoleOptions,
  grantRoleDataScope,
  grantRolePermissions,
  saveRoleMutexes,
  type DeptNode,
  type MenuNodeRow,
  type RoleRow
} from '@/api/system'
import { useDictStore } from '@/stores/dict'

/**
 * 角色授权抽屉
 *
 * 授权分三件事，放在一个抽屉的三个 tab 里：
 *   功能权限（能点什么）· 数据范围（能看到谁的数据）· 职责分离（不能和谁同时持有）
 *
 * 没有复用 FormDrawer：这里不是一张表单，三个 tab 各自保存、各自的接口，
 * 硬套一个「提交」按钮反而要在里面分支判断当前是哪个 tab，得不偿失。
 */
const emit = defineEmits<{ saved: [] }>()
const dictStore = useDictStore()

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)
const tab = ref<'perm' | 'data' | 'mutex'>('perm')
const role = ref<RoleRow | null>(null)

const permTree = ref<MenuNodeRow[]>([])
const permTreeRef = ref<InstanceType<typeof ElTree>>()
const inheritedIds = ref<number[]>([])

const deptTree = ref<DeptNode[]>([])
const deptTreeRef = ref<InstanceType<typeof ElTree>>()
const dataScope = ref(4)

const roleOptions = ref<Array<{ id: number; name: string; code: string }>>([])
const mutexIds = ref<number[]>([])

/** 只有「自定义」才需要挑部门，其余四种范围是规则算出来的 */
const needDepts = computed(() => dataScope.value === 5)

async function open(target: RoleRow) {
  role.value = target
  tab.value = 'perm'
  visible.value = true
  loading.value = true

  try {
    dictStore.preload(['data_scope'])

    const [detail, menus, depts, roles] = await Promise.all([
      fetchRole(target.id),
      fetchMenuTree(),
      fetchDeptTree(),
      fetchRoleOptions()
    ])

    permTree.value = menus
    deptTree.value = depts
    roleOptions.value = roles.filter((r) => r.id !== target.id)

    inheritedIds.value = detail.inherited_ids
    dataScope.value = detail.data_scope
    mutexIds.value = detail.mutex_ids

    /*
     * 授权了哪些就回填哪些，一个不多一个不少
     *
     * 树是 check-strictly 的（见模板那段注释），父子勾选互不牵连，
     * 所以这里可以直接把服务端给的 id 全塞进去。
     * 从前不行——非 strict 模式下塞一个父节点等于「全选它的子节点」，
     * 所以旧代码得先把父节点挑掉（`leafOnly`），再靠 el-tree 反推父节点状态。
     */
    await nextTick()
    permTreeRef.value?.setCheckedKeys(detail.permission_ids, false)
    deptTreeRef.value?.setCheckedKeys(detail.dept_ids, false)
  } finally {
    loading.value = false
  }
}

/**
 * 勾选联动：勾子补父、取消父清子
 *
 * 树本身是 `check-strictly`（父子独立），联动在这里手写，因为两个方向的规则**不对称**：
 *
 * - **勾一个子节点 → 自动补上它的所有祖先**。「新增用户」这个按钮权限脱离
 *   「用户管理」这个菜单毫无意义：菜单进不去，按钮也就无从点起；而且服务端
 *   构建菜单树时会把没有子节点的目录剪掉，父节点缺了整棵子树都不下发。
 * - **取消一个父节点 → 清掉它的整棵子树**。收回了「用户管理」，底下的增删改
 *   就该一起收回，否则库里留着一堆挂在看不见的菜单下的按钮权限。
 * - **勾一个父节点 → 什么都不做**。这正是原来最大的问题：想只给「用户管理」
 *   （只让看列表），一勾把新增/编辑/删除/重置密码/导出/看手机号全带上了，
 *   得反过来一个个取消，而且很容易漏掉一个就把删除权限发出去了。
 *
 * 继承来的节点不动：它们来自父角色，在这里取消不掉（服务端也会拒），
 * 联动时跳过，免得界面上出现「取消了但一刷新又回来」。
 */
function onPermCheck(data: MenuNodeRow) {
  const tree = permTreeRef.value
  if (!tree) return

  const node = tree.getNode(data.id)
  if (!node) return

  if (node.checked) {
    // 勾上：把祖先链补齐（leaf=false 表示只设这一个节点，不牵连它的子孙）
    for (let p = node.parent; p?.data?.id != null; p = p.parent) {
      if (!inheritedIds.value.includes(p.data.id)) tree.setChecked(p.data.id, true, false)
    }

    return
  }

  // 取消：清掉整棵子树
  const clear = (target: typeof node) => {
    for (const child of target.childNodes ?? []) {
      if (!inheritedIds.value.includes(child.data.id)) tree.setChecked(child.data.id, false, false)
      clear(child)
    }
  }
  clear(node)
}

async function savePermissions() {
  if (!role.value) return
  saving.value = true
  try {
    /*
     * strict 模式下没有「半选」这回事，勾了什么就是什么
     *
     * 从前要把 getHalfCheckedKeys() 也拼进来：非 strict 模式下，只授了子节点时
     * 父节点是半选态，不提交它菜单树在服务端就断链了。
     * 现在父节点由 `onPermCheck` 在勾子节点时显式补上，是实打实的选中态。
     */
    const ids = permTreeRef.value?.getCheckedKeys(false) as number[]
    await grantRolePermissions(role.value.id, ids)
    ElMessage.success('功能权限已保存')
    emit('saved')
  } finally {
    saving.value = false
  }
}

async function saveDataScope() {
  if (!role.value) return
  saving.value = true
  try {
    const ids = needDepts.value
      ? [
          ...(deptTreeRef.value?.getCheckedKeys(false) as number[]),
          ...(deptTreeRef.value?.getHalfCheckedKeys() as number[])
        ]
      : []
    await grantRoleDataScope(role.value.id, dataScope.value, ids)
    ElMessage.success('数据范围已保存')
    emit('saved')
  } finally {
    saving.value = false
  }
}

async function saveMutex() {
  if (!role.value) return
  saving.value = true
  try {
    await saveRoleMutexes(role.value.id, mutexIds.value)
    ElMessage.success('互斥关系已保存')
  } finally {
    saving.value = false
  }
}

function onSave() {
  if (tab.value === 'perm') return savePermissions()
  if (tab.value === 'data') return saveDataScope()
  return saveMutex()
}


defineExpose({ open })
</script>

<template>
  <el-drawer
    v-model="visible"
    :title="role ? `授权 · ${role.name}` : '授权'"
    size="620px"
    direction="rtl"
    :close-on-click-modal="false"
  >
    <div v-loading="loading" class="grant">
      <el-tabs v-model="tab">
        <el-tab-pane label="功能权限" name="perm">
          <el-alert type="info" :closable="false" show-icon class="hint">
            勾选这个角色能访问的菜单与按钮。勾子项会自动补上它所在的菜单；
            取消菜单会一并收回它下面的按钮。灰色项是从父角色继承来的，取消不掉。
          </el-alert>
          <!--
            check-strictly：父子勾选**互不牵连**，联动规则在 `onPermCheck` 里手写

            交给 el-tree 自己联动（check-strictly=false）的话，勾一个父节点等于
            勾上它的全部子节点——想只给「用户管理」的列表权限，会连新增、删除、
            重置密码、查看手机号一起授出去，然后得反过来一个个取消。
            权限界面上「多给了」和「少给了」的代价完全不对等，这个默认行为在这里是错的。
          -->
          <el-tree
            ref="permTreeRef"
            :data="permTree"
            :props="{ label: 'name', children: 'children', disabled: (d: any) => inheritedIds.includes(d.id) }"
            node-key="id"
            show-checkbox
            default-expand-all
            check-strictly
            class="tree"
            @check="onPermCheck"
          >
            <template #default="{ data }">
              <span class="node">
                {{ data.name }}
                <el-tag size="small" type="info" effect="plain">{{ data.perm_code }}</el-tag>
                <el-tag v-if="inheritedIds.includes(data.id)" size="small" type="warning" effect="plain">
                  继承
                </el-tag>
              </span>
            </template>
          </el-tree>
        </el-tab-pane>

        <el-tab-pane label="数据范围" name="data">
          <el-alert type="info" :closable="false" show-icon class="hint">
            决定持有该角色的人能看到「谁的数据」。一个人有多个角色时，取范围最大的那个。
          </el-alert>
          <el-radio-group v-model="dataScope" class="scope">
            <el-radio :value="1">全部数据</el-radio>
            <el-radio :value="2">本部门及下属</el-radio>
            <el-radio :value="3">本部门</el-radio>
            <el-radio :value="4">仅本人</el-radio>
            <el-radio :value="5">自定义</el-radio>
          </el-radio-group>

          <template v-if="needDepts">
            <div class="sub-title">选择可见的部门</div>
            <el-tree
              ref="deptTreeRef"
              :data="deptTree"
              :props="{ label: 'name', children: 'children' }"
              node-key="id"
              show-checkbox
              default-expand-all
              class="tree"
            />
          </template>
        </el-tab-pane>

        <el-tab-pane label="职责分离" name="mutex">
          <el-alert type="warning" :closable="false" show-icon class="hint">
            互斥的两个角色不能同时授予同一个人。典型场景：审计员不能同时是数据管理员，
            否则「操作」与「审计操作」落在同一个人身上，留痕就失去意义。
          </el-alert>
          <el-select v-model="mutexIds" multiple filterable placeholder="选择互斥角色" style="width: 100%">
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-tab-pane>
      </el-tabs>
    </div>

    <template #footer>
      <div class="footer">
        <el-button @click="visible = false">关 闭</el-button>
        <el-button type="primary" :loading="saving" @click="onSave">保存当前页</el-button>
      </div>
    </template>
  </el-drawer>
</template>

<style scoped>
.grant {
  min-height: 200px;
}

.hint {
  margin-bottom: 12px;
}

.tree {
  max-height: calc(100vh - 320px);
  overflow: auto;
}

.node {
  display: flex;
  align-items: center;
  gap: 8px;
}

.scope {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}

.sub-title {
  margin: 12px 0 8px;
  font-size: 13px;
  font-weight: 500;
  color: var(--el-text-color-primary);
}

.footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
