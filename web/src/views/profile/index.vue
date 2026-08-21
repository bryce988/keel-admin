<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import {
  fetchMyLogins,
  fetchProfile,
  updateProfile,
  uploadAvatar,
  type ProfileInfo
} from '@/api/profile'
import type { ProColumn } from '@/components'
import { useDictStore } from '@/stores/dict'
import { useUserStore } from '@/stores/user'
import PasswordDrawer from './PasswordDrawer.vue'
import PhoneDrawer from './PhoneDrawer.vue'

/**
 * 个人中心（详情页页型的参考实现，PROJECT.md §9.5）
 *
 * 左栏静态属性、右栏动态区块。左边这些（账号、部门、岗位、角色）在这里
 * 一律只读——它们由管理员在用户管理里改，个人中心能改的只有右边那几项。
 * 把「谁能改什么」直接做成版面，比在每个字段上写 disabled 更说得清。
 */
const dictStore = useDictStore()
const userStore = useUserStore()

const info = ref<ProfileInfo | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    info.value = await fetchProfile()
    form.value.real_name = info.value.real_name
    form.value.email = info.value.email
  } finally {
    loading.value = false
  }
}

// ---------------------------------------------------------------- 头像

/** 与后端白名单一致（ProfileService::AVATAR_EXTS）。这里只为提前拦一下，边界仍在服务端 */
const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
const AVATAR_MAX = 2 * 1024 * 1024

const uploading = ref(false)

/**
 * 换头像
 *
 * 用 el-upload 的 before-upload 做拦截 + 自己发请求，不走它的 action：
 * 组件自带的上传绕过了 utils/request，拿不到 token 也走不到统一的错误处理
 * （CLAUDE.md：页面不直接调 axios，接口一律写在 api/*.ts 里）。
 *
 * 返回 false 阻止组件继续上传，剩下的事我们自己做。
 */
async function onAvatarPick(file: File) {
  if (!AVATAR_TYPES.includes(file.type)) {
    ElMessage.error('只支持 jpg / png / gif / webp')
    return false
  }
  if (file.size > AVATAR_MAX) {
    ElMessage.error('头像不能超过 2MB')
    return false
  }

  uploading.value = true
  try {
    const { avatar } = await uploadAvatar(file)
    // 后端已经写库了，这里只同步本地状态，不需要再调 updateProfile
    if (info.value) info.value.avatar = avatar
    userStore.patchUser({ avatar })
    ElMessage.success('头像已更新')
  } finally {
    uploading.value = false
  }

  return false
}

// ---------------------------------------------------------------- 基本资料
const formRef = ref<FormInstance | null>(null)
const form = ref({ real_name: '', email: '' })
const saving = ref(false)

const rules: FormRules = {
  real_name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }]
}

async function save() {
  if (!(await formRef.value?.validate().catch(() => false))) return

  saving.value = true
  try {
    info.value = await updateProfile({ real_name: form.value.real_name, email: form.value.email })
    // 顶栏昵称由 store 派生，改完姓名要同步过去（原因见 patchUser 的注释）
    userStore.patchUser({ real_name: info.value.real_name, avatar: info.value.avatar })
    ElMessage.success('已保存')
  } finally {
    saving.value = false
  }
}

// ---------------------------------------------------------------- 安全设置
const pwdDrawer = ref<InstanceType<typeof PasswordDrawer> | null>(null)
const phoneDrawer = ref<InstanceType<typeof PhoneDrawer> | null>(null)

// ---------------------------------------------------------------- 登录记录
const loginQuery = ref<Record<string, unknown>>({})

const loginColumns: ProColumn[] = [
  { prop: 'created_at', label: '时间', width: 165 },
  { prop: 'ip', label: 'IP', width: 130 },
  { prop: 'location', label: '登录地址', minWidth: 120 },
  { prop: 'browser', label: '浏览器', width: 110 },
  { prop: 'os', label: '操作系统', width: 110 },
  { prop: 'type', label: '类型', width: 80, align: 'center', dict: 'login_type' },
  { prop: 'status', label: '结果', width: 80, align: 'center', dict: 'log_status' }
]

onMounted(() => {
  dictStore.preload(['login_type', 'log_status'])
  load()
})
</script>

<template>
  <div class="page profile">
    <!--
      首屏用骨架屏而不是 v-loading 整页转圈（§9.6）：
      转圈会让首屏「白 → 转圈 → 内容」跳两次，骨架屏的形状与真实版面一致，
      数据到了直接替换，只跳一次。二次加载（保存后重取）仍走按钮内 loading
    -->
    <PageSkeleton v-if="loading && !info" type="detail" />

    <div v-else class="cols">
      <!-- 左栏：静态属性，全部只读 -->
      <el-card class="side" shadow="never">
        <div class="ident">
          <!-- 头像是这一栏里唯一可改的东西，所以给它一个明确的悬停提示 -->
          <el-upload
            class="avatar-pick"
            :show-file-list="false"
            :before-upload="onAvatarPick"
            accept="image/jpeg,image/png,image/gif,image/webp"
          >
            <div v-loading="uploading" class="avatar-wrap">
              <el-avatar :size="64" :src="info?.avatar || undefined">
                {{ info?.real_name?.charAt(0) || '?' }}
              </el-avatar>
              <span class="avatar-mask">更换</span>
            </div>
          </el-upload>
          <div class="name">{{ info?.real_name || '—' }}</div>
          <div class="account">{{ info?.username }}</div>
          <el-tag v-if="info?.is_super" size="small" type="warning" effect="plain">
            超级管理员
          </el-tag>
        </div>

        <el-divider />

        <dl class="meta">
          <dt>部门</dt>
          <dd>{{ info?.dept_name || '—' }}</dd>
          <dt>岗位</dt>
          <dd>{{ info?.post_name || '—' }}</dd>
          <dt>角色</dt>
          <dd>
            <template v-if="info?.roles?.length">
              <el-tag v-for="r in info.roles" :key="r" size="small" effect="plain">{{ r }}</el-tag>
            </template>
            <template v-else>—</template>
          </dd>
          <dt>上次登录</dt>
          <dd class="num">{{ info?.last_login_at || '—' }}</dd>
          <dt>登录 IP</dt>
          <dd class="num">{{ info?.last_login_ip || '—' }}</dd>
          <dt>加入时间</dt>
          <dd class="num">{{ info?.created_at || '—' }}</dd>
        </dl>
      </el-card>

      <!-- 右栏：能改的东西都在这边 -->
      <el-card class="body" shadow="never">
        <el-tabs>
          <el-tab-pane label="基本资料">
            <el-form
              ref="formRef"
              :model="form"
              :rules="rules"
              label-width="80px"
              class="basic"
            >
              <el-form-item label="账号">
                <el-input :model-value="info?.username" disabled />
                <div class="tip">账号不可修改，需要变更请联系管理员</div>
              </el-form-item>
              <el-form-item label="姓名" prop="real_name">
                <el-input v-model="form.real_name" maxlength="64" show-word-limit />
              </el-form-item>
              <el-form-item label="邮箱" prop="email">
                <el-input v-model="form.email" maxlength="128" placeholder="用于接收系统通知" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="saving" @click="save">保存</el-button>
              </el-form-item>
            </el-form>
          </el-tab-pane>

          <el-tab-pane label="安全设置">
            <ul class="security">
              <li>
                <div>
                  <div class="label">登录密码</div>
                  <div class="tip">
                    最后修改：{{ info?.pwd_updated_at || '从未修改' }}
                  </div>
                </div>
                <el-button link type="primary" @click="pwdDrawer?.open()">修改</el-button>
              </li>
              <li>
                <div>
                  <div class="label">手机号</div>
                  <div class="tip">
                    <template v-if="info?.phone">
                      {{ info.phone }}——换绑需要验证当前密码
                    </template>
                    <template v-else>未绑定，绑定后可用于身份标识与找回</template>
                  </div>
                </div>
                <el-button link type="primary" @click="phoneDrawer?.open()">
                  {{ info?.phone ? '换绑' : '绑定' }}
                </el-button>
              </li>
            </ul>
          </el-tab-pane>

          <el-tab-pane label="登录记录">
            <!--
              sync-url 关掉：这是详情页里的一个 tab，把分页塞进地址栏之后，
              从页签切走再回来会停在第 3 页的登录记录上，而不是这个人的资料
            -->
            <ProTable
              v-model:params="loginQuery"
              :request="fetchMyLogins"
              :columns="loginColumns"
              :sync-url="false"
            />
          </el-tab-pane>
        </el-tabs>
      </el-card>
    </div>

    <PasswordDrawer ref="pwdDrawer" />
    <PhoneDrawer ref="phoneDrawer" @saved="load" />
  </div>
</template>

<style scoped>
.cols {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 16px;
  align-items: start;
}

/* 窄屏塌成单列：左栏 300px 固定，再挤就没法看了 */
@media (max-width: 1100px) {
  .cols {
    grid-template-columns: 1fr;
  }
}

.ident {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.ident .name {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.ident .account {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.avatar-pick {
  cursor: pointer;
}

.avatar-wrap {
  position: relative;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  overflow: hidden;
}

/* 悬停才浮出「更换」，静止时就是一个普通头像，不打扰阅读 */
.avatar-mask {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgb(0 0 0 / 45%);
  color: #fff;
  font-size: 12px;
  opacity: 0;
  transition: opacity 0.2s;
}

.avatar-wrap:hover .avatar-mask {
  opacity: 1;
}

/* dl 而不是 el-descriptions：这里是「标签 + 值」的窄栏，
   descriptions 的边框在 300px 宽度下会把值挤成两三行 */
.meta {
  display: grid;
  grid-template-columns: 64px 1fr;
  gap: 10px 12px;
  margin: 0;
}

.meta dt {
  color: var(--el-text-color-secondary);
}

.meta dd {
  margin: 0;
  color: var(--el-text-color-primary);
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.basic {
  max-width: 460px;
}

.tip {
  margin-top: 4px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
  line-height: 1.6;
}

.security {
  list-style: none;
  margin: 0;
  padding: 0;
  max-width: 560px;
}

.security li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 0;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.security li:last-child {
  border-bottom: none;
}

.security .label {
  color: var(--el-text-color-primary);
}
</style>
