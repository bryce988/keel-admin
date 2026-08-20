<script setup lang="ts">
/**
 * 首屏骨架屏（PROJECT.md §9.6：首屏骨架屏，翻页局部遮罩）
 *
 * 为什么不用 v-loading 转圈：整页遮罩会让首屏「白 → 转圈 → 内容」跳两次，
 * 而骨架屏的形状与真实内容一致，数据到了直接替换，视觉上只跳一次。
 *
 * **只用于首屏**。翻页、筛选这类二次加载仍然用 ProTable 自带的局部遮罩——
 * 那时候版面已经在了，再换回骨架屏等于把已渲染的内容抹掉重来。
 *
 *   <PageSkeleton v-if="loading" type="detail" />
 *   <div v-else>…</div>
 */
withDefaults(
  defineProps<{
    /** list 搜索栏+表格 · detail 左右栏 · form 分卡片表单 */
    type?: 'list' | 'detail' | 'form'
    /** list 类型的骨架行数，给得比首屏可见行数略少即可 */
    rows?: number
  }>(),
  { type: 'list', rows: 6 }
)
</script>

<template>
  <div class="page-skeleton">
    <!-- 列表页：搜索栏 → 工具栏 → 表格 -->
    <template v-if="type === 'list'">
      <div class="panel block search">
        <el-skeleton animated>
          <template #template>
            <div class="row">
              <el-skeleton-item variant="text" style="width: 200px" />
              <el-skeleton-item variant="text" style="width: 200px" />
              <el-skeleton-item variant="button" style="width: 76px" />
            </div>
          </template>
        </el-skeleton>
      </div>

      <div class="panel block">
        <el-skeleton animated>
          <template #template>
            <div class="row toolbar">
              <el-skeleton-item variant="button" style="width: 88px" />
            </div>
            <div v-for="i in rows" :key="i" class="row line">
              <el-skeleton-item variant="text" style="width: 24%" />
              <el-skeleton-item variant="text" style="width: 20%" />
              <el-skeleton-item variant="text" style="width: 16%" />
              <el-skeleton-item variant="text" style="width: 12%" />
              <el-skeleton-item variant="text" style="width: 18%" />
            </div>
          </template>
        </el-skeleton>
      </div>
    </template>

    <!-- 详情页：左栏静态属性 + 右栏动态区块 -->
    <div v-else-if="type === 'detail'" class="cols">
      <div class="panel block">
        <el-skeleton animated :rows="6" />
      </div>
      <div class="panel block">
        <el-skeleton animated :rows="8" />
      </div>
    </div>

    <!-- 表单页：按卡片分块 -->
    <template v-else>
      <div v-for="i in 3" :key="i" class="panel block">
        <el-skeleton animated :rows="4" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.page-skeleton {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* 面板外观走全局 .panel（styles/index.css）。
   骨架屏的方块必须和真实面板长得一模一样，否则加载完会「跳一下」 */

.row {
  display: flex;
  align-items: center;
  gap: 16px;
}

.toolbar {
  margin-bottom: 16px;
}

.line + .line {
  margin-top: 14px;
}

/* 与详情页页型同宽，避免数据到达时版面横向跳动 */
.cols {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

@media (max-width: 1100px) {
  .cols {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
