<script setup lang="ts">
import { useRouter } from 'vue-router'
import { Compass } from '@element-plus/icons-vue'

const router = useRouter()
</script>

<template>
  <div class="error-page">
    <el-icon class="icon"><Compass /></el-icon>
    <h1>404</h1>
    <p>页面不存在，或它不在你的菜单里</p>
    <span class="hint">菜单由后端按角色下发，未授予的页面在前端不会注册路由</span>
    <div class="actions">
      <el-button @click="router.back()">返回上一页</el-button>
      <el-button type="primary" @click="router.replace('/')">回到首页</el-button>
    </div>
  </div>
</template>

<style scoped>
.error-page {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 60vh;
  text-align: center;
}

.icon {
  font-size: 48px;
  color: var(--el-text-color-placeholder);
}

h1 {
  margin: 8px 0 0;
  font-size: 40px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

p {
  margin: 0;
  font-size: 16px;
  color: var(--el-text-color-regular);
}

.hint {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.actions {
  margin-top: 16px;
  display: flex;
  gap: 12px;
}
</style>
