<script setup lang="ts">
import { useRouter } from 'vue-router'
import BrandLogo from '@/components/BrandLogo.vue'
import { resolveMenuIcon } from '@/utils/icons'
import { useMenuNav } from '@/composables/useMenuNav'
import type { MenuNode } from '@/stores/user'

/**
 * 分栏版式的一级导航条（最左窄条）
 *
 * 与 <TopMenu> 是同一件事的两种摆法——都只画一级、都要下钻到叶子、都从路由反推
 * 高亮，逻辑全在 `useMenuNav` 里共用，这里只负责竖排的样子（图标在上、名字在下）。
 *
 * 没有做成「给 TopMenu 加一个 direction prop」：两者的排布、尺寸、溢出策略
 * （横向滚 vs 纵向滚）没有一行 CSS 是共享的，合成一个组件只会得到一堆
 * `v-if="vertical"`，而省下的那点结构不值这个价。
 */
const router = useRouter()
const { topMenus, activeTop, firstLeafPath } = useMenuNav()

const resolveIcon = resolveMenuIcon

/** 与顶栏一致：已在当前模块就不跳，避免把人从二级页踢回第一个叶子 */
function onSelect(node: MenuNode) {
  if (activeTop.value?.id === node.id) return
  router.push(firstLeafPath(node))
}
</script>

<template>
  <aside class="column-rail">
    <!--
      窄条只放标记不放字标：80px 里塞进产品名会挤成两行，
      而产品名在这个版式下由第二栏顶部的模块名接手承担识别。
    -->
    <div class="rail-brand">
      <BrandLogo :text="false" />
    </div>

    <el-scrollbar class="rail-scroll">
      <nav class="rail-list">
        <button
          v-for="node in topMenus"
          :key="node.id"
          type="button"
          class="rail-item"
          :class="{ 'is-active': activeTop?.id === node.id }"
          :title="node.name"
          @click="onSelect(node)"
        >
          <el-icon class="rail-icon"><component :is="resolveIcon(node.icon)" /></el-icon>
          <span class="rail-name">{{ node.name }}</span>
        </button>
      </nav>
    </el-scrollbar>
  </aside>
</template>

<style scoped>
.column-rail {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: var(--el-bg-color-page);
  border-right: 1px solid var(--el-border-color);
  overflow: hidden;
}

/* 高度与顶栏、侧栏 logo 区共用同一个令牌：三者拼的是同一条横线 */
.rail-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  height: var(--keel-topbar-height);
  flex: none;
  border-bottom: 1px solid var(--el-border-color-light);
}

.rail-scroll {
  flex: 1;
  min-height: 0;
}

.rail-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 6px;
}

.rail-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 10px 2px;
  border: none;
  border-radius: var(--keel-radius);
  background: transparent;
  font-family: inherit;
  color: var(--el-text-color-regular);
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.rail-item:hover {
  background: var(--el-fill-color-light);
  color: var(--el-text-color-primary);
}

.rail-item.is-active {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.rail-icon {
  font-size: 18px;
}

/*
 * 名字超长就截断而不是换行
 *
 * 「系统监控」这类四字模块正好放得下，但菜单名由后端配置，谁都可能填六个字。
 * 允许换行的话条目高度会参差不齐，整条导航看起来是坏的；截断至少保持节奏，
 * 完整名字由 title 兜住。
 */
.rail-name {
  max-width: 100%;
  font-size: 12px;
  line-height: 1.2;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/*
 * 窄屏下窄条横过来，跟顶栏、侧栏一样堆在上面
 *
 * 断点与 layout/index.vue 那套保持一致（900px）：那边把三块改成竖着堆，
 * 这里若还立着一条 80px 的竖条，内容区就只剩一条缝。
 */
@media (max-width: 900px) {
  .column-rail {
    height: auto;
    border-right: none;
    border-bottom: 1px solid var(--el-border-color);
  }

  .rail-brand {
    display: none;
  }

  .rail-list {
    flex-direction: row;
    overflow-x: auto;
  }

  .rail-item {
    flex: none;
    min-width: var(--keel-rail-width);
  }
}
</style>
