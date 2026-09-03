<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import {
  addRoleMembers,
  fetchRoleMembers,
  fetchUsers,
  removeRoleMember,
  type RoleRow,
  type UserRow
} from '@/api/system'
import type { TableQuery } from '@/types/api'

/**
 * 角色成员
 *
 * 「谁是这个角色」本质上属于用户管理的分配层，这里是同一件事的反向入口——
 * 按角色批量加人是真实需求（新部门成立时一次加十个人）。
 * 但校验走的是服务端同一个 `assertAssignable`：互斥与角色数上限两处必须一致，
 * 否则会出现「从角色页能加、从用户页加同一个人却被拒」这种自相矛盾。
 */
const emit = defineEmits<{ saved: [] }>()

const visible = ref(false)
const role = ref<RoleRow | null>(null)
const members = ref<UserRow[]>([])
const total = ref(0)
const loading = ref(false)

/** 添加成员的候选列表 */
const picking = ref(false)
const candidates = ref<UserRow[]>([])
const picked = ref<number[]>([])
const keyword = ref('')

function open(target: RoleRow) {
  role.value = target
  visible.value = true
  picking.value = false
  load()
}

async function load() {
  if (!role.value) return
  loading.value = true
  try {
    const result = await fetchRoleMembers(role.value.id, {
      page_num: 1,
      page_size: 100
    } as TableQuery)
    members.value = result.list
    total.value = result.total
  } finally {
    loading.value = false
  }
}

async function openPicker() {
  picking.value = true
  picked.value = []
  await searchCandidates()
}

async function searchCandidates() {
  const result = await fetchUsers({
    page_num: 1,
    page_size: 50,
    keyword: keyword.value
  } as TableQuery)

  // 已经是成员的不再出现在候选里，避免用户重复勾选后才发现没变化
  const memberIds = members.value.map((m) => m.id)
  candidates.value = result.list.filter((u) => !memberIds.includes(u.id))
}

async function confirmAdd() {
  if (!role.value || !picked.value.length) return

  await addRoleMembers(role.value.id, picked.value)
  ElMessage.success(`已添加 ${picked.value.length} 名成员`)
  picking.value = false
  await load()
  emit('saved')
}

async function onRemove(user: UserRow) {
  if (!role.value) return

  await ElMessageBox.confirm(
    `确定把「${user.real_name || user.username}」移出该角色吗？该用户的权限会立即变化。`,
    '移除成员',
    { type: 'warning', confirmButtonText: '移除', confirmButtonClass: 'el-button--danger' }
  )

  await removeRoleMember(role.value.id, user.id)
  ElMessage.success('已移除')
  await load()
  emit('saved')
}

/**
 * `<script setup>` 默认不对外暴露任何东西，少了这一句父组件拿到的 ref 上
 * 就没有 open，点「成员」直接 TypeError。
 * 类型检查发现不了：父组件那边把 ref 标成了 `{ open: ... } | null`，
 * 等于跟 TS 打了包票，而 TS 无从核对子组件到底暴露了什么。
 */
defineExpose({ open })
</script>

<template>
  <el-drawer
    v-model="visible"
    :title="role ? `成员 · ${role.name}` : '成员'"
    size="620px"
    direction="rtl"
  >
    <div v-loading="loading">
      <div class="bar">
        <span class="count">共 {{ total }} 名成员</span>
        <el-button
          v-permission="'sys:user:grantRole'"
          type="primary"
          size="small"
          :icon="Plus"
          @click="openPicker"
        >
          添加成员
        </el-button>
      </div>

      <el-table :data="members" size="small" border>
        <el-table-column prop="username" label="账号" min-width="120" />
        <el-table-column prop="real_name" label="姓名" min-width="100" />
        <el-table-column prop="dept_name" label="部门" min-width="110" />
        <el-table-column label="操作" width="90" align="center">
          <!-- 裸 el-table 的插槽把行给成 DefaultRow，断言回 UserRow（ProTable 内部做了同样的事） -->
          <template #default="scope">
            <el-button
              v-permission="'sys:user:grantRole'"
              link
              type="danger"
              :disabled="(scope.row as UserRow).is_super"
              @click="onRemove(scope.row as UserRow)"
            >
              移除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <EmptyState
            description="该角色还没有成员"
            action-text="添加成员"
            :size="80"
            @action="openPicker"
          />
        </template>
      </el-table>
    </div>

    <!-- 添加成员 -->
    <el-dialog v-model="picking" title="添加成员" width="520px" append-to-body>
      <el-input
        v-model="keyword"
        placeholder="搜索账号 / 姓名 / 手机号"
        clearable
        class="search"
        @keyup.enter="searchCandidates"
        @clear="searchCandidates"
      />

      <el-table :data="candidates" height="320" size="small" @selection-change="picked = $event.map((u: UserRow) => u.id)">
        <el-table-column type="selection" width="46" />
        <el-table-column prop="username" label="账号" min-width="110" />
        <el-table-column prop="real_name" label="姓名" min-width="90" />
        <el-table-column prop="dept_name" label="部门" min-width="100" />
        <template #empty>
          <EmptyState description="没有可添加的用户" :size="70" :action="false" />
        </template>
      </el-table>

      <template #footer>
        <el-button @click="picking = false">取 消</el-button>
        <el-button type="primary" :disabled="!picked.length" @click="confirmAdd">
          添加{{ picked.length ? `（${picked.length}）` : '' }}
        </el-button>
      </template>
    </el-dialog>
  </el-drawer>
</template>

<style scoped>
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.count {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.search {
  margin-bottom: 12px;
}
</style>
