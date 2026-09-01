import { useRouter } from 'vue-router'
import { resetDynamicRoutes } from '@/router'
import { useDictStore } from '@/stores/dict'
import { useNoticeStore } from '@/stores/notice'
import { useTagsViewStore } from '@/stores/tagsView'
import { useUserStore } from '@/stores/user'

/**
 * 退出登录
 *
 * 登出要清五样东西：登录态、页签、字典缓存、未读消息、动态路由。
 * 少清任何一样，换账号后都会残留上一个账号的痕迹——
 * 最刺眼的是动态路由：新账号明明没这个菜单，直接敲 URL 却进得去。
 *
 * 抽成 composable 而不是在每个调用点各写一遍：现在有顶栏退出、
 * 顶栏改密、个人中心改密三个入口，往后只会更多，
 * 而漏掉一句的表现是「偶尔串账号」，很难复现也很难查。
 *
 * 不做成 store action 是为了避开循环依赖：store 里 import 路由、
 * 路由守卫里又 import store，绕回来就是启动期 undefined。
 */
export function useSignOut() {
  const router = useRouter()
  const userStore = useUserStore()
  const tagsStore = useTagsViewStore()
  const dictStore = useDictStore()
  const noticeStore = useNoticeStore()

  return async function signOut(): Promise<void> {
    await userStore.logout()
    tagsStore.reset()
    dictStore.forget()
    // 铃铛的角标不清的话，下一个人登录进来会先看到上一个人的未读数，
    // 直到第一次轮询回来才纠正
    noticeStore.reset()
    resetDynamicRoutes()
    await router.replace('/login')
  }
}
