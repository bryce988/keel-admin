<script setup lang="ts">
import { useRouter } from 'vue-router'
import { resolveMenuIcon } from '@/utils/icons'
import { useMenuNav } from '@/composables/useMenuNav'
import type { MenuNode } from '@/stores/user'

/**
 * 混合版式的一级菜单（顶栏横排）
 *
 * 只画一级，子菜单交给左侧栏——这正是「混合」与「顶部版式」的分界：
 * 顶部版式会在这里挂下拉浮层，一级多起来时浮层会盖住内容区。
 */
const router = useRouter()
const { topMenus, activeTop, firstLeafPath } = useMenuNav()

const resolveIcon = resolveMenuIcon

/**
 * 点一级项 → 进它的第一个叶子页面
 *
 * 目录型的一级项自己没有 component，直接 push 它的 path 会落到一个
 * 匹配不到组件的路由上（表现是点了没反应）。下钻逻辑在 useMenuNav 里。
 *
 * 已经在当前模块里就不跳：否则从「部门管理」点一下顶栏的「系统管理」，
 * 会被踢回「用户管理」——用户的本意只是看一眼这个模块有什么。
 */
function onSelect(node: MenuNode) {
  if (activeTop.value?.id === node.id) return
  router.push(firstLeafPath(node))
}
</script>

<template>
  <nav class="top-menu">
    <button
      v-for="node in topMenus"
      :key="node.id"
      type="button"
      class="top-item"
      :class="{ 'is-active': activeTop?.id === node.id }"
      @click="onSelect(node)"
    >
      <el-icon><component :is="resolveIcon(node.icon)" /></el-icon>
      <span>{{ node.name }}</span>
    </button>
  </nav>
</template>

<style scoped>
/*
 * 用 <button> 而不是 el-menu
 *
 * el-menu 的横向模式会自带下拉浮层、`default-active` 要求 index 与路由一一对应，
 * 而这里一级项本身**不是页面**（点了要下钻到叶子），对不上。
 * 一排按钮反而更直白，也不用跟组件库的高亮逻辑较劲。
 */
.top-menu {
  display: flex;
  align-items: center;
  gap: 4px;
  /* 一级模块多起来时横向滚，而不是把右侧的搜索、头像挤出顶栏 */
  overflow-x: auto;
  scrollbar-width: none;
}

.top-menu::-webkit-scrollbar {
  display: none;
}

.top-item {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 12px;
  flex: none;
  border: none;
  border-radius: var(--keel-radius);
  background: transparent;
  font-family: inherit;
  font-size: 14px;
  color: var(--el-text-color-regular);
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.top-item:hover {
  background: var(--el-fill-color-light);
  color: var(--el-text-color-primary);
}

.top-item.is-active {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}
</style>
