<script setup lang="ts">
import { ref } from 'vue'
import { Check } from '@element-plus/icons-vue'
import { useAppStore } from '@/stores/app'
import type { LayoutMode } from '@/stores/app'

/**
 * 界面设置
 *
 * 目前只有导航版式一项。做成抽屉而不是下拉，是因为版式要给缩略图才选得明白
 * ——两行文字说不清「一级在顶栏、二级在侧栏」是什么样，下拉里也塞不下图。
 *
 * ## 排布：一行一个，不是两列网格
 *
 * 原来是 2 列卡片：三个选项排下来，第二行只剩一个，右边空着一格，
 * 而且 150px 的卡宽让说明文字每条都要折成两三行。
 * 改成一行一个（缩略图在左、文字在右）之后：没有空位、说明一行放得下、
 * 缩略图反而能画大一点——三种版式的差别本来就全在那张图上。
 */
const appStore = useAppStore()
const visible = ref(false)

const options: Array<{ value: LayoutMode; label: string; desc: string }> = [
  { value: 'side', label: '经典布局', desc: '一级、二级都在左侧栏' },
  { value: 'mix', label: '混合布局', desc: '一级在顶栏，二级在左侧栏' },
  { value: 'columns', label: '分栏布局', desc: '一级在左窄条，二级在第二栏' }
]

function open() {
  visible.value = true
}

defineExpose({ open })
</script>

<template>
  <el-drawer v-model="visible" title="界面设置" size="320px">
    <div class="section-title">导航版式</div>

    <div class="layout-picker">
      <button
        v-for="opt in options"
        :key="opt.value"
        type="button"
        class="layout-option"
        :class="{ 'is-active': appStore.layout === opt.value }"
        @click="appStore.setLayout(opt.value)"
      >
        <!--
          缩略图用几个 div 拼，不用图片：跟着 EP 的颜色令牌走，
          深色模式下自动成立；换成 png 就得准备两套，还会跟主题色对不上。

          三块的语义固定：thumb-top 是顶栏、thumb-side 是侧栏、thumb-body 是
          分栏版式多出来的那条窄条。哪一块是主色由各版式自己定——
          主色标的是「一级菜单在哪」，这正是三种版式唯一的区别。
        -->
        <span class="thumb" :class="`thumb--${opt.value}`">
          <span class="thumb-top" />
          <span class="thumb-side" />
          <span class="thumb-body" />
        </span>

        <span class="opt-text">
          <span class="opt-label">{{ opt.label }}</span>
          <span class="opt-desc">{{ opt.desc }}</span>
        </span>

        <!-- 选中态除了描边再给一个对勾：只靠 1px 边框，扫一眼分不出选的是哪个 -->
        <el-icon class="opt-check"><Check /></el-icon>
      </button>
    </div>
  </el-drawer>
</template>

<style scoped>
.section-title {
  margin-bottom: 10px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.layout-picker {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.layout-option {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--el-border-color-lighter);
  /* 它是张卡片（装着缩略图与说明），走容器档 */
  border-radius: var(--keel-radius-lg);
  background: var(--el-bg-color);
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s;
}

.layout-option:hover {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-fill-color-lighter);
}

.layout-option.is-active {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.opt-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.opt-label {
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.layout-option.is-active .opt-label {
  color: var(--el-color-primary);
  font-weight: 500;
}

.opt-desc {
  font-size: 12px;
  line-height: 1.4;
  color: var(--el-text-color-secondary);
}

/* 对勾占位始终保留（用 visibility 而不是 v-if）：否则选中的那一行会比
   其余两行窄一截，三行的文字起点跟着错开 */
.opt-check {
  flex: none;
  font-size: 16px;
  color: var(--el-color-primary);
  visibility: hidden;
}

.layout-option.is-active .opt-check {
  visibility: visible;
}

/* ---------------- 版式缩略图 ---------------- */
.thumb {
  display: block;
  position: relative;
  width: 56px;
  height: 40px;
  flex: none;
  border-radius: 4px;
  /* 内容区用底色示意，不留空白：空白会让人以为那块是"没画完" */
  background: var(--el-fill-color);
  overflow: hidden;
}

.thumb-top,
.thumb-side,
.thumb-body {
  position: absolute;
}

/* 经典：侧栏通高（主色 = 一级在这儿），顶栏只占右侧 */
.thumb--side .thumb-side {
  inset: 0 auto 0 0;
  width: 34%;
  background: var(--el-color-primary);
}

.thumb--side .thumb-top {
  inset: 0 0 auto 34%;
  height: 9px;
  background: var(--el-color-primary-light-7);
}

/* 混合：顶栏通宽（主色 = 一级在顶栏），侧栏退到它下面 */
.thumb--mix .thumb-top {
  inset: 0 0 auto 0;
  height: 9px;
  background: var(--el-color-primary);
}

.thumb--mix .thumb-side {
  inset: 9px auto 0 0;
  width: 34%;
  background: var(--el-color-primary-light-7);
}

/*
 * 分栏：最左窄条（主色 = 一级在这儿）+ 第二栏（浅）+ 右上顶栏
 *
 * 窄条与第二栏的深浅差是这张图的全部信息量——两列同色的话，
 * 看起来就只是「侧栏比经典宽了一点」。
 */
.thumb--columns .thumb-body {
  inset: 0 auto 0 0;
  width: 20%;
  background: var(--el-color-primary);
}

.thumb--columns .thumb-side {
  inset: 0 auto 0 20%;
  width: 26%;
  background: var(--el-color-primary-light-7);
}

.thumb--columns .thumb-top {
  inset: 0 0 auto 46%;
  height: 9px;
  background: var(--el-color-primary-light-8);
}
</style>
