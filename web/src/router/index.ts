import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { setUnauthorizedHandler } from '@/utils/request'
import { buildRoutes, firstMenuPath } from './dynamic'

/**
 * 五种页型模板，只在开发环境注册
 *
 * 两个目的，缺一不可：
 *   · 能打开——模板要真跑得起来才算数。只放在目录里不引用的话，
 *     vite 根本不会编译它们（未被 import 的 .vue 不进构建），
 *     组件签名变了也没人知道，第一个复制的人才踩坑
 *   · 不上线——它们是给开发者看的脚手架，不该出现在生产包里
 *
 * `import.meta.env.DEV` 在 `vite build` 时是 false（`vite build` 固定
 * NODE_ENV=production，加 `--mode development` 也一样），整个数组被折掉。
 * 另一半在 `dynamic.ts`：那里的 glob 也排除了 template 目录，
 * 否则模板会被当成业务页面一并打进生产包。
 *
 * 验证模板能否编译：请求 dev server 的模块地址，写错会 500
 * （见 views/template/README.md）。vue-tsc 查不出模板结构问题。
 */
const templateRoutes: RouteRecordRaw[] = import.meta.env.DEV
  ? [
      {
        path: 'template/list',
        component: () => import('@/views/template/list/index.vue'),
        meta: { title: '模板·标准列表页' }
      },
      {
        path: 'template/tree-list',
        component: () => import('@/views/template/tree-list/index.vue'),
        meta: { title: '模板·树表联动页' }
      },
      {
        path: 'template/master-detail',
        component: () => import('@/views/template/master-detail/index.vue'),
        meta: { title: '模板·主从页' }
      },
      {
        path: 'template/form',
        component: () => import('@/views/template/form/index.vue'),
        meta: { title: '模板·表单页' }
      },
      {
        path: 'template/detail/:id?',
        component: () => import('@/views/template/detail/index.vue'),
        meta: { title: '模板·详情页' }
      }
    ]
  : []

/**
 * 静态路由：登录页、布局壳、错误页
 *
 * 业务页面不在这里，由 /admin/auth/profile 下发的菜单树动态注册（见 dynamic.ts）。
 * 没有下发的菜单在前端就不存在这条路由，直接敲 URL 会落到 404。
 */
const staticRoutes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/login/index.vue'),
    meta: { public: true, title: '登录' }
  },
  {
    path: '/',
    name: 'layout',
    component: () => import('@/layout/index.vue'),
    children: [
      /*
       * 个人中心是静态子路由，不由菜单下发
       *
       * 它跟权限无关——任何登录用户都该能改自己的资料，所以既不该出现在
       * 菜单树里，也不该因为管理员没勾某个菜单就消失。同理它没有权限点：
       * 服务端的 id 取自令牌，不存在「改别人」的路径（docs/api.md §11）。
       */
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/views/profile/index.vue'),
        meta: { title: '个人中心' }
      },
      ...templateRoutes
    ]
  },
  {
    path: '/403',
    name: 'forbidden',
    component: () => import('@/views/error/403.vue'),
    meta: { public: true, title: '无权限' }
  },
  // 通配必须排在最后声明；vue-router 按匹配度而非顺序择优，
  // 但动态路由注册前的访问会先落到这里，由守卫补注册后重新解析
  {
    path: '/:pathMatch(.*)*',
    name: 'notFound',
    component: () => import('@/views/error/404.vue'),
    meta: { public: true, title: '页面不存在' }
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes: staticRoutes
})

/** 动态注册返回的卸载函数，登出时逐个调用，避免换账号后残留上个账号的路由 */
let removers: Array<() => void> = []

function registerDynamicRoutes(): void {
  const userStore = useUserStore()

  removers = buildRoutes(userStore.menus).map((route) => router.addRoute('layout', route))

  userStore.routesLoaded = true
}

export function resetDynamicRoutes(): void {
  removers.forEach((remove) => remove())
  removers = []
}

/**
 * 路由守卫
 * 未登录 → 跳登录页并记录来源
 * 已登录但未拉过 profile → 先取用户信息与菜单，再注册路由并重新解析本次跳转
 */
router.beforeEach(async (to) => {
  const userStore = useUserStore()

  if (to.meta.public && to.name !== 'notFound') {
    return true
  }

  if (!userStore.token) {
    return to.path === '/login' ? true : { path: '/login', query: { redirect: to.fullPath } }
  }

  if (!userStore.loaded) {
    try {
      await userStore.fetchProfile()
    } catch {
      userStore.reset()
      return { path: '/login', query: { redirect: to.fullPath } }
    }
  }

  if (!userStore.routesLoaded) {
    registerDynamicRoutes()
    /*
     * 重新解析这次跳转，三个字段都要手写
     *
     * 两个都踩过的坑：
     *   · `{ path: to.fullPath }` —— vue-router 的 `path` 不解析查询串，
     *     `/system/user?dept_id=2` 整个被当成路径，问号后面那段丢掉。
     *     表现是「带筛选条件的链接一刷新就退回无筛选状态」
     *   · `{ ...to }` —— 会把 `name` 一起带过去，而此刻 `to.name` 是
     *     `notFound`（动态路由还没注册时匹配到的通配）。name 的优先级高于
     *     path，于是重解析又回到 404 页
     *
     * 只带 path / query / hash 才两头都躲开。
     * replace 则是避免在历史里留下 404 那一跳。
     *
     * 这条分支只在整页加载时走到，SPA 内部点击一切正常，
     * 所以这类 bug 很难联想到守卫上——改这里务必刷新验一遍。
     */
    return { path: to.path, query: to.query, hash: to.hash, replace: true }
  }

  /**
   * 落地页在这里算，而不是注册一条 { path: '/', redirect } 路由：
   * 布局路由本身就占着 '/'，两条同路径记录里先注册的赢，重定向永远不会生效，
   * 表现为访问根路径只有空白的内容区。
   */
  if (to.path === '/') {
    const home = firstMenuPath(userStore.menus)
    return home ? { path: home, replace: true } : { name: 'notFound' }
  }

  return true
})

/**
 * 写标题：`页面名 · 站点名`
 *
 * 读的是当前路由而不是入参，因为它有两个调用时机：路由跳转之后，
 * 以及 `sys.name` 从后端到达之后（`main.ts` 里）。后者没有「to」可传。
 *
 * 站点名每次现读 store，不缓存到模块变量——参数是异步到的，
 * 缓存下来就成了「首屏之后改不了」。
 */
export function applyDocumentTitle(): void {
  const site = useAppStore().site.name
  const title = (router.currentRoute.value.meta.title as string) || ''
  document.title = title ? `${title} · ${site}` : site
}

router.afterEach(() => applyDocumentTitle())

// 拦截器里收到 401 时统一由这里处理跳转
setUnauthorizedHandler(() => {
  const userStore = useUserStore()
  userStore.reset()
  resetDynamicRoutes()
  router.replace({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
})

export default router
