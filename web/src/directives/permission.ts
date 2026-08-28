import type { Directive, DirectiveBinding } from 'vue'
import { useUserStore } from '@/stores/user'

/**
 * 权限指令
 *
 *   <el-button v-permission="'sys:user:create'">新增</el-button>
 *   <el-button v-permission.all="['sys:user:update', 'sys:user:grantRole']">改并授权</el-button>
 *
 * 默认多个权限点满足任一即显示（也可以写成 .any），加 .all 修饰符要求全部满足。
 *
 * ⚠️ 这只是界面上的收敛，不是安全边界。按钮藏了不等于接口调不通，
 * 后端每个写接口都在路由上声明了权限点由中间件拦截（config/route.php）。
 */

/**
 * 无权限时把元素摘掉，而不是 display:none —— 后者在 DOM 里仍然存在，
 * 用开发者工具改一下 style 就“恢复”了，容易被误认为绕过了权限。
 *
 * nodeType 判断不是防御性冗余：指令用在 Fragment 根组件上时
 * （el-dropdown-item 就是一个），Vue 交给指令的 el 是它用来定位片段的锚点
 * 文本节点，而不是那个组件渲染出的 DOM。摘掉锚点会打乱 Vue 后续的
 * patch / unmount，比“按钮没藏住”严重得多。Vue 自己只会警告
 * “Runtime directive used on component with non-element root node”，
 * 不说是哪一处、也不说怎么改，所以这里补一句。
 */
function detach(el: HTMLElement, name: string): void {
  if (el?.nodeType !== Node.ELEMENT_NODE) {
    console.warn(`[v-${name}] 只能用在真实元素上，这里挂到了 Fragment 根组件上，已跳过。` +
      '这类组件改用 v-if + userStore.can() 判断')
    return
  }

  el.parentNode?.removeChild(el)
}

/** `.all` 要求全部命中，不带修饰符（或 `.any`）是任一命中 */
const MODIFIERS = ['all', 'any']

function check(binding: DirectiveBinding<string | string[]>, name: string): boolean {
  const userStore = useUserStore()
  const value = binding.value

  const unknown = Object.keys(binding.modifiers).filter((m) => !MODIFIERS.includes(m))
  if (unknown.length) {
    // 修饰符写错不会报错，只会静默按默认的“任一命中”走——把 .all 敲成 .All
    // 就等于悄悄放宽了界面收敛，只能在这里喊一声
    console.warn(`[v-${name}] 无法识别的修饰符 .${unknown.join(' .')}，可用：.${MODIFIERS.join(' .')}`)
  }

  if (!value) {
    // 没传值属于用错了指令，开发期直接暴露出来，不要静默放行
    console.warn(`[v-${name}] 缺少权限点，用法：v-permission="'sys:user:create'"`)
    return false
  }

  const codes = Array.isArray(value) ? value : [value]
  if (codes.length === 0) return false

  return binding.modifiers.all
    ? codes.every((code) => userStore.can(code))
    : codes.some((code) => userStore.can(code))
}

export const permission: Directive<HTMLElement, string | string[]> = {
  mounted(el, binding) {
    if (!check(binding, 'permission')) detach(el, 'permission')
  }
}

/**
 * 角色指令：极少数按角色而非权限点控制的场景
 *
 * ⚠️ 匹配的是**角色编码**（登录接口下发的 `roles` 就是编码数组），
 * 而编码现在由程序按主键生成——`v-role="'ROLE-0007'"` 读不出是哪个角色。
 * 真要按角色分支，在业务侧维护一张「用途 → 角色 id/编码」的配置再引用，
 * 别把生成出来的编码字面量散落在模板里。
 *
 * 另外这只是界面收敛，不是安全边界——真正的拦截在后端路由的 perm 声明上。
 */
export const role: Directive<HTMLElement, string | string[]> = {
  mounted(el, binding) {
    const userStore = useUserStore()
    const codes = Array.isArray(binding.value) ? binding.value : [binding.value]
    const owned = userStore.profile?.roles ?? []
    const ok = binding.modifiers.all
      ? codes.every((c) => owned.includes(c))
      : codes.some((c) => owned.includes(c))

    if (!ok) detach(el, 'role')
  }
}

export default {
  install(app: { directive: (name: string, dir: Directive) => void }) {
    app.directive('permission', permission as Directive)
    app.directive('role', role as Directive)
  }
}
