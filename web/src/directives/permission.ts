import type { Directive, DirectiveBinding } from 'vue'
import { useUserStore } from '@/stores/user'

/**
 * 权限指令
 *
 *   <el-button v-permission="'sys:user:create'">新增</el-button>
 *   <el-button v-permission.all="['sys:user:update', 'sys:user:grantRole']">改并授权</el-button>
 *
 * 默认多个权限点满足**任一**即显示，加 .all 修饰符要求全部满足。
 *
 * ⚠️ 这只是界面上的收敛，**不是安全边界**。按钮藏了不等于接口调不通，
 * 后端每个写接口都在路由上声明了权限点由中间件拦截（config/route.php）。
 */
function check(binding: DirectiveBinding<string | string[]>): boolean {
  const userStore = useUserStore()
  const value = binding.value

  if (!value) {
    // 没传值属于用错了指令，开发期直接暴露出来，不要静默放行
    console.warn('[v-permission] 缺少权限点，用法：v-permission="\'sys:user:create\'"')
    return false
  }

  const codes = Array.isArray(value) ? value : [value]
  if (codes.length === 0) return false

  return binding.modifiers.all
    ? codes.every((code) => userStore.can(code))
    : codes.some((code) => userStore.can(code))
}

/**
 * 无权限时移除元素而不是 display:none —— 后者在 DOM 里仍然存在，
 * 用开发者工具改一下 style 就“恢复”了，容易被误认为绕过了权限。
 */
export const permission: Directive<HTMLElement, string | string[]> = {
  mounted(el, binding) {
    if (!check(binding)) {
      el.parentNode?.removeChild(el)
    }
  }
}

/** 角色指令：极少数按角色而非权限点控制的场景 */
export const role: Directive<HTMLElement, string | string[]> = {
  mounted(el, binding) {
    const userStore = useUserStore()
    const codes = Array.isArray(binding.value) ? binding.value : [binding.value]
    const owned = userStore.profile?.roles ?? []
    const ok = binding.modifiers.all
      ? codes.every((c) => owned.includes(c))
      : codes.some((c) => owned.includes(c))

    if (!ok) el.parentNode?.removeChild(el)
  }
}

export default {
  install(app: { directive: (name: string, dir: Directive) => void }) {
    app.directive('permission', permission as Directive)
    app.directive('role', role as Directive)
  }
}
