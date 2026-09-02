import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import type { MenuNode } from '@/stores/user'

/**
 * 菜单导航的公共推导
 *
 * 侧边版式只需要「当前高亮哪一项」；混合版式还要知道「当前属于哪个一级模块」——
 * 顶栏靠它高亮，侧栏靠它决定渲染哪一棵子树。两个组件都要用，收在这里一处算。
 *
 * ## 为什么必须从**路由**反推，而不是记住用户点了哪个顶栏项
 *
 * 用一个 ref 记住点击结果，在「点菜单」这条路径上是对的，但另外三条路径全错：
 * 刷新页面、直接粘贴 URL、从页签点回一个别的模块的页面——这些都没有点击事件，
 * 顶栏会高亮不上、侧栏会是空的。这是混合版式最容易漏的地方，所以这里只认路由。
 */
export function useMenuNav() {
  const route = useRoute()
  const userStore = useUserStore()

  /** 一级菜单（顶栏用），隐藏项不出现在导航里 */
  const topMenus = computed(() => userStore.menus.filter((m) => m.visible))

  /**
   * 当前高亮的菜单路径
   *
   * 详情页这类不在菜单里的路由，通过 `meta.activeMenu` 指回它的列表页
   * （PROJECT.md §4），否则进详情页侧栏会整个失去高亮。
   */
  const activeMenuPath = computed(() => (route.meta.activeMenu as string) || route.path)

  /** 子树里是否存在这个路径 */
  function contains(node: MenuNode, path: string): boolean {
    if (node.path === path) return true
    return (node.children ?? []).some((child) => contains(child, path))
  }

  /**
   * 当前所在的一级模块，不在菜单树里则为 null
   *
   * null 是正常状态而不是异常：个人中心、403、404 都不挂在菜单上。
   * 调用方要按「没有模块」渲染（顶栏无高亮、侧栏不显示），不能当成出错。
   */
  const activeTop = computed<MenuNode | null>(() => {
    const path = activeMenuPath.value
    return topMenus.value.find((group) => contains(group, path)) ?? null
  })

  /** 当前模块的子菜单；一级本身就是页面（不套目录的一级菜单）时为空数组 */
  const activeChildren = computed<MenuNode[]>(
    () => activeTop.value?.children?.filter((c) => c.visible) ?? []
  )

  /**
   * 点顶栏一级项时该去哪个页面
   *
   * 目录型的一级项自己没有页面，要下钻到第一个可见的叶子。
   * 逐层往下找而不是只看 children[0]：目录里还可以再套目录，
   * 只取一层的话会跳到一个同样没有 component 的节点上，表现是点了没反应。
   */
  function firstLeafPath(node: MenuNode): string {
    const visibleChildren = (node.children ?? []).filter((c) => c.visible)
    if (!visibleChildren.length) return node.path

    for (const child of visibleChildren) {
      const path = firstLeafPath(child)
      if (path) return path
    }
    return node.path
  }

  return { topMenus, activeMenuPath, activeTop, activeChildren, firstLeafPath }
}
