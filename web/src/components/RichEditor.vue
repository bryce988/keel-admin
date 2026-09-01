<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Placeholder } from '@tiptap/extensions'
import { ElMessageBox } from 'element-plus'

/**
 * 富文本编辑器
 *
 * 用法与 el-input 一致，v-model 绑的是 HTML 字符串：
 *   <RichEditor v-model="form.content" :min-height="240" />
 *
 * ## 选型
 *
 * tiptap（ProseMirror 内核）。国内后台里更常见的是 wangEditor，它自带整套中文
 * 工具栏、接进来几乎不用写 UI——但它最后一次发版是 2022 年 11 月，
 * 一个要长期跟着 Vue 与浏览器走的编辑器停更四年，是脚手架不该埋的雷。
 * tiptap 是无头的，代价就是下面这条工具栏得自己写；换来的是活跃维护、
 * 干净的 HTML 产出（没有一堆自定义 class 与内联样式）。
 *
 * ## 产出的 HTML 不可信
 *
 * 这里的白名单只决定「编辑器能打出什么」，不是安全边界——改一行 JS 或直接打
 * 接口就绕过去了。真正的净化在服务端写入时做（`support/Html.php`），
 * 两边的白名单要对齐：这边能打出、那边不放行的标签，表现是排好版保存后静默消失。
 */
const props = withDefaults(
  defineProps<{
    modelValue?: string
    placeholder?: string
    disabled?: boolean
    /** 编辑区最小高度（px），长公告用大一点 */
    minHeight?: number
  }>(),
  { modelValue: '', placeholder: '请输入正文', disabled: false, minHeight: 220 }
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      link: {
        openOnClick: false,
        // 编辑器自己也挡一道协议：白名单之外的 href 直接不成链接，
        // 让作者当场看到「这个链接没生效」，而不是保存后被服务端悄悄剥掉
        protocols: ['http', 'https', 'mailto']
      }
    }),
    // 占位文案由扩展渲染成 data-placeholder，样式在下面的 :deep 里
    Placeholder.configure({ placeholder: () => props.placeholder })
  ],
  onUpdate: ({ editor: e }) => {
    /*
     * 空文档要回空串，不能回 `<p></p>`
     *
     * tiptap 的空文档序列化出来是一个空段落，非空字符串会让「必填」校验通过，
     * 于是能存进一条正文只有一个空段落的公告——发出去别人点开是一片空白。
     */
    emit('update:modelValue', e.isEmpty ? '' : e.getHTML())
  }
})

/*
 * 外部改值时回灌
 *
 * 编辑抽屉/弹窗是复用的：同一个组件实例先后装两条公告，不回灌的话第二次打开
 * 显示的还是上一条的内容。比对当前 HTML 是为了跳过「自己 emit 出去又回来」的
 * 那一轮，否则每敲一个字都会重设文档，光标会跳到开头。
 */
watch(
  () => props.modelValue,
  (value) => {
    const current = editor.value?.getHTML() ?? ''
    if (!editor.value || value === current) return
    if (value === '' && editor.value.isEmpty) return

    editor.value.commands.setContent(value || '', { emitUpdate: false })
  }
)

watch(
  () => props.disabled,
  (disabled) => editor.value?.setEditable(!disabled)
)

onBeforeUnmount(() => editor.value?.destroy())

/** 工具栏：图标用文字/符号，不额外引图标包 */
const marks = [
  { key: 'bold', label: 'B', title: '加粗', style: 'font-weight:700' },
  { key: 'italic', label: 'I', title: '斜体', style: 'font-style:italic' },
  { key: 'underline', label: 'U', title: '下划线', style: 'text-decoration:underline' },
  { key: 'strike', label: 'S', title: '删除线', style: 'text-decoration:line-through' },
  { key: 'code', label: '<>', title: '行内代码', style: '' }
] as const

function toggleMark(key: (typeof marks)[number]['key']) {
  const chain = editor.value?.chain().focus()
  if (!chain) return

  ;({
    bold: () => chain.toggleBold().run(),
    italic: () => chain.toggleItalic().run(),
    underline: () => chain.toggleUnderline().run(),
    strike: () => chain.toggleStrike().run(),
    code: () => chain.toggleCode().run()
  })[key]()
}

async function setLink() {
  if (!editor.value) return

  const previous = editor.value.getAttributes('link').href ?? ''

  try {
    const { value } = await ElMessageBox.prompt('链接地址', '插入链接', {
      inputValue: previous,
      inputPlaceholder: 'https://',
      // 留空 = 取消链接，比再放一个「取消链接」按钮直白
      inputValidator: (v: string) => !v || /^(https?:|mailto:)/.test(v) || '只支持 http(s) 与 mailto'
    })

    const chain = editor.value.chain().focus().extendMarkRange('link')
    value ? chain.setLink({ href: value }).run() : chain.unsetLink().run()
  } catch {
    // 点了取消，什么都不做
  }
}
</script>

<template>
  <div class="rich-editor" :class="{ 'is-disabled': disabled }">
    <div v-if="!disabled && editor" class="toolbar">
      <button
        v-for="m in marks"
        :key="m.key"
        type="button"
        :title="m.title"
        :style="m.style"
        :class="{ 'is-active': editor.isActive(m.key) }"
        @click="toggleMark(m.key)"
      >
        {{ m.label }}
      </button>

      <span class="sep" />

      <button
        type="button"
        title="标题"
        :class="{ 'is-active': editor.isActive('heading', { level: 2 }) }"
        @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        H2
      </button>
      <button
        type="button"
        title="小标题"
        :class="{ 'is-active': editor.isActive('heading', { level: 3 }) }"
        @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
      >
        H3
      </button>

      <span class="sep" />

      <button
        type="button"
        title="无序列表"
        :class="{ 'is-active': editor.isActive('bulletList') }"
        @click="editor.chain().focus().toggleBulletList().run()"
      >
        • 列表
      </button>
      <button
        type="button"
        title="有序列表"
        :class="{ 'is-active': editor.isActive('orderedList') }"
        @click="editor.chain().focus().toggleOrderedList().run()"
      >
        1. 列表
      </button>
      <button
        type="button"
        title="引用"
        :class="{ 'is-active': editor.isActive('blockquote') }"
        @click="editor.chain().focus().toggleBlockquote().run()"
      >
        引用
      </button>

      <span class="sep" />

      <button
        type="button"
        title="链接"
        :class="{ 'is-active': editor.isActive('link') }"
        @click="setLink"
      >
        链接
      </button>
      <button type="button" title="分隔线" @click="editor.chain().focus().setHorizontalRule().run()">
        —
      </button>
      <button
        type="button"
        title="清除格式"
        @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"
      >
        清除
      </button>

      <span class="sep" />

      <button
        type="button"
        title="撤销"
        :disabled="!editor.can().undo()"
        @click="editor.chain().focus().undo().run()"
      >
        ↶
      </button>
      <button
        type="button"
        title="重做"
        :disabled="!editor.can().redo()"
        @click="editor.chain().focus().redo().run()"
      >
        ↷
      </button>
    </div>

    <EditorContent
      class="editor-body rich-content"
      :editor="editor"
      :style="{ minHeight: `${minHeight}px` }"
    />
  </div>
</template>

<style scoped>
.rich-editor {
  width: 100%;
  border: 1px solid var(--el-border-color);
  border-radius: var(--keel-radius);
  background: var(--el-fill-color-blank);
  overflow: hidden;
  transition: border-color 0.2s;
}

.rich-editor:focus-within {
  border-color: var(--el-color-primary);
}

.rich-editor.is-disabled {
  background: var(--el-fill-color-light);
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
  padding: 4px 6px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-lighter);
}

.toolbar button {
  min-width: 28px;
  height: 26px;
  padding: 0 6px;
  border: none;
  border-radius: 4px;
  background: transparent;
  font-family: inherit;
  font-size: 13px;
  line-height: 1;
  color: var(--el-text-color-regular);
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.toolbar button:hover:not(:disabled) {
  background: var(--el-fill-color-dark);
}

.toolbar button:disabled {
  color: var(--el-text-color-disabled);
  cursor: not-allowed;
}

.toolbar button.is-active {
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.sep {
  width: 1px;
  height: 16px;
  margin: 0 4px;
  background: var(--el-border-color);
}

.editor-body {
  padding: 8px 12px;
  font-size: 14px;
  line-height: 1.7;
  color: var(--el-text-color-primary);
}

/* ProseMirror 的可编辑区是内层 div，撑满高度才能整块点进去聚焦，
   否则只有正好点在文字上才进得了编辑态 */
.editor-body :deep(.tiptap) {
  min-height: inherit;
  outline: none;
}

.editor-body :deep(.tiptap p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  float: left;
  height: 0;
  color: var(--el-text-color-placeholder);
  pointer-events: none;
}
</style>
