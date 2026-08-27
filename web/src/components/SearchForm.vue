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
    <el-form :model="form" inline label-width="72px" @submit.prevent="onSearch">
      <!--
        字段单独包一层：它们只在这个区域里换行，不会排到右边动作区的上方。
        包之前六个字段和按钮在同一个 flex 里一起换行，展开后「执行结果」正好
        落在「查询」的正上方——那一列看起来像是字段列，其实是动作列。
      -->
      <div class="fields">
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
      </div>

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
 * 整体分两块：左边字段区（内部按栅格折行），右边动作区（永远独占一列）。
 * 外层 `flex-wrap: nowrap` 是关键——字段和按钮如果在同一个换行流里，
 * 字段就会排到按钮正上方，那一列看着像字段列其实是动作列。
 *
 * 间距一律用 gap，不用 padding + margin 凑：原先是 `padding: 16px 16px 0`
 * 配 form-item 的 `margin-bottom: 16px`，换行时靠 margin 撑、不换行时靠它补底，
 * 机制和别的面板不一样，谁调一下 padding 就会错位。
 * 也别再加 `margin-bottom`：容器已经有 gap，两者叠加会让搜索栏与表格之间宽出一截。
 */
.search-form :deep(.el-form--inline) {
  display: flex;
  flex-wrap: nowrap;
  align-items: flex-start;
  gap: var(--keel-gap-lg);
}

.fields {
  /*
   * 等宽栅格，不是 flex 顺排
   *
   * 顺排时每个字段的宽度 = 自己 label 的字数 + 控件那一档的宽度，两者都不统一
   * （「模块」2 个字 vs 「TraceID」7 个字符；输入框 220 vs 下拉 160），
   * 于是第二行的字段跟第一行完全对不上，看着像没对齐的表格。
   *
   * auto-fill + 1fr：列宽由容器算，每格等宽 → 各行的 label 起点、控件起点、
   * 控件右边缘三条线都对齐。窄屏自动从 3 列降到 2 列、1 列。
   * 300px 是单格下限：它决定一行放几个。字段区约 934px 时正好 3 列——
   * 与收起状态默认显示 3 个字段刚好对上，收起时就是整齐的一行。
   * 再大（如 320）会掉成 2 列，每列 459px、控件被撑到 387px，空得发虚。
   */
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--keel-gap-lg);
  /* min-width:0 少了的话，栅格按内容撑开、不肯收缩，按钮会被挤出面板 */
  flex: 1;
  min-width: 0;
}

.search-form :deep(.el-form-item) {
  margin: 0;
}

/*
 * 字段撑满自己那一格，label 固定 72px
 *
 * 这里换掉了原先「按装什么分档」的做法（输入框 220 / 下拉 160 / 日期 150 / 日期范围 260）。
 * 那套宽度本身能传达一点信息——一看就知道哪个框装长文本、哪个装两个字的状态——
 * 但代价是没有任何两列对得齐，多行时尤其明显。对齐比这点信息量值钱。
 *
 * 72px 是按最长 label 量的：4 个汉字 56px、「TraceID」7 个字符 52px，留一点余量。
 * 超过 4 个字的 label 会被压缩，加字段时注意。
 */
.search-form :deep(.fields .el-form-item) {
  display: flex;
}

.search-form :deep(.fields .el-form-item__content) {
  flex: 1;
  min-width: 0;
}

.search-form :deep(.fields .el-input),
.search-form :deep(.fields .el-select),
.search-form :deep(.fields .el-date-editor) {
  width: 100%;
}

/*
 * 动作区：不参与字段的换行，永远独占右侧一列
 *
 * 之前按钮是跟着最后一个字段顺排的：操作日志收起时它在第一行三个字段之后，
 * 展开后掉到第二行——同一个页面点一下「展开」，按钮横向挪近 300px，眼睛得重新找。
 * 字段数不同的页面之间更是各在各的位置。
 *
 * `flex: none` 保证按钮不被压缩（字段区是 flex:1，会先让出空间）。
 * 宽度由内容决定：没有「展开」链接的页面（字段数不超过 collapseAt）这一列自然窄一些。
 */
.search-form :deep(.el-form-item.actions) {
  flex: none;
}
</style>
