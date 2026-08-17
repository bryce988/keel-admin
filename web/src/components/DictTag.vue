<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useDictStore } from '@/stores/dict'

/**
 * 字典标签
 *
 * 颜色由字典项的 tagType 决定，页面里不写 status === 1 ? 'success' : 'info' 这种判断——
 * 加一个状态值只需要改字典，不用回头找所有写过判断的地方。
 */
const props = withDefaults(
  defineProps<{
    code: string
    value: unknown
    /** 字典里查不到时的兜底文案 */
    fallback?: string
    size?: 'large' | 'default' | 'small'
    effect?: 'dark' | 'light' | 'plain'
  }>(),
  { fallback: '-', size: 'small', effect: 'light' }
)

const dictStore = useDictStore()

onMounted(() => dictStore.load(props.code))

const item = computed(() => dictStore.find(props.code, props.value))
</script>

<template>
  <el-tag v-if="item" :type="(item.tagType || 'info') as never" :size="size" :effect="effect" disable-transitions>
    {{ item.label }}
  </el-tag>
  <span v-else class="dict-tag-empty">{{ fallback }}</span>
</template>

<style scoped>
.dict-tag-empty {
  color: var(--el-text-color-placeholder);
}
</style>
