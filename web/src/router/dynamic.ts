import type { RouteRecordRaw } from 'vue-router'
import type { MenuNode } from '@/stores/user'

/**
 * 后端菜单树 → 前端路由
 *
 * 页面组件路径由 sys_permissions.component 下发（如 'views/system/user/index.vue'），
 * 前端不维护第二份路由表——加一个菜单只改数据库，不用发版。
 *
 * import.meta.glob 会在构建时把 views 下所有页面登记成懒加载函数；
 * 不能用 `import(变量)` 拼路径，Vite 静态分析不到就打不进包里。
 *
 * 排除 `views/template/`：那是给开发者复制用的页型模板，不是业务页面。
 * 不排的话它们会被 glob 一并打进生产包（连同 `_demo.ts` 的假数据），
 * 而且后端只要把某个菜单的 component 填成 'views/template/list/index.vue'
 * 就真能在生产环境打开一个示例页。
 */
const modules = import.meta.glob(['../views/**/*.vue', '!../views/template/**'])

function resolveComponent(path: string) {
  if (!path || path === 'Layout') return null

  // 后端给的是 'views/xxx/index.vue'，glob 的键是 '../views/xxx/index.vue'
  const key = `../${path.replace(/^\/?/, '')}`
  const loader = modules[key]

  if (!loader) {
    console.warn(`[router] 菜单指向的页面不存在：${path}（该菜单会被跳过）`)
    return null
  }

  return loader
}

/**
 * 把菜单树拍平成 layout 的子路由
 *
 * 侧边栏的层级由菜单树本身表达，路由不需要跟着嵌套——
 * 嵌套路由会要求每个目录都有一个带 <router-view> 的容器组件，
 * 而目录在这里只是分组，没有页面。
 */
export function buildRoutes(menus: MenuNode[]): RouteRecordRaw[] {
  const routes: RouteRecordRaw[] = []

  const walk = (nodes: MenuNode[], parent?: MenuNode) => {
    for (const node of nodes) {
      const component = resolveComponent(node.component)

      if (component && node.path) {
        routes.push({
          path: node.path.replace(/^\//, ''),
          name: node.perm_code || node.path,
          component,
          // meta 是前端自己的结构，沿用 vue-router 的小驼峰惯例，
          // 值来自接口的 snake_case 字段
          meta: {
            title: node.name,
            icon: node.icon,
            permCode: node.perm_code,
            keepAlive: node.keep_alive,
            // 详情页等 visible=0 的路由，高亮回它所属的分组
            parentTitle: parent?.name ?? ''
          }
        })
      }

      if (node.children?.length) walk(node.children, node)
    }
  }

  walk(menus)

  return routes
}

/** 菜单树里第一个可跳转的页面，用于登录后与访问 '/' 时的落地页 */
export function firstMenuPath(menus: MenuNode[]): string {
  for (const node of menus) {
    if (node.visible && node.path && node.component && node.component !== 'Layout') {
      return node.path
    }
    if (node.children?.length) {
      const found = firstMenuPath(node.children)
      if (found) return found
    }
  }

  return ''
}
