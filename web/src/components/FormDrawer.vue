<script lang="ts">
/**
 * 表单抽屉
 *
 * 新增、编辑、详情三种场景统一走它。用抽屉而不是弹窗：
 * 后台表单字段普遍偏多，弹窗撑不下就得在内部再套一层滚动，
 * 而抽屉天然是整列高度，长表单不用二次滚动，也不会把列表整个盖住。
 *
 * 字段少、动作单一的小表单（改密码、换绑手机）用 {@link FormDialog}，
 * 两者共用 `useFormShell` 里那套壳逻辑，只有容器不同（PROJECT.md §9.4）。
 *
 *   <FormDrawer ref="drawer" :submit="save" :rules="rules" @success="tableRef?.refresh()">
 *     <template #default="{ form, errors, readonly }">
 *       <el-form-item label="账号" prop="username" :error="errors.username">
 *         <el-input v-model="form.username" :disabled="readonly" />
 *       </el-form-item>
 *     </template>
 *   </FormDrawer>
 *
 *   drawer.value.open({ title: '新增用户' })                        // 新增
 *   drawer.value.open({ title: '编辑用户', data: row })              // 编辑
 *   drawer.value.open({ title: '岗位详情', data: row, mode: 'view' }) // 详情（只读）
 */
export default { name: 'FormDrawer' }
</script>

<script setup lang="ts" generic="T extends object = Record<string, unknown>">
import { useFormShell, type FormShellProps } from '@/composables/useFormShell'

const props = withDefaults(defineProps<FormShellProps<T>>(), {
  size: '560px',
  labelWidth: '96px',
  confirmText: '确 定'
})

const emit = defineEmits<{ success: [result: unknown]; closed: [] }>()

const { visible, title, loading, formRef, form, errors, readonly, open, close, onConfirm, onClosed } =
  useFormShell<T>(props, emit)

defineExpose({ open, close, form })
</script>

<template>
  <el-drawer
    v-model="visible"
    :title="title"
    :size="size"
    direction="rtl"
    destroy-on-close
    :close-on-click-modal="false"
    @closed="onClosed"
  >
    <el-form
      ref="formRef"
      :model="form"
      :rules="readonly ? undefined : rules"
      :label-width="labelWidth"
      :disabled="readonly"
      @submit.prevent="onConfirm"
    >
      <slot :form="form" :errors="errors" :readonly="readonly" />
    </el-form>

    <template #footer>
      <div class="shell-footer">
        <el-button @click="close">{{ readonly ? '关 闭' : '取 消' }}</el-button>
        <el-button v-if="!readonly" type="primary" :loading="loading" @click="onConfirm">
          {{ confirmText }}
        </el-button>
      </div>
    </template>
  </el-drawer>
</template>

<style scoped>
.shell-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
