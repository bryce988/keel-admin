import { computed, nextTick, ref, type Ref } from 'vue'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { BizError } from '@/utils/request'

/**
 * 表单壳的公共逻辑
 *
 * 打开、深拷贝、校验、提交、loading、服务端错误回填、关闭——这一套与「装在
 * 抽屉里还是弹窗里」无关，所以抽出来给 `<FormDrawer>` 与 `<FormDialog>` 共用。
 *
 * 为什么不做成一个带 `variant` 开关的组件：那样会有一个叫 Drawer 的东西渲染出
 * Dialog，调用方读 `<FormDrawer variant="dialog">` 还得先知道这回事。名字应当
 * 说实话。而两个组件各抄一份逻辑同样不行——改一处漏一处，那正是当初把这套东西
 * 抽成组件要避免的。composable 让两边都成立：名字对，逻辑仍只有一份。
 *
 * 两个组件因此只剩下容器与插槽转发，各二十来行模板。
 */

/** 打开表单时传的参数 */
export interface FormShellOptions {
  title: string
  data?: Record<string, any>
  /** edit 可提交；view 只读，隐藏确定按钮 */
  mode?: 'edit' | 'view'
}

/** 页面拿 ref 用的实例类型，两个组件暴露的是同一套 */
export interface FormShellInstance {
  open: (options: FormShellOptions) => void
  close: () => void
  form: Record<string, any>
}

export interface FormShellProps {
  /** 提交函数，由页面提供；抛异常即视为失败，容器不关。详情模式下不会被调用 */
  submit?: (form: Record<string, any>) => Promise<unknown>
  rules?: FormRules
  size?: string
  labelWidth?: string
  successMessage?: string
  confirmText?: string
  /**
   * 业务码 → 字段名，用于把 409 这类冲突错误落到具体输入框上。
   * 例：`{ [BizCode.ACCOUNT_EXISTS]: 'username' }` —— 账号已存在时红框标在账号上，
   * 而不是只弹一句、用户还得自己找是哪一项（docs/api.md §1.3.1）
   *
   * 码一律用 `@/constants/bizCode` 里的常量，不要写裸数字：
   * 后端改码时裸数字不报错也不告警，只是红框悄悄标错地方
   */
  errorFields?: Record<number, string>
}

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

export function useFormShell(
  props: FormShellProps,
  emit: {
    (e: 'success', result: unknown): void
    (e: 'closed'): void
  }
) {
  const visible = ref(false)
  const title = ref('')
  const loading = ref(false)
  const mode = ref<'edit' | 'view'>('edit')
  const formRef = ref<FormInstance>()
  const form: Ref<Record<string, any>> = ref({})
  /** 服务端返回的字段级错误，插槽里绑到 el-form-item 的 :error 上 */
  const errors = ref<Record<string, string>>({})

  const readonly = computed(() => mode.value === 'view')

  function open(options: FormShellOptions) {
    title.value = options.title
    mode.value = options.mode ?? 'edit'
    form.value = clone(options.data)
    errors.value = {}
    visible.value = true

    // 容器内容是 destroy-on-close 的，等它挂好再清校验状态
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

  /** 把服务端错误落到具体字段上，失败时容器保持打开，用户可以直接改 */
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

  return { visible, title, loading, formRef, form, errors, readonly, open, close, onConfirm, onClosed }
}
