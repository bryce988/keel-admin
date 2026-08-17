<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { fetchDeptTree, fetchUsers, type DeptNode } from '@/api/system'
import type { ProColumn, SearchField } from '@/components'
import { useDictStore } from '@/stores/dict'

/**
 * 用户管理
 *
 * M1 阶段只有查询——它是骨架的样例页，用来验证
 * SearchForm / ProTable / DictTag / v-permission / 数据权限 是否串起来了。
 * 增删改在 M2 补齐，所以这里的写操作按钮先给出明确反馈而不是静默无响应。
 */
const dictStore = useDictStore()

const tableRef = ref<{ reload: () => void; refresh: () => void } | null>(null)

/**
 * 用 ref 而不是 reactive：SearchForm 与 ProTable 都用 v-model 绑它，
 * v-model 会整体赋值，reactive 声明的 const 赋不了值（点「重置」直接报错）。
 */
const query = ref<Record<string, unknown>>({
  keyword: '',
  status: '',
  dept_id: ''
})

/** URL 里取回来的是字符串，这两个字段要转成数字，否则下拉框与树选中态对不上 */
const paramParsers = {
  status: Number,
  dept_id: Number
}

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '账号 / 姓名 / 手机号' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'user_status', numeric: true }
]

// prop 是接口返回的字段名，因此是 snake_case
const columns: ProColumn[] = [
  { prop: 'username', label: '账号', minWidth: 120, sortable: true, fixed: 'left' },
  { prop: 'real_name', label: '姓名', minWidth: 100 },
  { prop: 'dept_name', label: '部门', minWidth: 110 },
  { prop: 'post_name', label: '岗位', minWidth: 120 },
  { prop: 'phone', label: '手机号', minWidth: 130 },
  { prop: 'email', label: '邮箱', minWidth: 180, hidden: true },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'user_status' },
  { prop: 'last_login_at', label: '最后登录', minWidth: 160, sortable: true },
  { prop: 'actions', label: '操作', width: 160, align: 'center', fixed: 'right', slot: 'actions' }
]

// ---------------------------------------------------------------- 部门树
const deptTree = ref<DeptNode[]>([])
const deptLoading = ref(false)

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
  query.value = {
    ...query.value,
    dept_id: query.value.dept_id === node.id ? '' : node.id
  }
  tableRef.value?.reload()
}

onMounted(() => {
  // 状态列与筛选项共用 user_status，预热一次避免两个组件各请求一遍
  dictStore.preload(['user_status'])
  loadDeptTree()
})

function notImplemented(action: string) {
  ElMessage.info(`「${action}」将在 M2 的用户模块中实现`)
}
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
      <el-empty v-if="!deptLoading && deptTree.length === 0" description="无可见部门" :image-size="60" />
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
          <el-button
            v-permission="'sys:user:create'"
            type="primary"
            :icon="Plus"
            @click="notImplemented('新增用户')"
          >
            新增
          </el-button>
          <el-button v-permission="'sys:user:export'" @click="notImplemented('导出用户')">导出</el-button>
        </template>

        <template #actions="{ row }">
          <el-button v-permission="'sys:user:update'" link type="primary" @click="notImplemented('编辑')">
            编辑
          </el-button>
          <el-button
            v-permission="'sys:user:resetPwd'"
            link
            type="primary"
            :disabled="row.is_super"
            @click="notImplemented('重置密码')"
          >
            重置密码
          </el-button>
        </template>
      </ProTable>
    </section>
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

@media (max-width: 900px) {
  .user-page {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
