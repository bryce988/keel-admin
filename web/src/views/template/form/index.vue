<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
// ⛔ 复制后把这一行换成你自己的 api/xxx.ts
import { createDemo } from '../_demo'

/**
 * 【模板 ④】表单页 —— 独立路由的长表单
 *
 * 字段少于 12 个、不需要草稿、不需要分享链接的，一律用 `FormDrawer` 抽屉
 * （系统管理七个模块全是那种）。到了下面这些情况才值得单开一个页面：
 *   · 字段多到抽屉里要二次滚动
 *   · 需要草稿暂存（填一半被打断）
 *   · 需要把链接发给别人接着填
 *
 * 这里实现了 PROJECT.md §9.4 要求的全部五条：标签置顶、分卡片、
 * 提交失败滚动到第一个错误字段、防重复提交、离开确认 + 草稿自动暂存。
 *
 * 复制清单：
 *   1. 换掉 `../_demo`，改 DRAFT_KEY（每个业务一个键，否则两个表单互相覆盖草稿）
 *   2. 改字段与 rules，按语义分卡片，单卡片不超过 8 个字段
 *   3. 编辑场景在 onMounted 里先拉详情填进 form，并把 dirty 重新置回 false
 */
const router = useRouter()

const formRef = ref<FormInstance | null>(null)
const submitting = ref(false)

/** 每个业务表单一个键，共用会互相覆盖 */
const DRAFT_KEY = 'keel:draft:template-form'

const form = ref({
  name: '',
  code: '',
  category_id: 13,
  owner: '',
  status: 1,
  sort: 0,
  contact: '',
  email: '',
  remark: ''
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  code: [
    { required: true, message: '请输入编码', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_]+$/, message: '只能包含字母、数字与下划线', trigger: 'blur' }
  ],
  owner: [{ required: true, message: '请输入负责人', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }]
}

/**
 * 有没有未保存的修改
 *
 * 用一个标志位而不是深比较：表单字段多的时候每次输入都做一次深比较
 * 是白烧 CPU，而这个标志位只需要「改过没有」这一个 bit。
 */
const dirty = ref(false)

function touch() {
  dirty.value = true
}

// ---------------------------------------------------------------- 草稿

/**
 * 每 30 秒自动暂存（§9.4）
 *
 * 存 localStorage 而不是调接口：草稿是纯前端的防丢措施，
 * 走接口就得在后端建一张草稿表、还要考虑并发与清理，代价完全不成比例。
 */
let draftTimer: ReturnType<typeof setInterval> | undefined

function saveDraft() {
  if (!dirty.value) return
  localStorage.setItem(DRAFT_KEY, JSON.stringify(form.value))
}

function clearDraft() {
  localStorage.removeItem(DRAFT_KEY)
}

async function restoreDraft() {
  const raw = localStorage.getItem(DRAFT_KEY)
  if (!raw) return

  try {
    await ElMessageBox.confirm('检测到上次未提交的草稿，是否恢复？', '恢复草稿', {
      confirmButtonText: '恢复',
      cancelButtonText: '放弃'
    })
    Object.assign(form.value, JSON.parse(raw))
    dirty.value = true
  } catch {
    // 用户选了「放弃」，或者草稿是坏的 JSON——两种情况都直接扔掉，
    // 留着只会在下次进页面时再问一遍
    clearDraft()
  }
}

// ---------------------------------------------------------------- 提交

async function submit() {
  /*
   * 提交时校验全表，并在失败后滚动到第一个错误字段（§9.4）。
   * 长表单里错误项常常在折叠区或首屏之外，只弹一句「校验失败」
   * 等于让用户自己从头找。
   */
  const invalid = await new Promise<string | null>((resolve) => {
    formRef.value?.validate((valid, fields) => {
      resolve(valid ? null : Object.keys(fields ?? {})[0] ?? null)
    })
  })

  if (invalid) {
    formRef.value?.scrollToField(invalid)
    return
  }

  // 点击后立即 loading 并禁用，防重复提交
  submitting.value = true
  try {
    await createDemo({ ...form.value })
    clearDraft()
    // 先清 dirty 再跳，否则路由守卫会拦下自己这次跳转，弹一句「有未保存的修改」
    dirty.value = false
    ElMessage.success('已提交')
    router.back()
  } finally {
    submitting.value = false
  }
}

async function cancel() {
  if (dirty.value) {
    await ElMessageBox.confirm('有未保存的修改，确定放弃吗？', '提示', { type: 'warning' })
  }
  clearDraft()
  dirty.value = false
  router.back()
}

// ---------------------------------------------------------------- 离开确认

onBeforeRouteLeave(async () => {
  if (!dirty.value) return true

  try {
    await ElMessageBox.confirm('有未保存的修改，确定离开吗？草稿已暂存。', '提示', {
      type: 'warning',
      confirmButtonText: '离开',
      cancelButtonText: '继续编辑'
    })
    saveDraft()

    return true
  } catch {
    return false
  }
})

/** 关标签页/刷新走浏览器原生确认，路由守卫拦不到这一种 */
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (!dirty.value) return
  saveDraft()
  e.preventDefault()
}

onMounted(() => {
  restoreDraft()
  draftTimer = setInterval(saveDraft, 30_000)
  window.addEventListener('beforeunload', onBeforeUnload)
})

onBeforeUnmount(() => {
  clearInterval(draftTimer)
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<template>
  <div class="page form-page">
    <!-- 标签置顶左对齐（§9.4）：长表单里置顶比左置更省横向空间，也更好扫读 -->
    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="top"
      @change="touch"
      @input="touch"
    >
      <!-- 按语义分卡片，单卡片不超过 8 个字段 -->
      <el-card shadow="never">
        <template #header>基本信息</template>
        <div class="grid">
          <el-form-item label="名称" prop="name">
            <el-input v-model="form.name" maxlength="64" show-word-limit />
          </el-form-item>
          <el-form-item label="编码" prop="code">
            <el-input v-model="form.code" maxlength="64" placeholder="如 DEMO_001" />
          </el-form-item>
          <el-form-item label="负责人" prop="owner">
            <el-input v-model="form.owner" maxlength="32" />
          </el-form-item>
          <el-form-item label="状态" prop="status">
            <!-- 枚举走字典，不写死选项（§10） -->
            <DictSelect v-model="form.status" code="enable_status" numeric />
          </el-form-item>
        </div>
      </el-card>

      <el-card shadow="never">
        <template #header>联系方式</template>
        <div class="grid">
          <el-form-item label="联系电话" prop="contact">
            <el-input v-model="form.contact" maxlength="20" />
          </el-form-item>
          <el-form-item label="邮箱" prop="email">
            <el-input v-model="form.email" maxlength="128" />
          </el-form-item>
        </div>
      </el-card>

      <el-card shadow="never">
        <template #header>其他</template>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="9999" controls-position="right" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" maxlength="255" show-word-limit />
        </el-form-item>
      </el-card>
    </el-form>

    <!-- 操作条吸底：长表单滚到一半要提交时不用再滚回顶部 -->
    <div class="panel footer">
      <el-button @click="cancel">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="submit">提交</el-button>
      <span v-if="dirty" class="dirty-tip">有未保存的修改，草稿每 30 秒自动暂存</span>
    </div>
  </div>
</template>

<style scoped>
.form-page {
  max-width: 900px;
  padding-bottom: 64px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 16px;
}

@media (max-width: 700px) {
  .grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

/* 面板外观走全局 .panel，只覆盖内边距：
   吸底操作条要比内容面板扁，用满 16px 会显得头重脚轻 */
.footer {
  position: sticky;
  bottom: 0;
  display: flex;
  align-items: center;
  gap: var(--keel-gap);
  padding: var(--keel-gap) var(--keel-panel-pad);
}

.dirty-tip {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
