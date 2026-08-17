<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter, type RouteLocationNormalizedLoaded } from 'vue-router'
import { ElMessage, ElMessageBox, type FormRules } from 'element-plus'
import { changePassword } from '@/api/profile'
import { Expand, Fold, Moon, Search, Sunny } from '@element-plus/icons-vue'
import SidebarMenu from './components/SidebarMenu.vue'
import TagsView from './components/TagsView.vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useTagsViewStore } from '@/stores/tagsView'
import { useDictStore } from '@/stores/dict'
import { resetDynamicRoutes } from '@/router'
import type { FormDrawerInstance } from '@/components'

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

/** 退出登录：清干净再跳，否则换账号后会残留上个账号的页签、字典与动态路由 */
async function signOut() {
  await userStore.logout()
  tagsStore.reset()
  dictStore.forget()
  resetDynamicRoutes()
  router.replace('/login')
}

// ---------------------------------------------------------------- 修改密码
const pwdDrawer = ref<FormDrawerInstance | null>(null)

const pwdRules: FormRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码长度不能少于 8 位', trigger: 'blur' }
  ],
  confirm_password: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      trigger: 'blur',
      validator: (_rule, value, callback) => {
        const form = (pwdDrawer.value as unknown as { form?: Record<string, any> })?.form
        callback(value && value !== form?.new_password ? new Error('两次输入的密码不一致') : undefined)
      }
    }
  ]
}

/** 原密码错误是 400 + 20005，映射到输入框上，用户不用自己找是哪一项错了 */
const pwdErrorFields = { 20005: 'old_password' }

function submitPassword(form: Record<string, any>) {
  return changePassword({ old_password: form.old_password, new_password: form.new_password })
}

/** 服务端改密后会把当前 token 拉黑，必须马上重新登录 */
async function onPasswordChanged() {
  await signOut()
}

async function onUserCommand(cmd: string) {
  if (cmd === 'logout') {
    await ElMessageBox.confirm('确定要退出登录吗？', '提示', { type: 'warning' })
    await signOut()
    return
  }

  if (cmd === 'password') {
    pwdDrawer.value?.open({
      title: '修改密码',
      data: { old_password: '', new_password: '', confirm_password: '' }
    })
    return
  }

  // 个人中心属于 M2 范围，先给出明确反馈而不是静默无响应
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

      <!-- 修改密码 -->
      <FormDrawer
        ref="pwdDrawer"
        :submit="submitPassword"
        :rules="pwdRules"
        :error-fields="pwdErrorFields"
        size="420px"
        label-width="80px"
        success-message="密码已修改，请重新登录"
        @success="onPasswordChanged"
      >
        <template #default="{ form, errors }">
          <el-form-item label="原密码" prop="old_password" :error="errors.old_password">
            <el-input v-model="form.old_password" type="password" show-password autocomplete="off" />
          </el-form-item>
          <el-form-item label="新密码" prop="new_password" :error="errors.new_password">
            <el-input v-model="form.new_password" type="password" show-password autocomplete="off" />
          </el-form-item>
          <el-form-item label="确认密码" prop="confirm_password">
            <el-input
              v-model="form.confirm_password"
              type="password"
              show-password
              autocomplete="off"
            />
          </el-form-item>
        </template>
      </FormDrawer>

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
