import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { setUnauthorizedHandler } from '@/utils/request'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/dashboard' },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/login/index.vue'),
      meta: { public: true, title: '登录' }
    },
    {
      path: '/',
      component: () => import('@/layout/index.vue'),
      children: [
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/dashboard/index.vue'),
          meta: { title: '系统概览' }
        }
      ]
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' }
  ]
})

/**
 * 路由守卫
 * 未登录访问受控页面 → 跳登录页并记录来源
 * 已登录但未加载过 profile → 先拉取用户信息与菜单
 */
router.beforeEach(async (to) => {
  const userStore = useUserStore()

  if (to.meta.public) {
    return true
  }

  if (!userStore.token) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  if (!userStore.loaded) {
    try {
      await userStore.fetchProfile()
    } catch {
      userStore.reset()
      return { path: '/login', query: { redirect: to.fullPath } }
    }
  }

  return true
})

// 拦截器里收到 401 时统一由这里处理跳转
setUnauthorizedHandler(() => {
  const userStore = useUserStore()
  userStore.reset()
  router.replace({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
})

export default router
