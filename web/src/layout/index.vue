<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Expand, Fold, Moon, Search, Sunny } from '@element-plus/icons-vue'
import SidebarMenu from './components/SidebarMenu.vue'
import TagsView from './components/TagsView.vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useTagsViewStore } from '@/stores/tagsView'
import { useDictStore } from '@/stores/dict'
import { resetDynamicRoutes } from '@/router'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const tagsStore = useTagsViewStore()
const dictStore = useDictStore()

/** 面包屑：一级分组 / 当前页 */
const breadcrumb = computed(() => {
  const title = (route.meta.title as string) || ''
  for (const group of userStore.menus) {
    if (group.children?.some((c) => c.path === route.path)) {
      return [group.name, title]
    }
  }
  return ['', title]
})

/** 刷新当前页：右键菜单「刷新当前」触发，通过 key 变化重新挂载 */
const viewKey = ref(0)
function onRefresh() {
  viewKey.value++
}
onMounted(() => window.addEventListener('keel:refresh-page', onRefresh))
onUnmounted(() => window.removeEventListener('keel:refresh-page', onRefresh))

async function onUserCommand(cmd: string) {
  if (cmd === 'logout') {
    await ElMessageBox.confirm('确定要退出登录吗？', '提示', { type: 'warning' })
    await userStore.logout()
    tagsStore.reset()
    dictStore.forget()
    // 卸载上个账号的动态路由，否则换账号登录会残留他看得见的页面
    resetDynamicRoutes()
    router.replace('/login')
    return
  }
  // 个人中心与修改密码属于 M2 范围，先给出明确反馈而不是静默无响应
  ElMessage.info('该功能将在「个人中心」模块中实现')
}
</script>

<template>
  <div class="layout" :class="{ 'is-collapsed': appStore.sidebarCollapsed }">
    <!-- 侧边栏 -->
    <aside class="sidebar">
      <div class="brand">
        <b>Keel</b>
        <span v-show="!appStore.sidebarCollapsed">龙骨</span>
      </div>
      <el-scrollbar class="menu-scroll">
        <SidebarMenu />
      </el-scrollbar>
    </aside>

    <div class="main">
      <!-- 顶栏 -->
      <header class="topbar">
        <el-icon class="hamburger" @click="appStore.toggleSidebar()">
          <component :is="appStore.sidebarCollapsed ? Expand : Fold" />
        </el-icon>

        <el-breadcrumb separator="/">
          <el-breadcrumb-item v-if="breadcrumb[0]">{{ breadcrumb[0] }}</el-breadcrumb-item>
          <el-breadcrumb-item>{{ breadcrumb[1] }}</el-breadcrumb-item>
        </el-breadcrumb>

        <div class="spacer" />

        <el-input
          class="search"
          placeholder="搜索菜单、用户、日志…"
          :prefix-icon="Search"
          size="default"
          readonly
        />

        <el-tooltip :content="appStore.theme === 'dark' ? '切换到浅色' : '切换到深色'">
          <el-icon class="icon-btn" @click="appStore.toggleTheme()">
            <component :is="appStore.theme === 'dark' ? Sunny : Moon" />
          </el-icon>
        </el-tooltip>

        <el-dropdown @command="onUserCommand">
          <span class="user">
            <el-avatar :size="30">{{ userStore.nickname.charAt(0) }}</el-avatar>
            <span class="name">{{ userStore.nickname }}</span>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="profile">个人中心</el-dropdown-item>
              <el-dropdown-item command="password">修改密码</el-dropdown-item>
              <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </header>

      <!-- 多页签 -->
      <TagsView />

      <!-- 内容区 -->
      <main class="content">
        <router-view v-slot="{ Component }">
          <keep-alive>
            <component :is="Component" :key="viewKey" />
          </keep-alive>
        </router-view>
      </main>
    </div>
  </div>
</template>

<style scoped>
.layout {
  display: grid;
  grid-template-columns: var(--keel-sidebar-width) minmax(0, 1fr);
  min-height: 100vh;
  transition: grid-template-columns 0.28s ease;
}

.layout.is-collapsed {
  grid-template-columns: var(--keel-sidebar-collapsed) minmax(0, 1fr);
}

/* ---------------- 侧边栏 ---------------- */
.sidebar {
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: sticky;
  top: 0;
  background: var(--el-bg-color);
  border-right: 1px solid var(--el-border-color);
  overflow: hidden;
}

.brand {
  display: flex;
  align-items: center;
  gap: 8px;
  height: var(--keel-brand-height);
  padding: 0 20px;
  flex: none;
  border-bottom: 1px solid var(--el-border-color-lighter);
  white-space: nowrap;
}

.is-collapsed .brand {
  justify-content: center;
  padding: 0;
}

.brand b {
  font-size: 18px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.brand span {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.menu-scroll {
  flex: 1;
}

/* ---------------- 顶栏 ---------------- */
.main {
  display: flex;
  flex-direction: column;
  min-width: 0;
  background: var(--el-bg-color-page);
}

.topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  height: var(--keel-topbar-height);
  padding: 0 20px;
  flex: none;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
}

.hamburger,
.icon-btn {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  font-size: 18px;
  color: var(--el-text-color-primary);
  cursor: pointer;
  transition: background 0.2s;
}

.hamburger:hover,
.icon-btn:hover {
  background: var(--el-fill-color-light);
}

.spacer {
  flex: 1;
}

.search {
  width: 230px;
}

.user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  outline: none;
}

.user .name {
  font-size: 14px;
  color: var(--el-text-color-regular);
}

/* ---------------- 内容区：全宽铺满 ---------------- */
.content {
  flex: 1;
  padding: 16px;
}

@media (max-width: 900px) {
  .layout,
  .layout.is-collapsed {
    grid-template-columns: minmax(0, 1fr);
  }

  .sidebar {
    position: static;
    height: auto;
    border-right: none;
    border-bottom: 1px solid var(--el-border-color);
  }

  .search {
    display: none;
  }
}
</style>
