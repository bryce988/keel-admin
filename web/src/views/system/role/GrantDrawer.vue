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

    // 只回填叶子节点：父节点由 el-tree 自己按子节点状态推导，
    // 直接把父节点也塞进 setCheckedKeys 会让它变成全选，把没授的子节点一起勾上
    await nextTick()
    permTreeRef.value?.setCheckedKeys(leafOnly(menus, detail.permission_ids), false)
    deptTreeRef.value?.setCheckedKeys(detail.dept_ids, false)
  } finally {
    loading.value = false
  }
}

/** 从已授权 id 里挑出叶子节点（没有子节点，或子节点一个都没被授权） */
function leafOnly(nodes: MenuNodeRow[], granted: number[]): number[] {
  const result: number[] = []

  const walk = (list: MenuNodeRow[]) => {
    for (const n of list) {
      if (!granted.includes(n.id)) continue

      const kids = n.children ?? []
      const anyGrantedKid = kids.some((k) => granted.includes(k.id))
      if (!anyGrantedKid) result.push(n.id)
      walk(kids)
    }
  }

  walk(nodes)
  return result
}

async function savePermissions() {
  if (!role.value) return
  saving.value = true
  try {
    // 半选的父节点也要提交，否则菜单树在服务端会断链（服务端也会兜底补齐）
    const ids = [
      ...(permTreeRef.value?.getCheckedKeys(false) as number[]),
      ...(permTreeRef.value?.getHalfCheckedKeys() as number[])
    ]
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
            勾选这个角色能访问的菜单与按钮。灰色项是从父角色继承来的，取消不掉。
          </el-alert>
          <el-tree
            ref="permTreeRef"
            :data="permTree"
            :props="{ label: 'name', children: 'children', disabled: (d: any) => inheritedIds.includes(d.id) }"
            node-key="id"
            show-checkbox
            default-expand-all
            :check-strictly="false"
            class="tree"
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
