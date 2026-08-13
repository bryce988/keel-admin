<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const userStore = useUserStore()

const menus = computed(() => userStore.menus)

async function onLogout() {
  await ElMessageBox.confirm('确定要退出登录吗？', '提示', { type: 'warning' })
  await userStore.logout()
  router.replace('/login')
}
</script>

<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand"><b>Keel</b><span>龙骨</span></div>

      <el-menu :default-active="$route.path" router class="menu">
        <template v-for="group in menus" :key="group.id">
          <el-sub-menu v-if="group.children?.length" :index="String(group.id)">
            <template #title>{{ group.name }}</template>
            <el-menu-item
              v-for="item in group.children"
              :key="item.id"
              :index="item.path"
            >
              {{ item.name }}
            </el-menu-item>
          </el-sub-menu>
          <el-menu-item v-else :index="group.path">{{ group.name }}</el-menu-item>
        </template>
      </el-menu>
    </aside>

    <div class="main">
      <header class="topbar">
        <span class="crumb">{{ $route.meta.title }}</span>
        <div class="spacer" />
        <el-dropdown @command="onLogout">
          <span class="user">
            <el-avatar :size="28">{{ userStore.nickname.charAt(0) }}</el-avatar>
            <span class="name">{{ userStore.nickname }}</span>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </header>

      <main class="content">
        <router-view />
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  grid-template-columns: 210px 1fr;
  min-height: 100vh;
}
.sidebar {
  background: #fff;
  border-right: 1px solid var(--el-border-color);
}
.brand {
  display: flex;
  align-items: baseline;
  gap: 8px;
  height: 60px;
  padding: 0 20px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  line-height: 60px;
}
.brand b {
  font-size: 18px;
  color: var(--el-text-color-primary);
}
.brand span {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.menu {
  border-right: none;
}
.main {
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--el-bg-color-page);
}
.topbar {
  display: flex;
  align-items: center;
  height: 56px;
  padding: 0 20px;
  background: #fff;
  border-bottom: 1px solid var(--el-border-color-light);
}
.crumb {
  font-size: 14px;
  color: var(--el-text-color-primary);
}
.spacer {
  flex: 1;
}
.user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  outline: none;
}
.name {
  font-size: 14px;
  color: var(--el-text-color-regular);
}
.content {
  flex: 1;
  padding: 16px;
}
</style>
