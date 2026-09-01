<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter, type RouteLocationNormalizedLoaded } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { Expand, Fold, FullScreen, Moon, ScaleToOriginal, Setting, Sunny } from '@element-plus/icons-vue'
import BrandLogo from '@/components/BrandLogo.vue'
import ColumnRail from './components/ColumnRail.vue'
import MenuSearch from './components/MenuSearch.vue'
import NoticeBell from './components/NoticeBell.vue'
import SidebarMenu from './components/SidebarMenu.vue'
import SettingsDrawer from './components/SettingsDrawer.vue'
import TagsView from './components/TagsView.vue'
import TopMenu from './components/TopMenu.vue'
import PasswordDialog from '@/views/profile/PasswordDialog.vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useFullscreen, fullscreenSupported } from '@/composables/useFullscreen'
import { useMenuNav } from '@/composables/useMenuNav'
import { useSignOut } from '@/composables/useSignOut'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const signOut = useSignOut()

const { activeChildren, activeTop } = useMenuNav()

/*
 * 全屏
 *
 * 不支持的浏览器直接不渲染这个按钮（`canFullscreen` 只在启动时算一次，
 * 浏览器能力不会中途变）。留一个点了没反应的按钮比没有更糟。
 *
 * 进出全屏会改变视口高度，表格的定高要跟着重算——ProTable 自己显式听了
 * fullscreenchange（没有只靠 resize），所以这里不用做任何事。
 */
const canFullscreen = fullscreenSupported()
const { isFullscreen, toggle: toggleFullscreen } = useFullscreen()

const isMix = computed(() => appStore.layout === 'mix')
const isColumns = computed(() => appStore.layout === 'columns')

/**
 * 一级菜单不在侧栏里的两种版式（混合放顶栏、分栏放左窄条）
 *
 * 侧栏对它们是同一种东西——只画当前模块的子菜单，所以下面凡是「侧栏画什么」
 * 的判断都用这个，而不是逐个列举版式：漏掉一个的表现是侧栏把整棵树又画一遍，
 * 一级项在两个地方同时出现。
 */
const isSubOnly = computed(() => isMix.value || isColumns.value)

/**
 * 侧栏该不该出现
 *
 * 两种情况收掉：
 * 1. 当前模块没有子菜单（「概览」这种一级项本身就是页面，个人中心、403 这些
 *    不在菜单树里的页面同理，`activeTop` 为 null）——留着就是一条空白竖条；
 * 2. 分栏版式下用户主动折叠。分栏里「折叠」的语义是**收起第二栏**而不是把它
 *    压成图标条：左边已经有一条图标窄条了，再来一条只有图标的列，
 *    两条谁是谁分不出来。
 */
const showSidebar = computed(() => {
  if (!isSubOnly.value) return true
  if (activeChildren.value.length === 0) return false
  return !(isColumns.value && appStore.sidebarCollapsed)
})

/**
 * 折叠按钮该不该出现
 *
 * 不能直接复用 `showSidebar`：分栏折叠后侧栏消失，按钮跟着消失就再也展不开了。
 * 判断依据是「有没有可折叠的东西」，与它当前是不是折叠着无关。
 */
const showHamburger = computed(() =>
  isSubOnly.value ? activeChildren.value.length > 0 : true
)

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

const settingsDrawer = ref<InstanceType<typeof SettingsDrawer> | null>(null)

/** 刷新当前页：右键菜单「刷新当前」触发，通过 key 变化重新挂载 */
const viewKey = ref(0)
function onRefresh() {
  viewKey.value++
}

/**
 * keep-alive 的缓存键必须带路由
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
const pwdDialog = ref<InstanceType<typeof PasswordDialog> | null>(null)

async function onUserCommand(cmd: string) {
  if (cmd === 'logout') {
    await ElMessageBox.confirm('确定要退出登录吗？', '提示', { type: 'warning' })
    await signOut()
    return
  }

  if (cmd === 'password') {
    pwdDialog.value?.open()
    return
  }

  router.push('/profile')
}
</script>

<template>
  <div
    class="layout"
    :class="{
      'is-collapsed': appStore.sidebarCollapsed,
      'is-mix': isMix,
      'is-columns': isColumns,
      'no-sidebar': !showSidebar
    }"
  >
<!-- 分栏版式的一级导航：竖排窄条，占住整个左边缘 -->
    <ColumnRail v-if="isColumns" class="rail" />

    <!--
      侧边栏

      三种版式共用这一块，差别只在渲染哪一层：
      经典给整棵树，混合与分栏只给当前一级模块的子菜单（一级已经在别处了）。
      品牌标记则跟着一级走——混合挪进通宽顶栏的左端，分栏挪进左窄条的顶部，
      腾出来的位置留给当前模块名，否则第二栏顶上会空一块、跟顶栏那条横线接不上。
    -->
    <aside v-if="showSidebar" class="sidebar">
      <div v-if="!isSubOnly" class="brand">
        <BrandLogo :text="!appStore.sidebarCollapsed" />
      </div>
      <div v-else-if="isColumns" class="sub-title">{{ activeTop?.name }}</div>
      <el-scrollbar class="menu-scroll">
        <SidebarMenu :nodes="isSubOnly ? activeChildren : undefined" />
      </el-scrollbar>
    </aside>

    <!--
      顶栏是 .layout 的直接子元素，不嵌在 .main 里

      因为两种版式下它占的格子不一样：经典版式顶栏只占侧栏右侧那一格，
      混合版式要通宽横跨两列（侧栏退到它下面）。嵌在 .main 里就只能永远待在
      右半边——这正是第一版做错的地方，logo 在右边、侧栏第一项却顶到了最上面。
      提出来之后交给 grid-template-areas 摆放，两种版式各一套。
    -->
    <header class="topbar">
      <BrandLogo v-if="isMix" class="topbar-brand" />

      <!-- 当前模块没有子菜单时没有可折叠的东西，按钮也就没有意义 -->
      <el-icon v-if="showHamburger" class="hamburger" @click="appStore.toggleSidebar()">
        <component :is="appStore.sidebarCollapsed ? Expand : Fold" />
      </el-icon>

      <!--
        混合版式用一级菜单占据顶栏，就不再放面包屑：
        「在哪个模块」由顶栏高亮表达，「在哪一页」由侧栏与页签表达，
        再加一条面包屑是第三次说同一件事。
      -->
      <TopMenu v-if="isMix" class="topbar-menu" />
      <el-breadcrumb v-else separator="/">
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

      <el-tooltip v-if="canFullscreen" :content="isFullscreen ? '退出全屏' : '全屏'">
        <el-icon class="icon-btn" @click="toggleFullscreen()">
          <component :is="isFullscreen ? ScaleToOriginal : FullScreen" />
        </el-icon>
      </el-tooltip>

      <!-- 消息铃铛排在界面设置左边：它是每天都会看的东西，设置不是 -->
      <NoticeBell />

      <el-tooltip content="界面设置">
        <el-icon class="icon-btn" @click="settingsDrawer?.open()">
          <Setting />
        </el-icon>
      </el-tooltip>

      <el-dropdown @command="onUserCommand">
        <span class="user">
          <el-avatar :size="30" :src="userStore.avatar || undefined">
            {{ userStore.nickname.charAt(0) }}
          </el-avatar>
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

    <div class="main">
      <!-- 多页签 -->
      <TagsView />

      <PasswordDialog ref="pwdDialog" />
      <SettingsDrawer ref="settingsDrawer" />

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
  grid-template-rows: var(--keel-topbar-height) minmax(0, 1fr);
  /*
   * 经典：侧栏通高（跨两行），顶栏只占右上那一格
   *
   *   ┌────────┬──────────┐
   *   │ 侧栏   │  顶栏    │
   *   │ (品牌) ├──────────┤
   *   │ 菜单   │  内容    │
   *   └────────┴──────────┘
   */
  grid-template-areas:
    'sidebar topbar'
    'sidebar main';
  height: 100vh;
  overflow: hidden;
  transition: grid-template-columns 0.28s ease;
}

/*
 * 混合：顶栏通宽（跨两列），侧栏退到它下面
 *
 *   ┌───────────────────┐
 *   │ 品牌  一级菜单 ⚙  │
 *   ├────────┬──────────┤
 *   │ 二级   │  内容    │
 *   └────────┴──────────┘
 */
.layout.is-mix {
  grid-template-areas:
    'topbar topbar'
    'sidebar main';
}

.sidebar {
  grid-area: sidebar;
}

.topbar {
  grid-area: topbar;
}

.main {
  grid-area: main;
}

.layout.is-collapsed {
  grid-template-columns: var(--keel-sidebar-collapsed) minmax(0, 1fr);
}

/*
 * 混合版式下当前模块没有子菜单（如「概览」），侧栏整列收掉
 *
 * 只改列定义、不给 .sidebar 加 display:none——DOM 本来就被 v-if 移除了。
 * 单列时 areas 也要跟着改成一列，否则 grid 找不到 sidebar 这个名字会警告。
 */
.layout.no-sidebar,
.layout.no-sidebar.is-collapsed {
  grid-template-columns: minmax(0, 1fr);
  grid-template-areas:
    'topbar'
    'main';
}

/*
 * 分栏：最左窄条通高（跨两行）放一级，第二栏放当前模块的子菜单
 *
 *   ┌────┬────────┬──────────┐
 *   │ 标 │ 模块名 │  顶栏    │
 *   │ 识 ├────────┼──────────┤
 *   │ 一 │ 二级   │  内容    │
 *   │ 级 │        │          │
 *   └────┴────────┴──────────┘
 *
 * 放在 `.is-collapsed` / `.no-sidebar` 之后：那两条规则写的是两列与一列的
 * 模板，分栏永远比它们多一列，靠源码顺序压过去（选择器权重相同）。
 */
.layout.is-columns {
  grid-template-columns: var(--keel-rail-width) var(--keel-sidebar-width) minmax(0, 1fr);
  grid-template-areas:
    'rail sidebar topbar'
    'rail sidebar main';
}

/*
 * 第二栏收起（用户主动折叠，或当前模块没有子菜单）
 *
 * 分栏版式下不存在「压成图标条」这一档，见 `showSidebar` 的注释。
 */
.layout.is-columns.no-sidebar,
.layout.is-columns.no-sidebar.is-collapsed {
  grid-template-columns: var(--keel-rail-width) minmax(0, 1fr);
  grid-template-areas:
    'rail topbar'
    'rail main';
}

.rail {
  grid-area: rail;
}

/* ---------------- 侧边栏 ---------------- */
.sidebar {
  display: flex;
  flex-direction: column;
  /*
   * 高度交给 grid 的行定义，不能再写 100vh：
   * 混合版式下侧栏只占第二行（顶栏下方），写死一屏高会顶出去多出一条顶栏的量。
   * 经典版式它跨两行，100% 同样等于满屏。
   */
  height: 100%;
  min-height: 0;
  background: var(--el-bg-color);
  border-right: 1px solid var(--el-border-color);
  overflow: hidden;
}

.brand {
  display: flex;
  align-items: center;
  justify-content: center;
  /* 高度与描边都必须跟顶栏一致——它们拼的是同一条横线，
     差 4px 或者差一档灰度都会在侧栏边界处露馅 */
  height: var(--keel-topbar-height);
  flex: none;
  border-bottom: 1px solid var(--el-border-color-light);
  white-space: nowrap;
}

/*
 * 分栏版式下第二栏顶部的模块名
 *
 * 尺寸必须与 `.brand`、顶栏一致（同一个高度令牌 + 同一条描边），
 * 三列的横线要在同一水平上——差一档就能在两条竖直分界处同时看出来。
 */
.sub-title {
  display: flex;
  align-items: center;
  height: var(--keel-topbar-height);
  padding: 0 16px;
  flex: none;
  border-bottom: 1px solid var(--el-border-color-light);
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.menu-scroll {
  flex: 1;
}

/* ---------------- 页签 + 内容 ---------------- */
.main {
  display: flex;
  flex-direction: column;
  min-width: 0;
  /* flex 子项默认 min-height:auto，不置 0 的话 .content 撑不小，
     滚动条又会跑回 window 上——这一行是整套固定外壳的关键 */
  min-height: 0;
  background: var(--el-bg-color-page);
}

/* ---------------- 顶栏 ---------------- */

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
  border-radius: var(--keel-radius);
  font-size: 18px;
  color: var(--el-text-color-primary);
  cursor: pointer;
  transition: background 0.2s;
}

.hamburger:hover,
.topbar :deep(.icon-btn:hover) {
  background: var(--el-fill-color-light);
}

/*
 * 混合版式：品牌标记与一级菜单都在顶栏
 *
 * 标记占住原本侧栏那一列的宽度，让它和下方侧栏的左边界对齐——
 * 不定宽的话 logo 有多宽就占多宽，一级菜单的起点会跟侧栏错开几个像素，
 * 那条竖直分界线看着就是歪的。
 */
.topbar-brand {
  flex: none;
  width: calc(var(--keel-sidebar-width) - 20px);
}

/*
 * 顶栏是 flex 且这一项要能被压缩：一级模块多起来时由它自己横向滚，
 * 而不是把右侧的搜索、设置、头像挤出可视区。
 * min-width:0 少了的话 flex 子项按内容撑开，压缩不生效。
 */
.topbar-menu {
  flex: 1;
  min-width: 0;
}

.spacer {
  flex: 1;
}

/* 一级菜单已经占满中间，再留一个弹性空隙会把它推向左边 */
.layout.is-mix .spacer {
  flex: none;
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
   * 窄屏下侧边栏堆到顶部，整个外壳比一屏高——这时必须放开高度限制，
   * 回到整页滚动。继续锁 100vh 的话菜单会把内容挤出视口，
   * 而内容区自己那个滚动条又够不着
   */
  .layout,
  .layout.is-collapsed,
  .layout.is-mix,
  .layout.is-columns {
    grid-template-columns: minmax(0, 1fr);
    /*
     * 单列下三块竖着堆：顶栏 → 侧栏 → 内容。
     * 行高改成 auto，否则顶栏那行仍被钉在 56px，而这里它可能要换行。
     * 两种版式在窄屏下收敛成同一套——屏幕宽度不够时，
     * 「一级在顶栏还是在侧栏」这个区别已经没有意义了。
     */
    grid-template-rows: auto auto minmax(0, 1fr);
    grid-template-areas:
      'topbar'
      'sidebar'
      'main';
    height: auto;
    min-height: 100vh;
    overflow: visible;
  }

  /*
   * 分栏在窄屏下多一块：一级窄条横过来堆在最上面（它在 ColumnRail 里自己
   * 改成了横排）。没有子菜单时 sidebar 这个区域是空的，grid 允许区域没有对应
   * 元素，所以不必再分出一套模板。
   */
  .layout.is-columns,
  .layout.is-columns.is-collapsed,
  .layout.is-columns.no-sidebar {
    grid-template-rows: auto auto auto minmax(0, 1fr);
    grid-template-areas:
      'rail'
      'topbar'
      'sidebar'
      'main';
  }

  .content {
    min-height: 0;
    overflow-y: visible;
  }

  .sidebar {
    height: auto;
    border-right: none;
    border-bottom: 1px solid var(--el-border-color);
  }

  /* 窄屏顶栏已经在最上面，logo 不必再占侧栏那一列的宽度 */
  .topbar-brand {
    width: auto;
  }
}
</style>
