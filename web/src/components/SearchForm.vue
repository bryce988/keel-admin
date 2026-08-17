<script setup lang="ts">
import { computed, ref } from 'vue'
import { Refresh, Search, ArrowDown, ArrowUp } from '@element-plus/icons-vue'
import DictSelect from './DictSelect.vue'

/**
 * 列表页筛选栏
 *
 * 用配置描述字段而不是每个页面手写一遍 el-form-item：
 * 七个系统管理页面的筛选栏结构完全一致，重复写只会让它们慢慢长歪。
 *
 *   <SearchForm v-model="query" :fields="fields" @search="load" />
 */
export interface SearchField {
  prop: string
  label: string
  /** input 文本 · dict 字典下拉 · select 自定义下拉 · date 日期 · daterange 日期区间 */
  type?: 'input' | 'dict' | 'select' | 'date' | 'daterange'
  placeholder?: string
  /** type=dict 时的字典编码 */
  dict?: string
  /** 字典值转数字 */
  numeric?: boolean
  /** type=select 时的选项 */
  options?: Array<{ label: string; value: string | number }>
}

const props = withDefaults(
  defineProps<{
    modelValue: Record<string, unknown>
    fields: SearchField[]
    /** 超过这个数量时折叠，点「展开」显示全部 */
    collapseAt?: number
    loading?: boolean
  }>(),
  { collapseAt: 3, loading: false }
)

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  search: []
  reset: []
}>()

const collapsed = ref(true)

const collapsible = computed(() => props.fields.length > props.collapseAt)
const visibleFields = computed(() =>
  collapsible.value && collapsed.value ? props.fields.slice(0, props.collapseAt) : props.fields
)

const form = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

function onSearch() {
  emit('search')
}

/** 重置只清筛选值，不动分页组件——分页由 ProTable 自己回到第 1 页 */
function onReset() {
  const cleared: Record<string, unknown> = {}
  props.fields.forEach((field) => {
    cleared[field.prop] = field.type === 'daterange' ? [] : ''
  })
  emit('update:modelValue', cleared)
  emit('reset')
}
</script>

<template>
  <div class="search-form">
    <el-form :model="form" inline @submit.prevent="onSearch">
      <el-form-item v-for="field in visibleFields" :key="field.prop" :label="field.label">
        <el-input
          v-if="!field.type || field.type === 'input'"
          v-model="form[field.prop] as string"
          :placeholder="field.placeholder || `请输入${field.label}`"
          clearable
          @keyup.enter="onSearch"
        />

        <DictSelect
          v-else-if="field.type === 'dict'"
          v-model="form[field.prop] as string"
          :code="field.dict!"
          :numeric="field.numeric"
          :placeholder="field.placeholder || `请选择${field.label}`"
        />

        <el-select
          v-else-if="field.type === 'select'"
          v-model="form[field.prop] as string"
          :placeholder="field.placeholder || `请选择${field.label}`"
          clearable
        >
          <el-option
            v-for="opt in field.options ?? []"
            :key="String(opt.value)"
            :label="opt.label"
            :value="opt.value"
          />
        </el-select>

        <el-date-picker
          v-else-if="field.type === 'date'"
          v-model="form[field.prop] as string"
          type="date"
          value-format="YYYY-MM-DD"
          :placeholder="field.placeholder || '选择日期'"
        />

        <el-date-picker
          v-else-if="field.type === 'daterange'"
          v-model="form[field.prop] as string[]"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          unlink-panels
        />
      </el-form-item>

      <el-form-item class="actions">
        <el-button type="primary" :icon="Search" :loading="loading" @click="onSearch">查询</el-button>
        <el-button :icon="Refresh" @click="onReset">重置</el-button>
        <el-button
          v-if="collapsible"
          link
          type="primary"
          :icon="collapsed ? ArrowDown : ArrowUp"
          @click="collapsed = !collapsed"
        >
          {{ collapsed ? '展开' : '收起' }}
        </el-button>
      </el-form-item>
    </el-form>
  </div>
</template>

<style scoped>
.search-form {
  padding: 16px 16px 0;
  margin-bottom: 12px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: var(--el-border-radius-base);
}

.search-form :deep(.el-form-item) {
  margin-right: 16px;
  margin-bottom: 16px;
}

/* 输入控件统一宽度，避免每个筛选项宽度不一显得参差 */
.search-form :deep(.el-input),
.search-form :deep(.el-select) {
  width: 200px;
}

.search-form :deep(.el-date-editor--daterange) {
  width: 240px;
}

.actions {
  margin-right: 0 !important;
}
</style>
