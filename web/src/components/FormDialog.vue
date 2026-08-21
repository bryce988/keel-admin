<script lang="ts">
/**
 * 表单弹窗
 *
 * 与 {@link FormDrawer} 是同一套壳（`useFormShell`），只是容器换成居中弹窗。
 * 用它的场景：字段少、动作单一、做完就想回到原来的位置——改密码、换绑手机
 * 这一类。全高抽屉给两三个输入框用，空得晃眼。
 *
 * 列表页的新增/编辑仍然用抽屉：那里字段多，而且抽屉不盖住列表，
 * 改完能立刻看到那一行（PROJECT.md §9.4）。
 *
 *   <FormDialog ref="dialog" :submit="save" :rules="rules" size="460px">
 *     <template #default="{ form, errors }">
 *       <el-form-item label="原密码" prop="old_password" :error="errors.old_password">
 *         <el-input v-model="form.old_password" type="password" show-password />
 *       </el-form-item>
 *     </template>
 *   </FormDialog>
 */
export default { name: 'FormDialog' }
</script>

<script setup lang="ts">
import { useFormShell, type FormShellProps } from '@/composables/useFormShell'

const props = withDefaults(defineProps<FormShellProps>(), {
  size: '460px',
  labelWidth: '96px',
  confirmText: '确 定'
})

const emit = defineEmits<{ success: [result: unknown]; closed: [] }>()

const { visible, title, loading, formRef, form, errors, readonly, open, close, onConfirm, onClosed } =
  useFormShell(props, emit)

defineExpose({ open, close, form })
</script>

<template>
  <el-dialog
    v-model="visible"
    :title="title"
    :width="size"
    destroy-on-close
    :close-on-click-modal="false"
    align-center
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
  </el-dialog>
</template>

<style scoped>
.shell-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
