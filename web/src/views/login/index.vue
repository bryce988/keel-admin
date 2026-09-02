<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Lock, Message, Picture, User } from '@element-plus/icons-vue'
import BrandLogo from '@/components/BrandLogo.vue'
import request, { BizError } from '@/utils/request'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()

/*
 * 站点标识由后端参数下发（sys.name / sys.logo / sys.footer）。
 * main.ts 已经在启动时拉过一次，这里只读——登录页是未登录场景，
 * 用的正是那个免登录的 /admin/params/public。
 */
const appStore = useAppStore()

/*
 * Logo 加载失败就退回内置矢量标记
 *
 * `sys.logo` 是运维在后台手填的 URL，填错、图挂了、跨域被拦都可能——
 * 而 `<img>` 加载失败的默认表现是一个碎图标加 alt 文字，比没有 Logo 难看得多。
 *
 * 除了 `error`，`load` 也要判一次 `naturalWidth === 0`：
 * **只有 viewBox、没有 width/height 的 SVG 会「加载成功但没有固有尺寸」**，
 * 配上 `width: auto` 得到的是一个画不出来的盒子——浏览器照样显示碎图。
 * 本仓库自己的 `public/favicon.svg` 就是这种（验证时正是拿它试出来的）。
 * 与其猜一个宽高比硬撑，不如退回内置标记：结果可预期，且永远不会是碎的。
 */
const logoBroken = ref(false)

watch(
  () => appStore.site.logo,
  () => (logoBroken.value = false)
)

const showLogoImage = computed(() => Boolean(appStore.site.logo) && !logoBroken.value)

function onLogoLoad(e: Event) {
  const img = e.target as HTMLImageElement
  if (img.naturalWidth === 0) logoBroken.value = true
}

/**
 * 品牌侧的背景motif：一组同心的船体肋骨
 *
 * 用的就是 `BrandLogo` 里那条船体横剖弧，按比例套着画若干层，
 * 像顺着船身往里看的一排肋骨。
 *
 * ## 走过两次弯路
 *
 * 1. 第一版画的是「一排竖线，高度按抛物线走」。形状上没错（那确实是横剖轮廓），
 *    但等宽竖线立在一条基线上**就是柱状图的长相**——登录页上冒出一张图表，
 *    会让人以为那是数据。同心弧没有这个歧义
 * 2. 第二版还带着 `BrandLogo` 里那根贯穿的龙骨主梁（一条竖线）。图形整体被放大到
 *    栏宽的一倍半，1.4 的线宽跟着放大成二十几像素的**竖条**，落在离两栏分隔线
 *    不远的地方，看着像渲染出了问题。去掉之后弧线自己就成立了——
 *    龙骨的意象由上方的 `BrandLogo` 承担，装饰不必再说一遍
 *
 * ## 为什么不是插画
 *
 * 插画要么外链（CSP 与离线部署都过不去）、要么内联一大坨 base64。
 * 而且这个脚手架是给人 fork 的：插画一旦有具体形象，改名换色之后就格格不入；
 * 一组线是结构性的，`currentColor` 跟着主色走。
 */
const HULL_PATH = 'M4 5.2 C 4.6 17.1, 9.7 25, 16 25.8 C 22.3 25, 27.4 17.1, 28 5.2'

/** 由内向外套 6 层，越外圈越淡——近处清晰、远处退开，才有纵深 */
const hullRings = computed(() =>
  Array.from({ length: 6 }, (_, i) => ({
    scale: 1 + i * 0.42,
    opacity: 0.9 - i * 0.13
  }))
)

/**
 * 两种登录方式
 *
 * `account` 账号 + 密码 + 图形验证码；
 * `email`   邮箱 + 密码 + 图形验证码 → 收邮箱验证码 → 提交。
 *
 * 邮箱这条路只有在后端配了 SMTP 时才出现（`appStore.site.emailLogin`）——
 * 没配还把页签摆出来，用户点「发送验证码」只会拿到一个他无从处理的错误。
 */
type LoginMode = 'account' | 'email'

const mode = ref<LoginMode>('account')
const accountFormRef = ref<FormInstance>()
const emailFormRef = ref<FormInstance>()
const loading = ref(false)
const captchaImage = ref('')

/*
 * 一个 model 两套规则，而不是两个 model
 *
 * 密码、图形验证码两栏是两种方式共用的，拆成两份的话「切个页签密码就没了」，
 * 而两个页签的密码本来就是同一个东西。规则分开是因为必填项不同：
 * 账号页签要 `username`，邮箱页签要 `email` 与 `email_code`。
 * Element Plus 只校验**当前渲染出来的** el-form-item，所以同一个 model
 * 挂两套规则不会互相牵连。
 *
 * 键名用 snake_case：它直接就是登录接口的请求体。
 */
const form = reactive({
  username: '',
  password: '',
  email: '',
  captcha_key: '',
  captcha_code: '',
  email_code: ''
})

/**
 * 「其他登录方式」列表：当前方式不在其中
 *
 * 邮箱那项要后端配了 SMTP 才出现（`appStore.site.emailLogin`）——
 * 没配还把入口摆出来，点进去发码只会拿到一个用户无从处理的错误。
 */
const altMethods = computed(() => {
  const all = [
    { mode: 'account' as const, label: '账号登录', icon: User, enabled: true },
    { mode: 'email' as const, label: '邮箱登录', icon: Message, enabled: appStore.site.emailLogin }
  ]

  return all.filter((item) => item.enabled && item.mode !== mode.value)
})

const captchaRule: FormRules[string] = [{ required: true, message: '请输入验证码', trigger: 'blur' }]
const passwordRule: FormRules[string] = [{ required: true, message: '请输入密码', trigger: 'blur' }]

const accountRules: FormRules = {
  username: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: passwordRule,
  captcha_code: captchaRule
}

const emailRules: FormRules = {
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' }
  ],
  password: passwordRule,
  captcha_code: captchaRule,
  email_code: [{ required: true, message: '请输入邮箱验证码', trigger: 'blur' }]
}

async function loadCaptcha() {
  const data = await request.get<unknown, { captcha_key: string; captcha_image: string }>(
    '/admin/auth/captcha'
  )
  form.captcha_key = data.captcha_key
  form.captcha_code = ''
  captchaImage.value = data.captcha_image
}

/* ---------------------------------------------------------------- 邮箱验证码 */

const sending = ref(false)
/** 距离可以再次发码还剩多少秒，>0 时按钮禁用 */
const countdown = ref(0)
let countdownTimer = 0

function startCountdown(seconds: number) {
  window.clearInterval(countdownTimer)
  countdown.value = seconds

  countdownTimer = window.setInterval(() => {
    if (--countdown.value <= 0) window.clearInterval(countdownTimer)
  }, 1000)
}

// 定时器不清会跟着组件一起泄漏：登录成功后这个页面就被卸载了，
// 而 interval 还在按秒改一个没人再看的 ref
onUnmounted(() => window.clearInterval(countdownTimer))

/**
 * 发验证码
 *
 * 只校验发码要用的三项——`email_code` 这时当然是空的，
 * 整表校验会先弹一个「请输入邮箱验证码」，而那正是这一步要去取的东西。
 *
 * 倒计时秒数用后端返回的 `resend_in` 而不是页面里写死的 60：
 * 真正的间隔限制在后端（`EMAIL_CODE_RESEND_SECONDS`），部署方调了那个值之后
 * 前端跟着走，不会出现「按钮亮了但点了还是 429」。
 */
async function onSendCode() {
  const ok = await emailFormRef.value
    ?.validateField(['email', 'password', 'captcha_code'])
    .catch(() => false)
  if (!ok) return

  sending.value = true
  try {
    const data = await request.post<unknown, { expires_in: number; resend_in: number }>(
      '/admin/auth/email/code',
      {
        email: form.email,
        password: form.password,
        captcha_key: form.captcha_key,
        captcha_code: form.captcha_code
      }
    )

    ElMessage.success(`验证码已发送至 ${form.email}，${Math.ceil(data.expires_in / 60)} 分钟内有效`)
    startCountdown(data.resend_in)
  } catch (e) {
    showError(e)
  } finally {
    sending.value = false
    // 图形验证码是一次性的，后端校验完就删了——无论成败都得换一张，
    // 否则用户拿着一个已经作废的 key 反复提交
    loadCaptcha()
  }
}

/* ---------------------------------------------------------------- 提交 */

/** 422 的字段级错误由拦截器放行到这里，取第一条提示 */
function showError(e: unknown) {
  if (!(e instanceof BizError)) return

  if (e.status === 422 && e.details) {
    const first = Object.values(e.details)[0]?.[0]
    ElMessage.error(first || e.message)
  } else {
    ElMessage.error(e.message)
  }
}

async function onSubmit() {
  /*
   * 邮箱登录这一步**不校验图形验证码**
   *
   * 它在发码那一步就被后端消费掉了（一次性），提交时那一栏必然是空的——
   * 整表校验会在这里弹「请输入验证码」，把用户卡在一个填了也没人看的框上。
   * 第一版就是这么写的，实测拿到验证码之后根本登不进去。
   */
  const ok =
    mode.value === 'account'
      ? await accountFormRef.value?.validate().catch(() => false)
      : await emailFormRef.value
          ?.validateField(['email', 'password', 'email_code'])
          .catch(() => false)
  if (!ok) return

  loading.value = true
  try {
    const res =
      mode.value === 'account'
        ? await userStore.login({
            username: form.username,
            password: form.password,
            captcha_key: form.captcha_key,
            captcha_code: form.captcha_code
          })
        : await userStore.loginByEmail({
            email: form.email,
            password: form.password,
            email_code: form.email_code
          })

    await userStore.fetchProfile()

    if (res.must_change_password) {
      ElMessage.warning('您还未修改过初始密码，建议尽快修改')
    }
    ElMessage.success('登录成功')
    // 不写死 /dashboard：落地页由守卫按该账号的菜单决定，
    // 否则没有概览权限的账号一登录就撞 404
    router.replace((route.query.redirect as string) || '/')
  } catch (e) {
    showError(e)

    if (mode.value === 'account') {
      // 账号登录每次提交都消费一张图形验证码，失败就得换新的。
      // 邮箱登录这一步没有图形验证码（它在发码那步用掉了），
      // 跟着刷新只会把用户刚填好的那栏清空
      loadCaptcha()
    } else {
      form.email_code = ''
    }
  } finally {
    loading.value = false
  }
}

onMounted(loadCaptcha)
</script>

<template>
  <div class="login-wrap">
    <div class="login-card">
      <!--
        左右两栏：左品牌、右表单。
        窄屏（< 860px）下左栏收成一条横带（见媒体查询）——
        品牌栏原样堆在表单上方会把输入框推到首屏之外，
        而登录页唯一要紧的事就是那三个输入框。
      -->
      <aside class="brand">
        <!--
          背景 motif，纯装饰，读屏器跳过。
          放在内容之前 + 绝对定位，让下面的文字自然压在它上面
        -->
        <svg class="hull" viewBox="0 0 32 32" aria-hidden="true">
          <g fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
            <g v-for="ring in hullRings" :key="ring.scale" :opacity="ring.opacity">
              <path :d="HULL_PATH" :transform="`translate(16 16) scale(${ring.scale}) translate(-16 -16)`" />
            </g>
          </g>
        </svg>

        <div class="brand-lockup">
          <!--
            配了 sys.logo 就用那张图，加载不出来（或没有固有尺寸）时回到内置矢量标记。
            图片高度锁死 44px，宽度自适应——上传的 Logo 长宽比不可控，
            锁宽会把窄标压扁、把宽标撑破卡片。
          -->
          <img
            v-if="showLogoImage"
            :src="appStore.site.logo"
            class="brand-image"
            :alt="appStore.site.name"
            @load="onLogoLoad"
            @error="logoBroken = true"
          />
          <BrandLogo v-else :size="40" />
        </div>

        <p class="brand-tagline">多端后台系统的底座</p>

        <!-- 页脚文案走 sys.footer；没配就整条不出，别留一行空白 -->
        <p v-if="appStore.site.footer" class="brand-foot">{{ appStore.site.footer }}</p>
      </aside>

      <section class="form">
        <!--
          标题跟着方式走。去掉页签之后，它是「我现在在用哪种方式登录」的唯一提示，
          没有它的话切过去只看到表单换了一副样子，不知道自己在哪
        -->
        <h1 class="form-title">{{ mode === 'account' ? '登录' : '邮箱登录' }}</h1>

        <el-form
          v-if="mode === 'account'"
          ref="accountFormRef"
          :model="form"
          :rules="accountRules"
          size="large"
          @keyup.enter="onSubmit"
        >
          <el-form-item prop="username">
            <el-input v-model="form.username" :prefix-icon="User" placeholder="账号" clearable />
          </el-form-item>

          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              :prefix-icon="Lock"
              placeholder="密码"
              show-password
            />
          </el-form-item>

          <el-form-item prop="captcha_code">
            <div class="captcha-row">
              <!--
                验证码这一格用 Picture 而不是 Key：Key 和上一行的 Lock 是一对近义图形，
                挨着放会让人分不清哪行才是密码。而「图形验证码」本来就是照着旁边那张图填，
                Picture 既说得通、形状上也与锁区分得开。
              -->
              <el-input
                v-model="form.captcha_code"
                :prefix-icon="Picture"
                placeholder="验证码"
                maxlength="4"
              />
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

        <!--
          邮箱登录：邮箱、密码、图形验证码三项填完才发得出验证码。
          顺序是刻意的——后端要先确认「这个邮箱确实绑在某个账号上，且密码正确」
          才肯发信，否则任何人填个邮箱就能让别人收信，还能靠「收没收到」
          试出谁是这个系统的用户
        -->
        <el-form
          v-else
          ref="emailFormRef"
          :model="form"
          :rules="emailRules"
          size="large"
          @keyup.enter="onSubmit"
        >
          <el-form-item prop="email">
            <el-input
              v-model="form.email"
              :prefix-icon="Message"
              placeholder="账号绑定的邮箱"
              maxlength="128"
              clearable
            />
          </el-form-item>

          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              :prefix-icon="Lock"
              placeholder="密码"
              show-password
            />
          </el-form-item>

          <el-form-item prop="captcha_code">
            <div class="captcha-row">
              <el-input
                v-model="form.captcha_code"
                :prefix-icon="Picture"
                placeholder="验证码"
                maxlength="4"
              />
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

          <el-form-item prop="email_code">
            <div class="captcha-row">
              <el-input
                v-model="form.email_code"
                :prefix-icon="Message"
                placeholder="邮箱验证码"
                maxlength="6"
              />
              <!--
                按钮宽度写死，与图形验证码那张图同宽：倒计时文案（「59s」）
                比「获取验证码」短得多，不定宽的话上下两行的输入框会不等长，
                每过一秒还跟着抖一下
              -->
              <el-button
                class="code-btn"
                :loading="sending"
                :disabled="countdown > 0"
                @click="onSendCode"
              >
                {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
              </el-button>
            </div>
          </el-form-item>

          <el-button type="primary" class="login-btn" :loading="loading" @click="onSubmit">
            登 录
          </el-button>
        </el-form>

        <p v-if="mode === 'account'" class="login-tip">演示环境默认账号 admin / admin123</p>
        <p v-else class="login-tip">验证码会发到该账号绑定的邮箱</p>

        <!--
          其他登录方式
          
          只列**当前没在用**的那些，用的这种不重复出现——这一排的语义是
          「还可以怎么登」，把当前方式也摆进去就变成了一个没有选中态的单选组。
          两种方式时它退化成一个图标，看着单薄，但结构是对的：
          将来加微信、钉钉往 `altMethods` 里追加一项即可，布局不用动。

          后端没配 SMTP 时整块不画（`emailLogin` 为 false 时列表为空）——
          留一条「其他登录方式」的分隔线下面空无一物，比没有更奇怪
        -->
        <div v-if="altMethods.length" class="alt-login">
          <div class="alt-divider"><span>其他登录方式</span></div>

          <div class="alt-list">
            <button
              v-for="item in altMethods"
              :key="item.mode"
              type="button"
              class="alt-item"
              :title="item.label"
              @click="mode = item.mode"
            >
              <el-icon :size="18"><component :is="item.icon" /></el-icon>
              <span class="alt-label">{{ item.label }}</span>
            </button>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.login-wrap {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background:
    radial-gradient(1000px 500px at 50% -10%, var(--el-color-primary-light-9), transparent 70%),
    var(--el-bg-color-page);
}

/*
 * 卡片本身不设内边距：两栏各有各的留白，而且左栏的底色要铺满到卡片边缘。
 * overflow: hidden 让底色被圆角裁掉——否则左栏的直角会从圆角处露出来。
 */
.login-card {
  display: grid;
  grid-template-columns: minmax(0, 0.85fr) minmax(0, 1fr);
  width: min(800px, 100%);
  overflow: hidden;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  /* 容器档：登录卡与全站的面板、卡片是同一类东西，圆角必须跟着走 */
  border-radius: var(--keel-radius-lg);
  box-shadow: var(--el-box-shadow-light);
}

/* ---------------------------------------------------------------- 左：品牌 */
.brand {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 40px 32px 32px;
  overflow: hidden;
  background: var(--el-color-primary-light-9);
  border-right: 1px solid var(--el-border-color-lighter);
}

.brand-lockup {
  display: flex;
  align-items: center;
}

.brand-image {
  height: 40px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
}

.brand-tagline {
  margin: 10px 0 0;
  font-size: 14px;
  line-height: 1.6;
  color: var(--el-text-color-secondary);
}

/*
 * 背景 motif
 *
 * 绝对定位并往外溢出：只露出弧线的一段，看着像船身还在画面之外继续延伸。
 * 卡片那层 `overflow: hidden` 负责裁掉多出去的部分。
 *
 * 不用参与布局的方案（比如 margin-top: auto 把它顶到底部）——
 * 品牌栏的高度由**右侧表单**决定，让装饰参与布局就会出现
 * 「表单多一行错误提示、装饰跟着跳一下」。
 */
.hull {
  position: absolute;
  right: -52%;
  bottom: -30%;
  width: 152%;
  height: auto;
  color: var(--el-color-primary);
  opacity: 0.16;
  pointer-events: none;
}

/* 文字压在装饰之上：装饰在 DOM 里排在前面，给内容加个定位上下文就够了 */
.brand-lockup,
.brand-tagline,
.brand-foot {
  position: relative;
}

/* margin-top: auto 把页脚推到品牌栏底部，中间那段空白就是留白本身 */
.brand-foot {
  margin: auto 0 0;
  padding-top: 24px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

/* ---------------------------------------------------------------- 右：表单 */
.form {
  padding: 40px 40px 32px;
}

.form-title {
  margin: 0 0 20px;
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.captcha-row {
  display: flex;
  gap: 10px;
  width: 100%;
}

/* 与验证码图片同宽，理由见模板里的注释 */
.code-btn {
  width: 104px;
  flex: none;
}

.captcha-img {
  width: 104px;
  height: 40px;
  border: 1px solid var(--el-border-color);
  /* 控件档：它与验证码输入框并排，得和输入框同圆角 */
  border-radius: var(--keel-radius);
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

/* ---------------------------------------------------------------- 其他登录方式 */
.alt-login {
  margin-top: 24px;
}

/*
 * 带文字的分隔线：两条 1px 线由伪元素画，中间的文字用 flex 撑开。
 * 不用 <el-divider content-position="center">——它的默认间距是给正文段落
 * 之间用的，塞在这里会把卡片撑高一截，而登录卡的高度直接决定它在视口里居不居中
 */
.alt-divider {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.alt-divider::before,
.alt-divider::after {
  flex: 1;
  height: 1px;
  content: '';
  background: var(--el-border-color-lighter);
}

.alt-list {
  display: flex;
  justify-content: center;
  gap: 24px;
  margin-top: 16px;
}

/*
 * 图标按钮
 *
 * 用 <button> 而不是 <div @click>：键盘能 Tab 到、回车能触发、读屏器认得出是按钮。
 * 这是登录页上除了提交之外唯一的动作，不能只有鼠标能用。
 */
.alt-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  cursor: pointer;
  background: none;
  border: none;
  transition: color 0.2s;
}

.alt-item .el-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  color: var(--el-text-color-regular);
  border: 1px solid var(--el-border-color);
  border-radius: 50%;
  transition:
    color 0.2s,
    border-color 0.2s,
    background-color 0.2s;
}

.alt-item:hover {
  color: var(--el-color-primary);
}

.alt-item:hover .el-icon {
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  border-color: var(--el-color-primary-light-5);
}

/* 键盘焦点必须看得见：hover 态只有鼠标能触发 */
.alt-item:focus-visible {
  outline: none;
}

.alt-item:focus-visible .el-icon {
  outline: 2px solid var(--el-color-primary);
  outline-offset: 2px;
}

/* ----------------------------------------------------------------
 * 窄屏：左栏收成一条横带
 *
 * 不是隐藏——品牌标识在登录页是有用的（这是哪个系统）。但竖着的品牌栏
 * 堆在表单上方会把输入框推到首屏之外，所以只留标识那一行，
 * 标语、肋骨、页脚三样装饰性的收掉。
 */
@media (max-width: 860px) {
  .login-card {
    grid-template-columns: minmax(0, 1fr);
  }

  .brand {
    align-items: center;
    padding: 24px;
    border-right: none;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .brand-tagline,
  .hull,
  .brand-foot {
    display: none;
  }

  .form {
    padding: 28px 24px 24px;
  }
}
</style>
