<script setup lang="ts">
import { ref } from 'vue'
import type { FormRules } from 'element-plus'
import { changePhone } from '@/api/profile'
import type { FormDrawerInstance } from '@/components'

/**
 * 换绑手机号
 *
 * 用当前密码验证身份，不发短信——Keel 不含业务逻辑，绑死某家短信服务商
 * 等于替使用者做选型（docs/api.md §11）。要接短信的项目，把这里的密码输入
 * 换成验证码输入、服务端 `ProfileService::changePhone()` 里换掉那次
 * `password_verify` 即可，其余不动。
 */
const emit = defineEmits<{ saved: [] }>()

const drawerRef = ref<FormDrawerInstance | null>(null)

const rules: FormRules = {
  phone: [
    { required: true, message: '请输入手机号', trigger: 'blur' },
    { pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确', trigger: 'blur' }
  ],
  password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }]
}

/**
 * 20005 是「密码错误」、20106 是「号码被占用」
 *
 * 两个都是 4xx + 单条 message，没有 details，所以要靠 errorFields
 * 落到对应输入框上——不映射的话用户只看到顶部一句红字，得自己猜是哪一栏错了
 */
const errorFields = { 20005: 'password', 20106: 'phone' }

function submit(form: Record<string, any>) {
  return changePhone({ phone: form.phone, password: form.password })
}

function open() {
  drawerRef.value?.open({ title: '换绑手机号', data: { phone: '', password: '' } })
}

defineExpose({ open })
</script>

<template>
  <FormDrawer
    ref="drawerRef"
    :submit="submit"
    :rules="rules"
    :error-fields="errorFields"
    size="420px"
    label-width="90px"
    success-message="手机号已更新"
    @success="emit('saved')"
  >
    <template #default="{ form, errors }">
      <el-form-item label="新手机号" prop="phone" :error="errors.phone">
        <el-input v-model="form.phone" maxlength="11" placeholder="请输入新的手机号" />
      </el-form-item>
      <el-form-item label="当前密码" prop="password" :error="errors.password">
        <el-input v-model="form.password" type="password" show-password autocomplete="off" />
      </el-form-item>
      <el-alert type="info" :closable="false" show-icon>
        换绑后下次登录仍使用账号密码，手机号仅用于身份标识与找回。
      </el-alert>
    </template>
  </FormDrawer>
</template>
