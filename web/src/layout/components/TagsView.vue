<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  CircleClose,
  Close,
  Paperclip,
  Refresh
} from '@element-plus/icons-vue'
import { useTagsViewStore } from '@/stores/tagsView'

const route = useRoute()
const router = useRouter()
const tagsStore = useTagsViewStore()

const stripRef = ref<HTMLElement>()

/** 菜单宽度写死，是为了从按钮右对齐时能算准 left（见 openFromButton） */
const MENU_WIDTH = 176
/** 估算高度，只用于贴边时的夹取，宁可略大 */
const MENU_HEIGHT = 250

/**
 * 一份菜单，两个入口
 *
 * 右键页签作用于该页签，页签条右端的下拉按钮作用于当前页签。
 * 两者共用同一份 items 与同一个浮层——之前页签条上那两个按钮和右键菜单
 * 是各写各的，加一项要改两处，迟早不一致
 */
const ctx = reactive({ visible: false, x: 0, y: 0, path: '' })

const ctxIndex = computed(() => tagsStore.tags.findIndex((t) => t.path === ctx.path))
const ctxTag = computed(() => tagsStore.tags[ctxIndex.value])

/** 各方向上「真正能被关掉的」数量：固定页签不算在内，否则计数会骗人 */
const leftCount = computed(() =>
  ctxIndex.value <= 0 ? 0 : tagsStore.tags.slice(0, ctxIndex.value).filter((t) => !t.affix).length
)
const rightCount = computed(() =>
  ctxIndex.value === -1 ? 0 : tagsStore.tags.slice(ctxIndex.value + 1).filter((t) => !t.affix).length
)
const otherCount = computed(
  () => tagsStore.tags.filter((t) => !t.affix && t.path !== ctx.path).length
)
const allCount = computed(() => tagsStore.tags.filter((t) => !t.affix).length)

interface MenuItem {
  cmd?: string
  label?: string
  icon?: unknown
  count?: number
  disabled?: boolean
  sep?: boolean
}

const menuItems = computed<MenuItem[]>(() => [
  { cmd: 'refresh', label: '刷新', icon: Refresh },
  {
    cmd: 'affix',
    label: ctxTag.value?.affix ? '取消固定' : '固定',
    icon: Paperclip,
    // 首页签的固定是结构约束不是偏好，理由见 store 的 toggleAffix
    disabled: !ctxTag.value || ctxIndex.value === 0
  },
  { sep: true },
  { cmd: 'closeLeft', label: '关闭左侧', icon: ArrowLeft, count: leftCount.value },
  { cmd: 'closeRight', label: '关闭右侧', icon: ArrowRight, count: rightCount.value },
  { cmd: 'closeOthers', label: '关闭其他', icon: Close, count: otherCount.value },
  { cmd: 'closeAll', label: '关闭全部', icon: CircleClose, count: allCount.value }
])

// 监听 fullPath 而不是 path：同一个页面改了筛选条件也要更新页签记住的地址，
// 这样切走再切回来筛选与页码还在
watch(
  () => route.fullPath,
  () => {
    tagsStore.open(route)
    scrollActiveIntoView()
  },
  { immediate: true }
)

function scrollActiveIntoView() {
  requestAnimationFrame(() => {
    stripRef.value?.querySelector('.tag.is-active')?.scrollIntoView({
      block: 'nearest',
      inline: 'nearest'
    })
  })
}

function onClose(path: string) {
  const next = tagsStore.close(path)
  if (next) router.push(next)
}

/** 统一开菜单：坐标一律夹在视口内，否则贴着右边或底边的页签会把菜单顶出屏幕 */
function openMenu(x: number, y: number, path: string) {
  ctx.path = path
  ctx.x = Math.max(8, Math.min(x, window.innerWidth - MENU_WIDTH - 8))
  ctx.y = Math.max(8, Math.min(y, window.innerHeight - MENU_HEIGHT - 8))
  ctx.visible = true
}

function openFromTag(e: MouseEvent, path: string) {
  openMenu(e.clientX, e.clientY, path)
}

function openFromButton(e: MouseEvent) {
  // 再点一次收起。按钮上带了 .stop，document 的关闭监听收不到这次点击
  if (ctx.visible) {
    closeCtx()
    return
  }

  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  openMenu(rect.right - MENU_WIDTH, rect.bottom + 6, route.path)
}

function closeCtx() {
  ctx.visible = false
}

/** 计数为 0 的批量项置灰：它同时在告诉用户「这个方向没东西可关」 */
function isDisabled(item: MenuItem) {
  if (item.disabled) return true
  return item.count !== undefined && item.count === 0
}

function onCommand(item: MenuItem) {
  if (item.sep || !item.cmd || isDisabled(item)) return

  const path = ctx.path
  closeCtx()

  switch (item.cmd) {
    case 'refresh': {
      // 刷新当前页：借助 key 变化重新挂载
      if (path !== route.path) {
        const target = tagsStore.tags.find((t) => t.path === path)
        router.push(target?.fullPath ?? path)
      }
      window.dispatchEvent(new CustomEvent('keel:refresh-page'))
      break
    }
    case 'affix':
      tagsStore.toggleAffix(path)
      break
    case 'closeLeft': {
      const next = tagsStore.closeLeft(path)
      if (next) router.push(next)
      break
    }
    case 'closeRight': {
      const next = tagsStore.closeRight(path)
      if (next) router.push(next)
      break
    }
    case 'closeOthers':
      router.push(tagsStore.closeOthers(path))
      break
    case 'closeAll':
      router.push(tagsStore.closeAll())
      break
  }
}

onMounted(() => {
  document.addEventListener('click', closeCtx)
  window.addEventListener('scroll', closeCtx, true)
})
onUnmounted(() => {
  document.removeEventListener('click', closeCtx)
  window.removeEventListener('scroll', closeCtx, true)
})
</script>

<template>
  <div class="tags-view">
    <div ref="stripRef" class="strip">
      <router-link
        v-for="tag in tagsStore.tags"
        :key="tag.path"
        :to="tag.fullPath"
        class="tag"
        :class="{ 'is-active': tag.path === route.path, 'is-affix': tag.affix }"
        @contextmenu.prevent="openFromTag($event, tag.path)"
      >
        <span class="dot" />
        <span class="title">{{ tag.title }}</span>
        <!-- 固定的页签把关闭位换成图钉：既表明状态，也说明它为什么关不掉 -->
        <el-icon v-if="tag.affix" class="pin"><Paperclip /></el-icon>
        <el-icon v-else class="close" @click.prevent.stop="onClose(tag.path)">
          <Close />
        </el-icon>
      </router-link>
    </div>

    <button type="button" class="more" title="页签操作" @click.stop="openFromButton">
      <el-icon><ArrowDown /></el-icon>
    </button>

    <!-- 右键页签与右端按钮共用这一个浮层 -->
    <teleport to="body">
      <ul v-show="ctx.visible" class="ctx-menu" :style="{ left: ctx.x + 'px', top: ctx.y + 'px' }">
        <template v-for="(item, i) in menuItems" :key="item.cmd ?? `sep-${i}`">
          <li v-if="item.sep" class="sep" />
          <li v-else :class="{ disabled: isDisabled(item) }" @click="onCommand(item)">
            <el-icon><component :is="item.icon" /></el-icon>
            <span>{{ item.label }}</span>
            <em v-if="item.count">{{ item.count }}</em>
          </li>
        </template>
      </ul>
    </teleport>
  </div>
</template>

<style scoped>
.tags-view {
  display: flex;
  /* 固定高度的横条，不参与收缩：外壳锁定视口后，
     少这一行页签会被内容挤扁（layout/index.vue 的 .content 说明） */
  flex: none;
  align-items: center;
  gap: 8px;
  height: var(--keel-tags-height);
  padding: 0 16px;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
  /* 走令牌而不是写死纯黑：深浅两套主题的阴影颜色不同（见 styles/index.css），
     写死的话深色模式下这条 4% 的黑投在 #0a0a0a 上完全看不见 */
  box-shadow: var(--el-box-shadow-lighter);
}

.strip {
  display: flex;
  /* 间距 10px：页签之间靠得太近时，一排看下来是一整条色块而不是几个独立标签 */
  gap: 10px;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: none;
}

.strip::-webkit-scrollbar {
  display: none;
}

.tag {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: none;
  /* 30px / 13px：比正文的 14px 小半档，页签是导航不是内容，
     但也不能像原来 26px / 12px 那样小到要凑近才看清 */
  height: 30px;
  padding: 0 8px;
  border: 1px solid var(--el-border-color-light);
  border-radius: var(--keel-radius);
  background: var(--el-bg-color);
  color: var(--el-text-color-regular);
  font-size: 13px;
  text-decoration: none;
  transition: all 0.15s;
}

.tag:hover {
  color: var(--el-color-primary);
}

.tag .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: transparent;
}

.tag.is-active {
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
  color: #fff;
}

.tag.is-active .dot {
  background: #fff;
}

.tag .close {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  font-size: 12px;
}

.tag .close:hover {
  background: var(--el-text-color-secondary);
  color: #fff;
}

.tag.is-active .close:hover {
  background: #fff;
  color: var(--el-color-primary);
}

.tag .pin {
  width: 14px;
  height: 14px;
  font-size: 11px;
  /* 图钉是状态而非按钮，压暗一档，别让人以为能点 */
  opacity: 0.65;
}

/* 右端的下拉入口。做成和页签同高，视觉上属于这条横条而不是浮在上面 */
.more {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  /* 与页签同高，视觉上属于这条横条而不是浮在上面 */
  width: 30px;
  height: 30px;
  padding: 0;
  border: 1px solid var(--el-border-color-light);
  border-radius: var(--keel-radius);
  background: var(--el-bg-color);
  color: var(--el-text-color-regular);
  font-size: 12px;
  cursor: pointer;
}

.more:hover {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}
</style>

<style>
/* 右键菜单挂在 body 上，不能用 scoped */
.ctx-menu {
  position: fixed;
  z-index: 3000;
  margin: 0;
  padding: 5px 0;
  list-style: none;
  background: var(--el-bg-color-overlay);
  border: 1px solid var(--el-border-color-light);
  border-radius: 4px;
  box-shadow: var(--el-box-shadow-light);
}

.ctx-menu {
  /* 定宽而不是 min-width：从按钮右对齐时要拿它算 left（TagsView 的 MENU_WIDTH） */
  width: 176px;
}

.ctx-menu li {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 34px;
  padding: 0 14px;
  font-size: 14px;
  color: var(--el-text-color-regular);
  cursor: pointer;
}

.ctx-menu li:hover:not(.disabled):not(.sep) {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.ctx-menu li.disabled {
  color: var(--el-text-color-disabled);
  cursor: not-allowed;
}

.ctx-menu li em {
  margin-left: auto;
  font-style: normal;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.ctx-menu li.sep {
  height: 1px;
  margin: 5px 0;
  padding: 0;
  background: var(--el-border-color-lighter);
  cursor: default;
}
</style>
