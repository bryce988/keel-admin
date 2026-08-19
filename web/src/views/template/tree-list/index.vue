<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import type { ProColumn, ProTableInstance, SearchField } from '@/components'
// ⛔ 复制后把这一行换成你自己的 api/xxx.ts
import { deleteDemo, demoTree, fetchDemoList, type DemoRow } from '../_demo'

/**
 * 【模板 ②】树表联动页 —— 左树筛右表
 *
 * 实体挂在某个层级归属下时用它：用户挂部门、资产挂分类、文件挂目录。
 * 系统管理里的「用户管理」就是这个形状（PROJECT.md §9.2）。
 *
 * 复制清单：
 *   1. 换掉 `../_demo`，树接口一次返回全量（树分页是断的，父节点翻页就成孤儿）
 *   2. 改 treeProps 里的字段名（后端字段是 snake_case，别在这里做键名转换）
 *   3. 若后端按「连同下级」筛选，前端只传选中的那个 id 就够，不要在前端展开子树 id
 */
const tableRef = ref<ProTableInstance | null>(null)

const treeData = ref(demoTree)
const treeLoading = ref(false)

/**
 * category_id 是筛选条件的一部分
 *
 * 放进 query 而不是单独一个 ref：这样它跟着 ProTable 一起同步进 URL，
 * 刷新后**树的选中高亮和表格数据一起回来**。单独放的话只有表格恢复，
 * 树的高亮丢了，用户会以为筛选没生效。
 */
const query = ref<Record<string, unknown>>({ keyword: '', status: '', category_id: '' })

/** category_id 必须登记成数字，否则刷新后树对不上高亮（URL 里是字符串） */
const paramParsers = { status: Number, category_id: Number }

const searchFields: SearchField[] = [
  { prop: 'keyword', label: '关键词', placeholder: '名称 / 编码' },
  { prop: 'status', label: '状态', type: 'dict', dict: 'enable_status', numeric: true }
]

const columns: ProColumn[] = [
  { prop: 'name', label: '名称', minWidth: 160 },
  { prop: 'code', label: '编码', minWidth: 140 },
  { prop: 'owner', label: '负责人', width: 100 },
  { prop: 'status', label: '状态', width: 90, align: 'center', dict: 'enable_status' },
  { prop: 'created_at', label: '创建时间', width: 165, sortable: true },
  { prop: 'actions', label: '操作', width: 120, align: 'center', fixed: 'right', slot: 'actions' }
]

/**
 * 切换节点：重置到第 1 页，但**保留搜索关键词**
 *
 * 用户点另一个节点的意图是「换个范围再搜一次」，把关键词一起清掉
 * 等于替他撤销了刚输入的东西。reload() 本身就会回到第 1 页。
 */
function onNodeClick(node: { id: number }) {
  query.value.category_id = node.id === 1 ? '' : node.id
  tableRef.value?.reload()
}

async function onDelete(row: DemoRow) {
  await ElMessageBox.confirm(`确定删除「${row.name}」吗？`, '删除确认', {
    type: 'warning',
    confirmButtonText: '删除',
    confirmButtonClass: 'el-button--danger'
  })

  await deleteDemo(row.id)
  ElMessage.success('已删除')
  tableRef.value?.refresh()
}
</script>

<template>
  <div class="page tree-list-page">
    <aside v-loading="treeLoading" class="tree-panel">
      <div class="panel-title">分类</div>
      <el-tree
        :data="treeData"
        :props="{ label: 'name', children: 'children' }"
        node-key="id"
        default-expand-all
        :expand-on-click-node="false"
        :current-node-key="(query.category_id as number) || undefined"
        highlight-current
        @node-click="onNodeClick"
      />
      <!--
        统一走 <EmptyState>（§9.6）。这里 :action="false" 是刻意的：
        「一个分类都看不见」通常是数据权限的结果，给个按钮用户也点不出东西来。
        空状态该不该有动作，取决于用户能不能自己解决，不是格式要求
      -->
      <EmptyState
        v-if="!treeLoading && treeData.length === 0"
        description="无可见分类"
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
        :request="fetchDemoList"
        :param-parsers="paramParsers"
        :columns="columns"
        index
      >
        <template #toolbar>
          <el-button type="primary" :icon="Plus">新增</el-button>
        </template>

        <template #actions="{ row }">
          <div class="table-actions">
            <el-button link type="primary">编辑</el-button>
            <el-button link type="danger" @click="onDelete(row)">删除</el-button>
          </div>
        </template>
      </ProTable>
    </section>
  </div>
</template>

<style scoped>
.tree-list-page {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 12px;
  align-items: start;
}

.tree-panel {
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

/* minmax(0, 1fr) 而不是 1fr：grid 子项默认 min-width:auto，
   表格横向滚动条会把整个页面撑宽 */
.list-panel {
  min-width: 0;
}

@media (max-width: 900px) {
  .tree-list-page {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
