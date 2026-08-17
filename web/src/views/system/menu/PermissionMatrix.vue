<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Check, Close } from '@element-plus/icons-vue'
import { fetchPermissionMatrix, type MenuNodeRow, type PermissionMatrix } from '@/api/system'

/**
 * 角色 × 权限矩阵（**只读**审计视图）
 *
 * 「谁有哪些权限」平时要在角色页里一个个点开看，真出事时根本来不及。
 * 这里一次把全貌摊开做交叉检查——比如某个只读角色是不是被误授了删除权限。
 *
 * 只读是有意的：授权入口只有角色管理一处（RBAC 三层里的「授权」层）。
 * 在这里也能改的话，同一件事就有了两个入口，迟早出现两边行为不一致。
 */
const loading = ref(false)
const data = ref<PermissionMatrix | null>(null)
const onlyGranted = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await fetchPermissionMatrix()
  } finally {
    loading.value = false
  }
}

/** 树压平成表格行，用缩进表达层级——矩阵是横向对比，树形展开反而碍事 */
function flatten(nodes: MenuNodeRow[], depth = 0): Array<MenuNodeRow & { depth: number }> {
  return nodes.flatMap((n) => [
    { ...n, depth },
    ...(n.children?.length ? flatten(n.children, depth + 1) : [])
  ])
}

const rows = computed(() => {
  if (!data.value) return []

  const all = flatten(data.value.tree)

  return onlyGranted.value
    ? all.filter((n) => (data.value!.granted[String(n.id)] ?? []).length > 0)
    : all
})

function has(permissionId: number, roleId: number): boolean {
  return (data.value?.granted[String(permissionId)] ?? []).includes(roleId)
}

/** 每个角色实际持有的权限点数，放在表头下面，一眼看出谁的权限最大 */
function countOf(roleId: number): number {
  if (!data.value) return 0

  return Object.values(data.value.granted).filter((ids) => ids.includes(roleId)).length
}

onMounted(load)
</script>

<template>
  <div class="matrix" v-loading="loading">
    <div class="bar">
      <el-alert type="info" :closable="false" show-icon>
        只读审计视图。授权入口只有「角色管理」一处，避免同一件事有两个入口。
      </el-alert>
      <el-checkbox v-model="onlyGranted">只看已授权的权限点</el-checkbox>
    </div>

    <el-table :data="rows" size="small" border stripe height="calc(100vh - 340px)">
      <el-table-column prop="name" label="权限点" min-width="240" fixed="left">
        <template #default="{ row }">
          <span :style="{ paddingLeft: row.depth * 16 + 'px' }">{{ row.name }}</span>
          <el-tag size="small" type="info" effect="plain" class="code">{{ row.perm_code }}</el-tag>
        </template>
      </el-table-column>

      <el-table-column
        v-for="role in data?.roles ?? []"
        :key="role.id"
        align="center"
        width="130"
      >
        <template #header>
          <div class="role-head">
            <span>{{ role.name }}</span>
            <em>{{ countOf(role.id) }} 项</em>
          </div>
        </template>
        <template #default="{ row }">
          <el-icon v-if="has(row.id, role.id)" class="yes"><Check /></el-icon>
          <el-icon v-else class="no"><Close /></el-icon>
        </template>
      </el-table-column>

      <template #empty>
        <el-empty description="暂无数据" :image-size="90" />
      </template>
    </el-table>
  </div>
</template>

<style scoped>
.matrix {
  padding: 16px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: var(--el-border-radius-base);
}

.bar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 12px;
}

.bar :deep(.el-alert) {
  flex: 1;
}

.code {
  margin-left: 8px;
  font-family: var(--el-font-family-mono, monospace);
}

.role-head {
  display: flex;
  flex-direction: column;
  line-height: 1.4;
}

.role-head em {
  font-style: normal;
  font-size: 12px;
  font-weight: 400;
  color: var(--el-text-color-secondary);
}

.yes {
  color: var(--el-color-success);
}

.no {
  color: var(--el-text-color-placeholder);
}
</style>
