<script setup lang="ts">
import { computed } from 'vue'

/**
 * 空状态（PROJECT.md §9.6）
 *
 * 四种场景各有固定文案与**必带动作**——「暂无数据」下面没有新建入口，
 * 用户就只能盯着它发呆。所以动作不是可选装饰，是这个组件存在的理由。
 *
 * 之前全仓十来处各写各的 el-empty：文案不一致、image-size 有 60/70/80/90
 * 四种、而且一个都没带动作。收敛成一个组件，顺便把「该给什么动作」
 * 变成调用时绕不过去的选择。
 *
 *   <EmptyState scene="empty" @action="onCreate" />
 *   <EmptyState scene="search" :keyword="query.keyword" @action="onReset" />
 *   <EmptyState scene="error" :code="err.code" @action="load" />
 *   <EmptyState description="该角色还没有成员" />        // 只改文案，不给动作
 */
const props = withDefaults(
  defineProps<{
    /** 无数据 / 无搜索结果 / 无权限 / 服务异常 */
    scene?: 'empty' | 'search' | 'forbidden' | 'error'
    /** 覆盖默认文案；只想换句话时用它，不必新增 scene */
    description?: string
    /** scene=search 时拼进文案：未找到匹配「xxx」的结果 */
    keyword?: string
    /** scene=error 时附上错误码，用户报障时能报出来 */
    code?: string | number
    /** 动作按钮文字；不传则用场景默认值 */
    actionText?: string
    /** 传 false 显式关掉动作按钮——「就是不该有动作」也是一种决定 */
    action?: boolean
    size?: number
  }>(),
  { scene: 'empty', action: true, size: 90 }
)

const emit = defineEmits<{ action: [] }>()

const PRESET = {
  empty:     { text: '暂无数据',           action: '新建' },
  search:    { text: '未找到匹配的结果',   action: '清空筛选' },
  forbidden: { text: '你没有访问该页面的权限', action: '申请权限' },
  error:     { text: '服务暂时不可用',     action: '重新加载' }
} as const

const text = computed(() => {
  if (props.description) return props.description

  const preset = PRESET[props.scene]
  if (props.scene === 'search' && props.keyword) {
    return `未找到匹配「${props.keyword}」的结果`
  }
  if (props.scene === 'error' && props.code) {
    return `${preset.text}（错误码 ${props.code}）`
  }

  return preset.text
})

const buttonText = computed(() => props.actionText || PRESET[props.scene].action)
</script>

<template>
  <el-empty :description="text" :image-size="size">
    <slot name="action">
      <el-button v-if="action" type="primary" @click="emit('action')">
        {{ buttonText }}
      </el-button>
    </slot>
  </el-empty>
</template>
