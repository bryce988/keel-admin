<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Search } from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import type { MenuNode } from '@/stores/user'

/** 一条可跳转的结果 */
interface MenuHit {
  name: string
  path: string
  group: string
}

const router = useRouter()
const userStore = useUserStore()

const visible = ref(false)
const keyword = ref('')
/** 键盘高亮的下标；鼠标移入也会同步过来，两种操作方式共用一个游标 */
const active = ref(0)
const inputRef = ref<{ focus: () => void } | null>(null)
const listRef = ref<HTMLElement | null>(null)

const isMac = /mac/i.test(navigator.userAgent)
const shortcut = isMac ? '⌘ K' : 'Ctrl K'

/**
 * 把菜单树拍平成「能直接跳的叶子」
 *
 * 只收叶子：type=1 的目录（有子节点的那种）自己没有页面，
 * 搜出来点了也是空的。`visible` 的过滤与侧边栏同一套口径。
 *
 * 结果不需要缓存——菜单是登录时一次性下发的，几十条量级，
 * 每次敲键重算的成本远小于维护一份失效逻辑。
 */
const entries = computed<MenuHit[]>(() => {
  const out: MenuHit[] = []

  const walk = (nodes: MenuNode[], group: string) => {
    for (const node of nodes) {
      if (!node.visible) continue
      const children = node.children?.filter((c) => c.visible) ?? []
      if (children.length) {
        walk(children, group || node.name)
      } else if (node.path) {
        out.push({ name: node.name, path: node.path, group })
      }
    }
  }

  walk(userStore.menus, '')
  return out
})

/**
 * 匹配范围包含分组名与路径
 *
 * 「日志」应该能搜到「日志审计」下的两个页面，敲 `/system/user` 也该出来——
 * 只匹配菜单名的话这两种都落空，而它们恰恰是老手最常用的输入方式。
 */
const results = computed<MenuHit[]>(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) return entries.value
  return entries.value.filter((e) => `${e.name}${e.group}${e.path}`.toLowerCase().includes(kw))
})

function open() {
  visible.value = true
}

watch(visible, (on) => {
  if (!on) return
  // 每次打开都是干净的：搜索框不是地址栏，留着上次的词还得先全选删掉
  keyword.value = ''
  active.value = 0
})

/**
 * 聚焦必须挂在 `@opened` 上
 *
 * 在 watch 里 `nextTick` 聚焦是抢不过的：el-dialog 内置 focus-trap，
 * 挂载后会把焦点收到 `.el-dialog` 容器上，时序正好在 nextTick 之后
 * （实测 activeElement 是那个 div，不是输入框）。
 * `@opened` 在入场动画结束后才触发，那时焦点陷阱已经落定。
 */
function onOpened() {
  inputRef.value?.focus()
}

// 筛完之后原来的高亮多半已经越界，回到第一条
watch(results, () => {
  active.value = 0
})

function move(step: number) {
  const total = results.value.length
  if (!total) return
  active.value = (active.value + step + total) % total

  // 高亮跟着滚动条走，否则按到第 11 条时人已经看不见它了
  nextTick(() => {
    listRef.value?.children[active.value]?.scrollIntoView({ block: 'nearest' })
  })
}

function choose(hit?: MenuHit) {
  if (!hit) return
  visible.value = false
  if (hit.path !== router.currentRoute.value.path) {
    router.push(hit.path)
  }
}

/**
 * 全局快捷键
 *
 * 挂在 window 上而不是对话框内部：它的职责是把对话框叫出来，
 * 装在对话框里就只有对话框开着时才生效，等于没有。
 */
function onKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    visible.value = true
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

defineExpose({ open })
</script>

<template>
  <el-tooltip :content="`搜索菜单（${shortcut}）`">
    <el-icon class="icon-btn" @click="open">
      <Search />
    </el-icon>
  </el-tooltip>

  <!--
    命令面板而不是顶栏里的常驻输入框：一个 230px 的框只为偶尔用一次的功能
    长期占着顶栏，视觉上也压过了面包屑。收成图标，点开才铺开。
  -->
  <el-dialog
    v-model="visible"
    class="menu-search-dialog"
    width="520px"
    :show-close="false"
    append-to-body
    @opened="onOpened"
  >
    <el-input
      ref="inputRef"
      v-model="keyword"
      size="large"
      placeholder="搜索菜单"
      :prefix-icon="Search"
      clearable
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter.prevent="choose(results[active])"
    />

    <ul v-if="results.length" ref="listRef" class="hits">
      <li
        v-for="(item, i) in results"
        :key="item.path"
        :class="{ 'is-active': i === active }"
        @click="choose(item)"
        @mouseenter="active = i"
      >
        <span class="hit-name">{{ item.name }}</span>
        <span v-if="item.group" class="hit-group">{{ item.group }}</span>
      </li>
    </ul>

    <!-- 不给动作：这里唯一的出路就是改词重搜，摆个按钮反而多此一举 -->
    <EmptyState v-else scene="search" :keyword="keyword" :action="false" :size="70" />

    <div class="tips">
      <span><kbd>↑</kbd><kbd>↓</kbd> 选择</span>
      <span><kbd>Enter</kbd> 打开</span>
      <span><kbd>Esc</kbd> 关闭</span>
    </div>
  </el-dialog>
</template>

<!--
  对话框外壳的样式只能写在非 scoped 块里

  el-dialog 被传送到 body，`.el-dialog` 与 `.el-dialog__header` 都是 EP 自己
  渲染的元素，上面一个 data-v 都没有（实测），scoped 选择器与 :deep() 都够不着——
  写在 scoped 里的话头部照样显示、margin-top 也不生效。
  我自己写的 `.hits` 那些是插槽内容，带着本组件的作用域 id，仍然走 scoped。
  类名 `menu-search-dialog` 足够独特，全局也不会误伤。
-->
<style>
/*
 * 顶部锚定，不用 `align-center`
 *
 * 两个原因：`.el-dialog.is-align-center` 是 `margin: auto`，会把下面这条
 * margin-top 直接架空（实测算出来 165px，10vh 根本没生效）；
 * 更要紧的是垂直居中会让面板随结果条数变化上下跳——边打字边跳，很难受。
 *
 * 另外 el-dialog 在 EP 2.x 没有 `top` 属性，位置只能靠这个 CSS 变量，
 * 照记忆写 top="10vh" 是静默失效的。
 */
.menu-search-dialog {
  --el-dialog-margin-top: 10vh;
}

/* 没有标题也没有关闭按钮，那条空的头部只剩 16px 内边距，得去掉 */
.menu-search-dialog .el-dialog__header {
  display: none;
}
</style>

<style scoped>
.hits {
  max-height: 320px;
  margin: 12px 0 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.hits li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  height: 40px;
  padding: 0 12px;
  border-radius: 4px;
  cursor: pointer;
}

/*
 * 只有一个高亮态，鼠标与键盘共用
 *
 * 分成 :hover 和 .is-active 两套的话，鼠标停在别处、手上按方向键时
 * 会同时亮两条，看不出回车会打开哪个
 */
.hits li.is-active {
  background: var(--el-fill-color-light);
}

/* min-width:0 是必需的：flex 子项默认 min-width:auto，
   不置 0 的话它宁可把分组名挤出容器也不肯截断自己 */
.hit-name {
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: var(--el-text-color-primary);
}

.hit-group {
  flex: none;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.tips {
  display: flex;
  gap: 16px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid var(--el-border-color-lighter);
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.tips kbd {
  display: inline-block;
  min-width: 18px;
  margin-right: 2px;
  padding: 1px 4px;
  border: 1px solid var(--el-border-color);
  border-radius: 3px;
  background: var(--el-fill-color-light);
  font-family: inherit;
  text-align: center;
}
</style>
