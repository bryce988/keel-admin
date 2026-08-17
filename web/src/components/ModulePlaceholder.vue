<script setup lang="ts">
import { Tools } from '@element-plus/icons-vue'

/**
 * 未实现模块的占位页
 *
 * 菜单树在 M1 就按完整信息架构下发了，但七个模块的页面属于 M2。
 * 与其让这些菜单点进去白屏或 404，不如明确告诉使用者「这里将会是什么」——
 * 骨架阶段的可见空缺，比看起来能用实际不能用要好。
 */
defineProps<{
  title: string
  description: string
  /** 该模块对应的权限点前缀，便于对照 config/route.php */
  permPrefix?: string
}>()
</script>

<template>
  <div class="page placeholder">
    <el-icon class="icon"><Tools /></el-icon>
    <h2>{{ title }}</h2>
    <p>{{ description }}</p>
    <el-tag v-if="permPrefix" type="info" effect="plain" size="small">权限点：{{ permPrefix }}</el-tag>
    <span class="stage">该模块在 M2「系统管理」阶段实现，接口契约见 docs/api.md</span>
  </div>
</template>

<style scoped>
.placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 55vh;
  text-align: center;
  background: var(--el-bg-color);
  border: 1px dashed var(--el-border-color);
  border-radius: var(--el-border-radius-base);
}

.icon {
  font-size: 40px;
  color: var(--el-color-primary);
}

h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

p {
  margin: 0;
  max-width: 520px;
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-regular);
}

.stage {
  margin-top: 4px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
