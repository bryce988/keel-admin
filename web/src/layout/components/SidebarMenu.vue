<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import * as ElIcons from '@element-plus/icons-vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import type { MenuNode } from '@/stores/user'

const route = useRoute()
const appStore = useAppStore()
const userStore = useUserStore()

/** 后端返回的 icon 是 Element Plus 图标名，这里动态解析 */
function resolveIcon(name: string) {
  return (ElIcons as Record<string, unknown>)[name] ?? ElIcons.Menu
}

const menus = computed(() => userStore.menus.filter((m) => m.visible))

/**
 * 当前高亮项：详情页等不在菜单里的路由，
 * 通过 meta.activeMenu 指回它的列表页（PROJECT.md §4）
 */
const activeMenu = computed(() => (route.meta.activeMenu as string) || route.path)

function hasChildren(node: MenuNode) {
  return !!node.children?.some((c) => c.visible)
}
</script>

<template>
  <el-menu
    class="sidebar-menu"
    :default-active="activeMenu"
    :collapse="appStore.sidebarCollapsed"
    :collapse-transition="false"
    router
  >
    <template v-for="group in menus" :key="group.id">
      <!-- 一级目录：只负责展开，不承载页面 -->
      <el-sub-menu v-if="hasChildren(group)" :index="String(group.id)">
        <template #title>
          <el-icon><component :is="resolveIcon(group.icon)" /></el-icon>
          <span>{{ group.name }}</span>
        </template>
        <!--
          子菜单同样画图标：icon 字段本来就逐条存着（菜单管理里能看到），
          之前这一支只输出名字，等于把数据白存了。
          图标也不是纯装饰——折叠态下二级项弹在浮层里，只有图标能提供辨识锚点
        -->
        <el-menu-item
          v-for="item in group.children!.filter((c) => c.visible)"
          :key="item.id"
          :index="item.path"
        >
          <el-icon><component :is="resolveIcon(item.icon)" /></el-icon>
          <template #title>{{ item.name }}</template>
        </el-menu-item>
      </el-sub-menu>

      <!-- 无子级的一级菜单直接跳转 -->
      <el-menu-item v-else :index="group.path">
        <el-icon><component :is="resolveIcon(group.icon)" /></el-icon>
        <template #title>{{ group.name }}</template>
      </el-menu-item>
    </template>
  </el-menu>
</template>

<style scoped>
.sidebar-menu {
  border-right: none;
}

/* 折叠态下不显示子菜单箭头留白 */
.sidebar-menu:not(.el-menu--collapse) {
  width: 100%;
}
</style>
