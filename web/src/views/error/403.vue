<script setup lang="ts">
import { useRouter } from 'vue-router'
import { Lock } from '@element-plus/icons-vue'

const router = useRouter()
</script>

<template>
  <div class="error-page">
    <el-icon class="icon"><Lock /></el-icon>
    <h1>403</h1>
    <p>你没有访问该页面的权限</p>
    <span class="hint">如果确认需要，请联系管理员为你的角色授予对应权限点</span>
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
  color: var(--el-color-warning);
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
