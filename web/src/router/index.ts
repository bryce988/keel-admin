import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { setUnauthorizedHandler } from '@/utils/request'
import { buildRoutes, firstMenuPath } from './dynamic'

/**
 * 静态路由：登录页、布局壳、错误页
 *
 * 业务页面**不在这里**，由 /admin/auth/profile 下发的菜单树动态注册（见 dynamic.ts）。
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
    children: []
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

  // 首页重定向指向该账号第一个有权访问的页面，而不是写死 /dashboard
  const home = firstMenuPath(userStore.menus)
  if (home) {
    removers.push(router.addRoute({ path: '/', redirect: home }))
  }

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
    // replace 避免在历史里留下 404 那一跳
    return { path: to.fullPath, replace: true }
  }

  return true
})

router.afterEach((to) => {
  const title = (to.meta.title as string) || ''
  document.title = title ? `${title} · Keel` : 'Keel'
})

// 拦截器里收到 401 时统一由这里处理跳转
setUnauthorizedHandler(() => {
  const userStore = useUserStore()
  userStore.reset()
  resetDynamicRoutes()
  router.replace({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
})

export default router
