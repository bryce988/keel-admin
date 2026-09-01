<script setup lang="ts">
import { ref } from 'vue'
import { useAppStore } from '@/stores/app'
import type { LayoutMode } from '@/stores/app'

/**
 * 界面设置
 *
 * 目前只有导航版式一项。做成抽屉而不是下拉，是因为版式要给缩略图才选得明白
 * ——两行文字说不清「一级在顶栏、二级在侧栏」是什么样，下拉里也塞不下图。
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
          深色模式下自动成立；换成 png 就得准备两套，还会跟主题色对不上
        -->
        <span class="thumb" :class="`thumb--${opt.value}`">
          <span class="thumb-top" />
          <span class="thumb-side" />
          <span class="thumb-body" />
        </span>
        <span class="opt-label">{{ opt.label }}</span>
        <span class="opt-desc">{{ opt.desc }}</span>
      </button>
    </div>

    <el-alert type="info" :closable="false" show-icon class="tip">
      版式只影响导航的排布，菜单与权限仍由后端下发，切换不会改变你能看到的内容。
    </el-alert>
  </el-drawer>
</template>

<style scoped>
.section-title {
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.layout-picker {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--keel-gap-lg);
}

.layout-option {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  padding: 10px;
  border: 1px solid var(--el-border-color);
  /* 它是张卡片（装着缩略图与说明），走容器档 */
  border-radius: var(--keel-radius-lg);
  background: var(--el-bg-color);
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.layout-option:hover {
  border-color: var(--el-color-primary-light-5);
}

.layout-option.is-active {
  border-color: var(--el-color-primary);
  /* 用内阴影加粗边框，不用 border-width：改宽度会让卡片尺寸跳一下 */
  box-shadow: inset 0 0 0 1px var(--el-color-primary);
}

.opt-label {
  font-size: 13px;
  color: var(--el-text-color-primary);
}

.opt-desc {
  font-size: 12px;
  line-height: 1.4;
  color: var(--el-text-color-secondary);
}

/* ---------------- 版式缩略图 ---------------- */
.thumb {
  display: block;
  position: relative;
  width: 100%;
  height: 56px;
  border-radius: 3px;
  background: var(--el-fill-color-lighter);
  overflow: hidden;
}

.thumb-top,
.thumb-side,
.thumb-body {
  position: absolute;
}

/* 经典：侧栏通高，顶栏只占右侧 */
.thumb--side .thumb-side {
  inset: 0 auto 0 0;
  width: 30%;
  background: var(--el-color-primary);
}

.thumb--side .thumb-top {
  inset: 0 0 auto 30%;
  height: 12px;
  background: var(--el-color-primary-light-5);
}

/* 混合：顶栏通宽，侧栏在它下面 */
.thumb--mix .thumb-top {
  inset: 0 0 auto 0;
  height: 12px;
  background: var(--el-color-primary);
}

.thumb--mix .thumb-side {
  inset: 12px auto 0 0;
  width: 30%;
  background: var(--el-color-primary-light-5);
}

/*
 * 分栏：最左窄条（深）+ 第二栏（浅）+ 右上顶栏
 *
 * 窄条与第二栏的深浅差是这张图的全部信息量——两列同色的话，
 * 缩略图看起来就只是「侧栏比经典宽了一点」。
 */
.thumb--columns .thumb-body {
  inset: 0 auto 0 0;
  width: 18%;
  background: var(--el-color-primary);
}

.thumb--columns .thumb-side {
  inset: 0 auto 0 18%;
  width: 26%;
  background: var(--el-color-primary-light-5);
}

.thumb--columns .thumb-top {
  inset: 0 0 auto 44%;
  height: 12px;
  background: var(--el-color-primary-light-7);
}

.tip {
  margin-top: var(--keel-gap-lg);
}

.tip :deep(.el-alert__content) {
  font-size: 12px;
  line-height: 1.5;
}
</style>
