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
import { computed } from 'vue'
import { useAppStore } from '@/stores/app'

const props = withDefaults(
  defineProps<{
    /** 标记的边长（px） */
    size?: number
    /** 侧栏折叠时关掉字标：64px 宽里放不下标记加文字 */
    text?: boolean
    /** 覆盖产品名。不传就用系统参数 `sys.name`（后台「参数配置 / 基础设置」里改） */
    name?: string
  }>(),
  { size: 28, text: true }
)

/*
 * 产品名的来源：props > 系统参数 > 兜底
 *
 * 原来是 prop 的默认值写死 'Keel'，注释还写着「fork 出去改产品名时只动这里」——
 * 而后端其实早就有 `sys.name` 参数和免登录的下发接口，只是前端从没调过。
 * 结果是在「参数配置」里改系统名称，保存成功、列表显示新值，界面纹丝不动。
 * 现在默认走参数，prop 保留给确实要写死的场合。
 */
const appStore = useAppStore()
const displayName = computed(() => props.name || appStore.site.name)
</script>

<template>
  <!--
    role + aria-label 挂在外层：无障碍树会忽略 role="img" 元素的内部内容，
    标记和字标就不会被读成两遍「Keel」。
  -->
  <span class="brand-logo" role="img" :aria-label="displayName">
    <svg class="mark" viewBox="0 0 32 32" :width="size" :height="size">
      <rect class="mark-bg" width="32" height="32" rx="7.5" />
      <g class="mark-line" fill="none" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 5.2 C 4.6 17.1, 9.7 25, 16 25.8 C 22.3 25, 27.4 17.1, 28 5.2" />
        <path d="M16 3.5 V 28.5" />
      </g>
    </svg>
    <b v-if="text" class="brand-text">{{ displayName }}</b>
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
/*
 * 品牌字号定死 20px，不跟着 size 走
 *
 * 原来是 `size * 0.64`，size 默认 28 → **17.92px**。小数字号不是"更精确"：
 * 浏览器按亚像素栅格化，同一个字重在 17.92 和 18 下的笔画粗细肉眼可辨，
 * 而全站别处都是整数，它就成了唯一一个"看着不太一样"的地方。
 * logo 是版式里最大的那个字，本来就该单独定值，不该由图标尺寸推导出来。
 */
.brand-text {
  font-size: 20px;
  font-weight: 600;
  letter-spacing: 0.2px;
  color: var(--el-text-color-primary);
  white-space: nowrap;
}
</style>
