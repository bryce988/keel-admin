<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter, type RouteLocationNormalizedLoaded } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { Expand, Fold, Moon, Sunny } from '@element-plus/icons-vue'
import MenuSearch from './components/MenuSearch.vue'
import SidebarMenu from './components/SidebarMenu.vue'
import TagsView from './components/TagsView.vue'
import PasswordDrawer from '@/views/profile/PasswordDrawer.vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useSignOut } from '@/composables/useSignOut'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const signOut = useSignOut()

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

/**
 * keep-alive 的缓存键**必须带路由**
 *
 * 只用 viewKey 的话所有页面共用一个键，keep-alive 会把第一个缓存的组件
 * 一直还给你——表现为点菜单页签加上了、地址栏也变了，但内容区纹丝不动。
 * 带上 path 之后每个页面各缓存一份，正是多页签工作区想要的行为；
 * viewKey 递增则让「刷新当前」能强制重新挂载。
 */
function cacheKey(current: RouteLocationNormalizedLoaded): string {
  return `${current.path}#${viewKey.value}`
}
onMounted(() => window.addEventListener('keel:refresh-page', onRefresh))
onUnmounted(() => window.removeEventListener('keel:refresh-page', onRefresh))

/** 改密与登出的细节都在各自的组件/composable 里，这里只做分发 */
const pwdDrawer = ref<InstanceType<typeof PasswordDrawer> | null>(null)

async function onUserCommand(cmd: string) {
  if (cmd === 'logout') {
    await ElMessageBox.confirm('确定要退出登录吗？', '提示', { type: 'warning' })
    await signOut()
    return
  }

  if (cmd === 'password') {
    pwdDrawer.value?.open()
    return
  }

  router.push('/profile')
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

        <MenuSearch />

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

      <PasswordDrawer ref="pwdDrawer" />

      <!-- 内容区 -->
      <main class="content">
        <router-view v-slot="{ Component, route: current }">
          <!-- 是否缓存由后端菜单的 keep_alive 字段决定，默认缓存 -->
          <keep-alive v-if="current.meta.keepAlive !== false" :max="12">
            <component :is="Component" :key="cacheKey(current)" />
          </keep-alive>
          <component v-else :is="Component" :key="cacheKey(current)" />
        </router-view>
      </main>
    </div>
  </div>
</template>

<style scoped>
/*
 * 外壳锁定在视口内，只让内容区滚
 *
 * 用 `min-height: 100vh` 的话滚动条长在 window 上，页面一往下翻，
 * 顶栏（面包屑、搜索、头像）和页签条就一起滚没了——长表格翻到底部时
 * 既看不到自己在哪个页签，也够不着任何全局操作。
 * 改成 `height: 100vh` + 内容区 `overflow-y: auto`：外壳固定，内容自己滚。
 */
.layout {
  display: grid;
  grid-template-columns: var(--keel-sidebar-width) minmax(0, 1fr);
  height: 100vh;
  overflow: hidden;
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
  /* 高度与描边都必须跟顶栏一致——它们拼的是同一条横线，
     差 4px 或者差一档灰度都会在侧栏边界处露馅 */
  height: var(--keel-topbar-height);
  padding: 0 20px;
  flex: none;
  border-bottom: 1px solid var(--el-border-color-light);
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
  /* flex 子项默认 min-height:auto，不置 0 的话 .content 撑不小，
     滚动条又会跑回 window 上——这一行是整套固定外壳的关键 */
  min-height: 0;
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

/*
 * `:deep()` 不是风格选择：`.icon-btn` 现在也用在 <MenuSearch> 自己渲染的
 * 触发器上，而 scoped 的作用域 id 传不到子组件的根元素
 * （实测那上面既没有子组件的 data-v，也没有本组件的）。
 * 不穿透的话搜索图标会掉出这套尺寸与 hover，变成一个没边界的裸图标。
 */
.hamburger,
.topbar :deep(.icon-btn) {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  font-size: 18px;
  color: var(--el-text-color-primary);
  cursor: pointer;
  transition: background 0.2s;
}

.hamburger:hover,
.topbar :deep(.icon-btn:hover) {
  background: var(--el-fill-color-light);
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

.user .name {
  font-size: 14px;
  color: var(--el-text-color-regular);
}

/* ---------------- 内容区：全宽铺满，也是唯一的滚动容器 ---------------- */
.content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
}

@media (max-width: 900px) {
  /*
   * 窄屏下侧边栏堆到顶部，整个外壳比一屏高——这时**必须**放开高度限制，
   * 回到整页滚动。继续锁 100vh 的话菜单会把内容挤出视口，
   * 而内容区自己那个滚动条又够不着
   */
  .layout,
  .layout.is-collapsed {
    grid-template-columns: minmax(0, 1fr);
    height: auto;
    min-height: 100vh;
    overflow: visible;
  }

  .content {
    min-height: 0;
    overflow-y: visible;
  }

  .sidebar {
    position: static;
    height: auto;
    border-right: none;
    border-bottom: 1px solid var(--el-border-color);
  }

}
</style>
