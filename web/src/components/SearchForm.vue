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
  <div class="panel search-form">
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
/*
 * 面板外观走全局 .panel（styles/index.css），这里只管排布。
 *
 * 原先是 `padding: 16px 16px 0` + form-item 的 `margin-bottom: 16px` 凑出下边距——
 * 换行时靠 margin 撑、不换行时靠它补底，机制和别的面板不一样，
 * 谁调一下 padding 就会错位。改成 flex + gap：间距只有一个来源。
 *
 * 也去掉了原来的 `margin-bottom: 12px`：容器已经有 gap，
 * 两者叠加会让搜索栏与表格之间比别处宽出一截。
 */
.search-form :deep(.el-form--inline) {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: var(--keel-gap-lg);
}

.search-form :deep(.el-form-item) {
  margin: 0;
}

/*
 * 宽度按装什么分档，不是一刀切
 *
 * 「关键词」（占位符是「操作人 / 描述 / 对象」这种长串）和「状态」（选项只有两个字）
 * 如果一样宽，一排看下来就是几个等宽空框，宽度本身不传达任何信息。
 *
 * 下面的数字是按 **14px** 重新量的（全局尺寸从 small 改成 default 之后，
 * 原来那套 12px 的测量值全部偏小，200px 的输入框装不下最长的占位符）：
 *   最长占位符「请输入操作人 / 描述 / 对象」165px + 左右内边距 22 + 清除图标 25 = 212 → 取 220
 *   最长下拉占位「请选择数据范围」98px + 22 + 箭头 25 = 145 → 取 160
 *   单个日期「2026-08-21」74px + 前缀图标与内边距 ≈ 121 → 取 150 不变
 *   日期范围两格 74×2 + 分隔符与图标 → 240 在 14px 下偏挤，取 260
 */
.search-form :deep(.el-input) {
  width: 220px;
}

.search-form :deep(.el-select) {
  width: 160px;
}

.search-form :deep(.el-date-editor:not(.el-date-editor--daterange)) {
  width: 150px;
}

/* 放最后：日期范围要盖住上面 .el-input 那条同权重的规则 */
.search-form :deep(.el-date-editor--daterange) {
  width: 260px;
}

.actions {
  margin-right: 0 !important;
}
</style>
