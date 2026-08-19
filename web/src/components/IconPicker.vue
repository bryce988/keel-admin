<script setup lang="ts">
import { computed, ref } from 'vue'
import * as ElIcons from '@element-plus/icons-vue'
import { Search } from '@element-plus/icons-vue'

/**
 * 图标选择器
 *
 * 取代原来的 `el-select + 300 个 el-option`：下拉一行一个，
 * 找一个图标要滚半天，而且窄下拉里图标只有一列，根本谈不上"看图选图"。
 * 这里点开是一整面网格，配英文关键词搜索。
 *
 * **没有做官网那样的分类折叠**：`@element-plus/icons-vue` 只导出组件，
 * 不带任何分类元数据（官网的 Arrows / Document 那些分组是文档站自己维护的
 * 一份 JSON）。要分组就得在这里硬编码近 300 个图标名的归属，
 * EP 每加一批图标就漏一批，而漏掉的那些会**从选择器里消失**——
 * 比不分组糟得多。按名称升序本身已经把同族图标聚在一起了
 * （ArrowDown/ArrowLeft/…、CaretBottom/…），再配搜索够用。
 *
 * 全局注册的组件进不了 tree-shaking（见 components/index.ts 的说明），
 * 这个只有菜单管理一个页面用，所以按需 import，不进 install。
 */
const props = withDefaults(
  defineProps<{
    modelValue?: string
    placeholder?: string
  }>(),
  { modelValue: '', placeholder: '点击选择图标' }
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const visible = ref(false)
const keyword = ref('')

const names = Object.keys(ElIcons).sort()

function iconComp(name: string) {
  return (ElIcons as Record<string, unknown>)[name]
}

/** 选中的图标可能是手填的、或 EP 升级后被移除的，解析不到就当没选 */
const current = computed(() => (props.modelValue ? iconComp(props.modelValue) : undefined))

const results = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  if (!kw) return names
  return names.filter((n) => n.toLowerCase().includes(kw))
})

function pick(name: string) {
  emit('update:modelValue', name)
  visible.value = false
}

function clear() {
  emit('update:modelValue', '')
  visible.value = false
}
</script>

<template>
  <el-popover
    v-model:visible="visible"
    :width="392"
    trigger="click"
    placement="bottom-start"
    popper-class="icon-picker-popper"
    @show="keyword = ''"
  >
    <template #reference>
      <div class="icon-picker-trigger">
        <!--
          输入框是只读的：值是图标名（如 Odometer），手打没有意义，
          打错了还会让侧边栏渲染成兜底图标而不报错。
          只读会让 EP 的 clearable 失效，所以「清除」放在面板底部
        -->
        <el-input :model-value="modelValue" :placeholder="placeholder" readonly>
          <template #prefix>
            <el-icon v-if="current" class="picked"><component :is="current" /></el-icon>
            <el-icon v-else><Search /></el-icon>
          </template>
        </el-input>
      </div>
    </template>

    <el-input
      v-model="keyword"
      placeholder="搜索图标（英文关键词）"
      :prefix-icon="Search"
      clearable
    />

    <el-scrollbar v-if="results.length" height="264px" class="icon-scroll">
      <div class="icon-grid">
        <!--
          用原生 title 而不是 el-tooltip：一屏近 300 个格子，
          每个挂一个 tooltip 实例就是 300 个 popper，打开面板会明显卡一下
        -->
        <button
          v-for="name in results"
          :key="name"
          type="button"
          class="icon-cell"
          :class="{ 'is-active': name === modelValue }"
          :title="name"
          @click="pick(name)"
        >
          <el-icon><component :is="iconComp(name)" /></el-icon>
        </button>
      </div>
    </el-scrollbar>

    <!-- 不给动作：这里唯一的出路就是改词重搜 -->
    <EmptyState v-else scene="search" :keyword="keyword" :action="false" :size="60" />

    <div class="icon-footer">
      <span class="picked-name">
        <template v-if="modelValue">
          已选 <el-icon><component :is="current" /></el-icon> {{ modelValue }}
        </template>
        <template v-else>共 {{ results.length }} 个图标</template>
      </span>
      <el-button v-if="modelValue" link type="primary" @click="clear">清除</el-button>
    </div>
  </el-popover>
</template>

<style scoped>
.icon-picker-trigger {
  width: 100%;
}

.picked {
  color: var(--el-text-color-primary);
}

.icon-scroll {
  margin-top: 10px;
}

/*
 * auto-fill 而不是固定列数：面板宽度以后要是改了，格子会自己重排，
 * 不用回来同步一个写死的 8
 */
.icon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(42px, 1fr));
  gap: 4px;
  padding-right: 6px;
}

.icon-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  padding: 0;
  border: 1px solid transparent;
  border-radius: 4px;
  background: none;
  color: var(--el-text-color-regular);
  font-size: 18px;
  cursor: pointer;
}

.icon-cell:hover {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-fill-color-light);
  color: var(--el-color-primary);
}

.icon-cell.is-active {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.icon-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--el-border-color-lighter);
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.picked-name {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
</style>
