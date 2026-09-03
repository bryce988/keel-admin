<script setup lang="ts">
import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Download, UploadFilled } from '@element-plus/icons-vue'
import type { BatchOutcome } from '@/types/api'

/**
 * Excel 导入弹窗
 *
 * ## 为什么要有这个弹窗，而不是点一下直接弹文件选择器
 *
 * 原先是工具栏上一个「导入」按钮包着 `el-upload`，点了直接进系统文件选择器，
 * 旁边再单放一个「下载模板」文字链接。三个问题：
 *
 * 1. **模板和导入被拆开了**。模板只有在要导入时才需要，却长期占着工具栏一个位置；
 *    而真正要导入的人，点「导入」那一刻并不会被提醒「你得先下模板」——
 *    于是拿自己整理的表格去传，失败一堆行，回头才发现有模板这回事
 * 2. **没有任何前置说明**。支持什么格式、能传几个、多大，全都要等失败了才知道
 * 3. **失败明细弹在另一个弹窗里**，看完一关，想重传还得再走一遍工具栏
 *
 * 现在合成一个弹窗：模板下载 → 选文件 → 结果就地展示，失败时不关窗，可直接重传。
 *
 * ## 用法
 *
 *   <ImportDialog ref="importRef" title="导入用户" accept=".xlsx,.csv"
 *                 :download-template="onDownloadTemplate" :upload="importUsers"
 *                 @success="tableRef?.refresh()" />
 *   importRef.value?.open()
 */
const props = withDefaults(
  defineProps<{
    title?: string
    /** 接受的扩展名，同时用于 input 的 accept 与前端预校验 */
    accept?: string
    /** 单文件大小上限（MB）。超了直接拦下，不浪费一次上传 */
    maxSize?: number
    /** 下载模板，通常是 utils/request 的 download() */
    downloadTemplate?: () => Promise<unknown> | unknown
    /** 真正的上传动作，返回逐行结果 */
    upload: (file: File) => Promise<BatchOutcome>
  }>(),
  { title: '导入数据', accept: '.xlsx,.csv', maxSize: 10 }
)

const emit = defineEmits<{ success: [outcome: BatchOutcome] }>()

const visible = ref(false)
const uploading = ref(false)
const downloading = ref(false)
/** 上一次导入的结果；有失败时留在弹窗里给人看，成功则直接关窗 */
const outcome = ref<BatchOutcome | null>(null)

const extList = computed(() =>
  props.accept
    .split(',')
    .map((e) => e.trim())
    .filter(Boolean)
)

function open() {
  outcome.value = null
  visible.value = true
}

async function onDownload() {
  if (!props.downloadTemplate) return
  downloading.value = true
  try {
    await props.downloadTemplate()
  } finally {
    downloading.value = false
  }
}

/**
 * 前端先拦一道
 *
 * 扩展名与大小在这里判，是为了给出**能照着改**的话。交给后端的话：
 * 扩展名错会得到一句后端文案，而超大文件根本到不了后端——
 * nginx 的 `client_max_body_size` 会直接 413，前端只能显示一句没头没尾的「请求失败」。
 */
function validate(file: File): string {
  const ext = '.' + (file.name.split('.').pop() ?? '').toLowerCase()
  if (!extList.value.includes(ext)) {
    return `只支持 ${extList.value.join(' / ')}，选中的是 ${ext || '无扩展名文件'}`
  }
  if (file.size > props.maxSize * 1024 * 1024) {
    return `文件 ${(file.size / 1024 / 1024).toFixed(1)}MB，超过 ${props.maxSize}MB 上限`
  }
  return ''
}

/** 接管 el-upload 的请求：走各页自己的 api 函数（带 token 与统一错误处理） */
async function onRequest(options: { file: File }) {
  const error = validate(options.file)
  if (error) {
    ElMessage.warning(error)
    return
  }

  uploading.value = true
  outcome.value = null
  try {
    const result = await props.upload(options.file)
    outcome.value = result
    emit('success', result)

    // 全成功就没什么好看的，关窗；有失败则留在原地，让人对着行号去改表格
    if (result.fail_count === 0) {
      ElMessage.success(`导入成功 ${result.success_count} 条`)
      visible.value = false
    }
  } finally {
    uploading.value = false
  }
}

defineExpose({ open })
</script>

<template>
  <el-dialog v-model="visible" :title="title" width="520px" append-to-body>
    <!-- 第一步：模板。放在上传区之前，顺序本身就是操作顺序 -->
    <section v-if="downloadTemplate" class="step">
      <div class="step-text">
        <div class="step-title">模板下载</div>
        <div class="step-desc">请先用标准模板整理数据，列名与顺序不要改动</div>
      </div>
      <!--
        这里是弹窗里唯一带色的元素，用的是系统主色。
        工具栏的导入/导出保持中性（颜色在这套系统里有含义：danger = 破坏性、
        primary = 该做的那件事），而「先下模板」正是打开这个弹窗后该做的第一件事。
      -->
      <el-button type="primary" plain :icon="Download" :loading="downloading" @click="onDownload">
        下载模板
      </el-button>
    </section>

    <!-- 第二步：上传 -->
    <section class="step step--block">
      <div class="step-title">选择文件</div>
      <div class="step-desc">
        支持 {{ extList.join(' / ') }}，单次一个文件，最大 {{ maxSize }}MB
      </div>

      <el-upload
        drag
        :show-file-list="false"
        :http-request="onRequest"
        :accept="accept"
        :disabled="uploading"
        class="dropzone"
      >
        <el-icon class="dropzone-icon"><UploadFilled /></el-icon>
        <div class="dropzone-text">
          {{ uploading ? '正在导入…' : '将文件拖到此处，或点击选择' }}
        </div>
      </el-upload>
    </section>

    <!--
      结果就地展示

      只在**有失败**时出现：全成功已经关窗了。逐行列出原因而不是笼统一句
      「部分失败」——用户手上只有那个 Excel，得知道回去改哪一行。
    -->
    <section v-if="outcome && outcome.fail_count > 0" class="result">
      <el-alert type="warning" :closable="false" show-icon>
        成功 {{ outcome.success_count }} 条，失败 {{ outcome.fail_count }} 条。
        修正后可直接重新上传，无需关闭。
      </el-alert>
      <ul class="fail-list">
        <li v-for="(item, i) in outcome.failed" :key="i">{{ item.reason }}</li>
      </ul>
    </section>
  </el-dialog>
</template>

<style scoped>
.step {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--keel-gap);
  padding: var(--keel-gap);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: var(--keel-radius-lg);
}

.step + .step {
  margin-top: var(--keel-gap-lg);
}

/* 上传那一步是上下结构，不是左右 */
.step--block {
  display: block;
}

.step-title {
  font-size: 14px;
  color: var(--el-text-color-primary);
}

.step-desc {
  margin-top: 2px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--el-text-color-secondary);
}

.dropzone {
  margin-top: var(--keel-gap);
}

/*
 * EP 的拖拽区默认 180px 高，这里只放一个图标加一行字，
 * 压到 120px；再高就会把「下载模板」挤出首屏，而那一步恰恰要先看到
 */
.dropzone :deep(.el-upload-dragger) {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 120px;
  padding: 0;
  border-radius: var(--keel-radius-lg);
}

.dropzone-icon {
  font-size: 36px;
  color: var(--el-text-color-placeholder);
}

.dropzone-text {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.result {
  margin-top: var(--keel-gap-lg);
}

.fail-list {
  max-height: 180px;
  margin: var(--keel-gap) 0 0;
  padding-left: 18px;
  overflow-y: auto;
  font-size: 12px;
  line-height: 1.8;
  color: var(--el-text-color-regular);
}
</style>
