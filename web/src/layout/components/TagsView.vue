<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Close } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { MAX_TAGS, useTagsViewStore } from '@/stores/tagsView'

const route = useRoute()
const router = useRouter()
const tagsStore = useTagsViewStore()

const stripRef = ref<HTMLElement>()

/** 右键菜单 */
const ctx = reactive({ visible: false, x: 0, y: 0, path: '' })

const ctxIndex = computed(() => tagsStore.tags.findIndex((t) => t.path === ctx.path))
const rightCount = computed(() =>
  ctxIndex.value === -1 ? 0 : tagsStore.tags.slice(ctxIndex.value + 1).filter((t) => !t.affix).length
)
const otherCount = computed(
  () => tagsStore.tags.filter((t) => !t.affix && t.path !== ctx.path).length
)

// 监听 fullPath 而不是 path：同一个页面改了筛选条件也要更新页签记住的地址，
// 这样切走再切回来筛选与页码还在
watch(
  () => route.fullPath,
  () => {
    tagsStore.open(route)
    if (tagsStore.lastEvicted) {
      ElMessage.info(`页签已达上限 ${MAX_TAGS} 个，已关闭最早的「${tagsStore.lastEvicted}」`)
      tagsStore.lastEvicted = ''
    }
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

function openCtx(e: MouseEvent, path: string) {
  ctx.path = path
  ctx.x = e.clientX
  ctx.y = e.clientY
  ctx.visible = true
}

function closeCtx() {
  ctx.visible = false
}

function onCommand(cmd: string) {
  const path = ctx.path
  closeCtx()

  switch (cmd) {
    case 'refresh': {
      // 刷新当前页：借助 key 变化重新挂载
      if (path !== route.path) {
        const target = tagsStore.tags.find((t) => t.path === path)
        router.push(target?.fullPath ?? path)
      }
      window.dispatchEvent(new CustomEvent('keel:refresh-page'))
      break
    }
    case 'close':
      onClose(path)
      break
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
        @contextmenu.prevent="openCtx($event, tag.path)"
      >
        <span class="dot" />
        <span class="title">{{ tag.title }}</span>
        <el-icon v-if="!tag.affix" class="close" @click.prevent.stop="onClose(tag.path)">
          <Close />
        </el-icon>
      </router-link>
    </div>

    <span class="count">{{ tagsStore.tags.length }} / {{ MAX_TAGS }}</span>
    <el-button size="small" @click="onCommand('closeOthers')">关闭其他</el-button>
    <el-button size="small" @click="onCommand('closeAll')">全部关闭</el-button>

    <!-- 右键菜单 -->
    <teleport to="body">
      <ul v-show="ctx.visible" class="ctx-menu" :style="{ left: ctx.x + 'px', top: ctx.y + 'px' }">
        <li @click="onCommand('refresh')">刷新当前</li>
        <li class="sep" />
        <li :class="{ disabled: ctxIndex === 0 }" @click="ctxIndex !== 0 && onCommand('close')">
          关闭
        </li>
        <li :class="{ disabled: !rightCount }" @click="rightCount && onCommand('closeRight')">
          关闭右侧<em>{{ rightCount }}</em>
        </li>
        <li :class="{ disabled: !otherCount }" @click="otherCount && onCommand('closeOthers')">
          关闭其他<em>{{ otherCount }}</em>
        </li>
        <li class="sep" />
        <li @click="onCommand('closeAll')">全部关闭</li>
      </ul>
    </teleport>
  </div>
</template>

<style scoped>
.tags-view {
  display: flex;
  align-items: center;
  gap: 8px;
  height: var(--keel-tags-height);
  padding: 0 16px;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
  box-shadow: 0 1px 3px 0 rgb(0 0 0 / 4%);
}

.strip {
  display: flex;
  gap: 6px;
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
  height: 26px;
  padding: 0 8px;
  border: 1px solid var(--el-border-color-light);
  border-radius: 3px;
  background: var(--el-bg-color);
  color: var(--el-text-color-regular);
  font-size: 12px;
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
  width: 14px;
  height: 14px;
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

.count {
  flex: none;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>

<style>
/* 右键菜单挂在 body 上，不能用 scoped */
.ctx-menu {
  position: fixed;
  z-index: 3000;
  min-width: 160px;
  margin: 0;
  padding: 5px 0;
  list-style: none;
  background: var(--el-bg-color-overlay);
  border: 1px solid var(--el-border-color-light);
  border-radius: 4px;
  box-shadow: var(--el-box-shadow-light);
}

.ctx-menu li {
  display: flex;
  align-items: center;
  height: 34px;
  padding: 0 16px;
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
