<script setup lang="ts">
/**
 * 品牌标记
 *
 * 图形是船体肋骨的横剖加一根贯穿的龙骨主梁——龙骨是全船肋骨唯一的附着点，
 * 这个脚手架对业务代码就是这个关系。
 *
 * 路径与 public/favicon.svg、apple-touch-icon.png、favicon.ico 是同一份形状，
 * 改动要四处一起改（那三个是浏览器直接加载的静态资产，读不到组件）。
 *
 * 坐标按 32 格排满，含笔宽的外框四周只留 2——标记在侧栏折叠态只有 28px，
 * 留白吃掉的是可辨识度。
 */
const props = withDefaults(
  defineProps<{
    /** 标记的边长（px），字标字号按它换算，不用两处各调一次 */
    size?: number
    /** 侧栏折叠时关掉字标：64px 宽里放不下标记加文字 */
    text?: boolean
    /** fork 出去改产品名时只动这里 */
    name?: string
  }>(),
  { size: 28, text: true, name: 'Keel' }
)
</script>

<template>
  <!--
    role + aria-label 挂在外层：无障碍树会忽略 role="img" 元素的内部内容，
    标记和字标就不会被读成两遍「Keel」。
  -->
  <span class="brand-logo" role="img" :aria-label="props.name">
    <svg class="mark" viewBox="0 0 32 32" :width="size" :height="size">
      <rect class="mark-bg" width="32" height="32" rx="7.5" />
      <g class="mark-line" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 5.2 C 4.6 17.1, 9.7 25, 16 25.8 C 22.3 25, 27.4 17.1, 28 5.2" />
        <path d="M16 3.5 V 28.5" />
      </g>
    </svg>
    <b v-if="text" class="brand-text" :style="{ fontSize: `${size * 0.64}px` }">{{ props.name }}</b>
  </span>
</template>

<style scoped>
.brand-logo {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  line-height: 1;
}
.mark {
  display: block;
  flex: none;
}
.mark-bg {
  fill: var(--el-color-primary);
}
.mark-line {
  /* 这一处白色是图形内部的对比色，不是主题色：底板永远是主色，
     线条就永远得是白的。跟着 --el-text-color-* 走的话深色模式下
     线条会变浅灰，压在蓝底上直接糊掉。 */
  stroke: #fff;
}
.brand-text {
  font-weight: 600;
  letter-spacing: 0.2px;
  color: var(--el-text-color-primary);
  white-space: nowrap;
}
</style>
