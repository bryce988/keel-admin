<script lang="ts">
/**
 * 打开抽屉的参数与实例类型
 *
 * 单独导出，页面统一 `ref<FormDrawerInstance | null>(null)`。
 * 每个页面各写一遍内联注解的话，组件加了参数就会有一半页面的类型对不上，
 * 而 TS 只会在用到新参数的那个页面报错，别的地方悄悄过。
 */
export interface FormDrawerOptions {
  title: string
  data?: Record<string, any>
  /** edit 可提交；view 只读，隐藏确定按钮 */
  mode?: 'edit' | 'view'
}

export interface FormDrawerInstance {
  open: (options: FormDrawerOptions) => void
  close: () => void
  form: Record<string, any>
}
</script>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { BizError } from '@/utils/request'

/**
 * 表单抽屉
 *
 * 新增、编辑、详情三种场景统一走它。用抽屉而不是弹窗：
 * 后台表单字段普遍偏多，弹窗撑不下就得在内部再套一层滚动，
 * 而抽屉天然是整列高度，长表单不用二次滚动，也不会把列表整个盖住。
 *
 * 统一的是**壳**——打开、深拷贝、校验、提交、loading、服务端错误回填、关闭；
 * 字段本身由调用方用插槽自由写。七个模块的表单字段差异很大
 * （菜单表单光 type 就有五种形态），做成 schema 驱动会变成一个没人愿意读的小框架。
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
const props = withDefaults(
  defineProps<{
    /** 提交函数，由页面提供；抛异常即视为失败，抽屉不关。详情模式下不会被调用 */
    submit?: (form: Record<string, any>) => Promise<unknown>
    rules?: FormRules
    /** 抽屉宽度，字段多的表单可以调大 */
    size?: string
    labelWidth?: string
    successMessage?: string
    confirmText?: string
    /**
     * 业务码 → 字段名，用于把 409 这类冲突错误落到具体输入框上。
     * 例：`{ [BizCode.ACCOUNT_EXISTS]: 'username' }` —— 账号已存在时红框标在账号上，
     * 而不是只弹一句、用户还得自己找是哪一项（docs/api.md §1.3.1）
     *
     * **码一律用 `@/constants/bizCode` 里的常量，不要写裸数字**：
     * 后端改码时裸数字不报错也不告警，只是红框悄悄标错地方
     */
    errorFields?: Record<number, string>
  }>(),
  { size: '560px', labelWidth: '96px', confirmText: '确 定' }
)

const emit = defineEmits<{ success: [result: unknown]; closed: [] }>()

const visible = ref(false)
const title = ref('')
const loading = ref(false)
const mode = ref<'edit' | 'view'>('edit')
const formRef = ref<FormInstance>()
const form = ref<Record<string, any>>({})
/** 服务端返回的字段级错误，插槽里绑到 el-form-item 的 :error 上 */
const errors = ref<Record<string, string>>({})

const readonly = computed(() => mode.value === 'view')

/**
 * 深拷贝一份再编辑
 *
 * 直接把列表行对象传进来编辑的话，用户还没点保存，表格里的数据就跟着变了；
 * 取消之后更是回不去。JSON 拷贝对表单数据（字符串/数字/布尔/数组）足够，
 * 遇到 Date 或 undefined 需要自己在 data 里先转好。
 */
function clone(value?: Record<string, any> | null): Record<string, any> {
  return value ? JSON.parse(JSON.stringify(value)) : {}
}

function open(options: FormDrawerOptions) {
  title.value = options.title
  mode.value = options.mode ?? 'edit'
  form.value = clone(options.data)
  errors.value = {}
  visible.value = true

  // 抽屉内容是 destroy-on-close 的，等它挂好再清校验状态
  nextTick(() => formRef.value?.clearValidate())
}

function close() {
  visible.value = false
}

async function onConfirm() {
  if (loading.value || readonly.value || !props.submit) return

  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return

  loading.value = true
  errors.value = {}

  try {
    const result = await props.submit({ ...form.value })

    ElMessage.success(props.successMessage ?? '保存成功')
    visible.value = false
    emit('success', result)
  } catch (e) {
    if (e instanceof BizError) {
      applyServerErrors(e)
    }
    // 其余错误 utils/request 的拦截器已经提示过，这里不重复弹
  } finally {
    loading.value = false
  }
}

/** 把服务端错误落到具体字段上，失败时抽屉保持打开，用户可以直接改 */
function applyServerErrors(e: BizError) {
  // 422：details 里是字段级明细，直接对号入座
  if (e.status === 422 && e.details) {
    errors.value = Object.fromEntries(
      Object.entries(e.details).map(([field, messages]) => [field, messages[0] ?? ''])
    )
    // 422 的提示交给表单，拦截器故意没弹，这里补一句避免用户没注意到红字
    ElMessage.error(Object.values(errors.value)[0] || e.message)

    return
  }

  // 409 / 400 这类只有一条 message，按业务码映射到字段
  const field = props.errorFields?.[e.code]
  if (field) {
    errors.value = { [field]: e.message }
  }
}

function onClosed() {
  form.value = {}
  errors.value = {}
  emit('closed')
}

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
      <div class="drawer-footer">
        <el-button @click="close">{{ readonly ? '关 闭' : '取 消' }}</el-button>
        <el-button v-if="!readonly" type="primary" :loading="loading" @click="onConfirm">
          {{ confirmText }}
        </el-button>
      </div>
    </template>
  </el-drawer>
</template>

<style scoped>
.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}
</style>
