<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import BrandLogo from '@/components/BrandLogo.vue'
import request, { BizError } from '@/utils/request'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()

const formRef = ref<FormInstance>()
const loading = ref(false)
const captchaImage = ref('')

// form 直接作为登录接口的请求体，所以键名用 snake_case
const form = reactive({
  username: 'admin',
  password: 'admin123',
  captcha_key: '',
  captcha_code: ''
})

const rules: FormRules = {
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
  captcha_code: [{ required: true, message: '请输入验证码', trigger: 'blur' }]
}

async function loadCaptcha() {
  const data = await request.get<unknown, { captcha_key: string; captcha_image: string }>(
    '/admin/auth/captcha'
  )
  form.captcha_key = data.captcha_key
  form.captcha_code = ''
  captchaImage.value = data.captcha_image
}

async function onSubmit() {
  if (!(await formRef.value?.validate().catch(() => false))) return

  loading.value = true
  try {
    const res = await userStore.login({ ...form })
    await userStore.fetchProfile()

    if (res.must_change_password) {
      ElMessage.warning('您还未修改过初始密码，建议尽快修改')
    }
    ElMessage.success('登录成功')
    // 不写死 /dashboard：落地页由守卫按该账号的菜单决定，
    // 否则没有概览权限的账号一登录就撞 404
    router.replace((route.query.redirect as string) || '/')
  } catch (e) {
    // 422 的字段级错误由拦截器放行到这里，回填到表单
    if (e instanceof BizError) {
      if (e.status === 422 && e.details) {
        const first = Object.values(e.details)[0]?.[0]
        ElMessage.error(first || e.message)
      } else if (e.status === 401) {
        ElMessage.error(e.message)
      }
    }
    loadCaptcha()
  } finally {
    loading.value = false
  }
}

onMounted(loadCaptcha)
</script>

<template>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-head">
        <BrandLogo :size="40" />
        <p>多端后台系统的底座</p>
      </div>

      <el-form ref="formRef" :model="form" :rules="rules" size="large" @keyup.enter="onSubmit">
        <el-form-item prop="username">
          <el-input v-model="form.username" placeholder="账号" clearable />
        </el-form-item>

        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" placeholder="密码" show-password />
        </el-form-item>

        <el-form-item prop="captcha_code">
          <div class="captcha-row">
            <el-input v-model="form.captcha_code" placeholder="验证码" maxlength="4" />
            <img
              v-if="captchaImage"
              :src="captchaImage"
              class="captcha-img"
              title="点击刷新"
              alt="验证码"
              @click="loadCaptcha"
            />
          </div>
        </el-form-item>

        <el-button type="primary" class="login-btn" :loading="loading" @click="onSubmit">
          登 录
        </el-button>
      </el-form>

      <p class="login-tip">演示环境默认账号 admin / admin123</p>
    </div>

    <div class="login-foot">Keel v1.0.0 · MIT License</div>
  </div>
</template>

<style scoped>
.login-wrap {
  position: fixed;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
  background:
    radial-gradient(1000px 500px at 50% -10%, var(--el-color-primary-light-9), transparent 70%),
    var(--el-bg-color-page, #f2f3f5);
}
.login-card {
  width: min(400px, 92vw);
  padding: 32px 36px 28px;
  background: var(--el-bg-color, #fff);
  border: 1px solid var(--el-border-color-lighter, #ebeef5);
  border-radius: 4px;
  box-shadow: var(--el-box-shadow-light);
}
/* 竖排 flex 而不是 text-align：标记是 inline-flex，按行内元素排会带上
   基线下方的空隙，副标题跟它的间距就不是这里写的 6px 了 */
.login-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 24px;
}
.login-head p {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.captcha-row {
  display: flex;
  gap: 10px;
  width: 100%;
}
.captcha-img {
  width: 104px;
  height: 40px;
  border: 1px solid var(--el-border-color);
  border-radius: 4px;
  cursor: pointer;
  user-select: none;
}
.login-btn {
  width: 100%;
  height: 40px;
  letter-spacing: 0.24em;
}
.login-tip {
  margin: 16px 0 0;
  font-size: 12px;
  text-align: center;
  color: var(--el-text-color-secondary);
}
.login-foot {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
