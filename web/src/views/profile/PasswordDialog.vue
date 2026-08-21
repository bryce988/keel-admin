<script setup lang="ts">
import { ref } from 'vue'
import type { FormRules } from 'element-plus'
import { changePassword } from '@/api/profile'
import { useSignOut } from '@/composables/useSignOut'
import type { FormShellInstance } from '@/components'
import { BizCode } from '@/constants/bizCode'

/**
 * 修改密码
 *
 * 两个入口共用：顶栏下拉、个人中心的安全设置。抽成组件是因为这里有三样
 * 容易写歪的东西——两次输入一致的联动校验、20005 的错误回填、
 * 以及成功后必须登出。复制一份到第二个入口，迟早有一份忘了最后那件事，
 * 而那会让用户带着一个已被服务端拉黑的 token 继续点，直到下一个请求才 401。
 *
 * 放在 views/profile/ 而不是 components/：components 是全局注册的通用件
 * （见 components/index.ts 的说明），这个是业务组件，按需 import。
 */
const dialogRef = ref<FormShellInstance | null>(null)
const signOut = useSignOut()

const rules: FormRules = {
  old_password: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, message: '密码长度不能少于 8 位', trigger: 'blur' }
  ],
  confirm_password: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      trigger: 'blur',
      validator: (_rule, value, callback) => {
        const form = (dialogRef.value as unknown as { form?: Record<string, any> })?.form
        callback(value && value !== form?.new_password ? new Error('两次输入的密码不一致') : undefined)
      }
    }
  ]
}

/** 原密码错误是 400 + 20005，映射到输入框上，用户不用自己找是哪一项错了 */
const errorFields = { [BizCode.OLD_PASSWORD_ERROR]: 'old_password' }

function submit(form: Record<string, any>) {
  return changePassword({ old_password: form.old_password, new_password: form.new_password })
}

function open() {
  dialogRef.value?.open({
    title: '修改密码',
    data: { old_password: '', new_password: '', confirm_password: '' }
  })
}

defineExpose({ open })
</script>

<template>
  <FormDialog
    ref="dialogRef"
    :submit="submit"
    :rules="rules"
    :error-fields="errorFields"
    label-width="80px"
    success-message="密码已修改，请重新登录"
    @success="signOut"
  >
    <template #default="{ form, errors }">
      <el-form-item label="原密码" prop="old_password" :error="errors.old_password">
        <el-input v-model="form.old_password" type="password" show-password autocomplete="off" />
      </el-form-item>
      <el-form-item label="新密码" prop="new_password" :error="errors.new_password">
        <el-input v-model="form.new_password" type="password" show-password autocomplete="off" />
      </el-form-item>
      <el-form-item label="确认密码" prop="confirm_password">
        <el-input v-model="form.confirm_password" type="password" show-password autocomplete="off" />
      </el-form-item>
    </template>
  </FormDialog>
</template>
