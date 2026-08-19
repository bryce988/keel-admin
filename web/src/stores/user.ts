import { acceptHMRUpdate, defineStore } from 'pinia'
import request from '@/utils/request'

/**
 * 接口数据结构一律 snake_case，与后端字段名逐字一致（docs/api.md §1.4）。
 * 只有 TS 变量、组件 props、store getter 这类**前端自己的标识符**才用小驼峰。
 */
export interface MenuNode {
  id: number
  name: string
  path: string
  component: string
  icon: string
  perm_code: string
  visible: boolean
  keep_alive: boolean
  children?: MenuNode[]
}

export interface Profile {
  user: {
    id: number
    username: string
    real_name: string
    avatar: string
    dept_id: number
    dept_name: string
    is_super: boolean
  }
  roles: string[]
  permissions: string[]
  data_scope: number
  menus: MenuNode[]
}

const TOKEN_KEY = 'keel_token'
const REFRESH_KEY = 'keel_refresh_token'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    profile: null as Profile | null,
    loaded: false,
    /** 菜单驱动的动态路由是否已注册，由 router/index.ts 维护 */
    routesLoaded: false
  }),

  getters: {
    nickname: (s) => s.profile?.user.real_name || s.profile?.user.username || '',
    isSuper: (s) => s.profile?.user.is_super ?? false,
    menus: (s) => s.profile?.menus ?? []
  },

  actions: {
    async login(payload: {
      username: string
      password: string
      captcha_key: string
      captcha_code: string
    }) {
      const data = await request.post<
        unknown,
        {
          access_token: string
          refresh_token: string
          expires_in: number
          must_change_password: boolean
        }
      >('/admin/auth/login', payload)

      this.token = data.access_token
      localStorage.setItem(TOKEN_KEY, data.access_token)
      localStorage.setItem(REFRESH_KEY, data.refresh_token)

      return data
    },

    /**
     * 局部更新当前用户的基本信息
     *
     * 个人中心改完姓名/头像后调一次。不重新拉整个 profile：那会连
     * 菜单和权限一起换掉，动态路由要跟着重注册，而这次改动跟权限毫无关系。
     * 顶栏的昵称是从这里派生的 getter，不同步的话改完右上角还是旧名字，
     * 刷新一下又对了——最招人烦的那类 bug。
     */
    patchUser(partial: Partial<Profile['user']>) {
      if (this.profile) {
        this.profile.user = { ...this.profile.user, ...partial }
      }
    },

    /** 登录后第一个请求：拿到用户、角色、权限、菜单 */
    async fetchProfile() {
      const data = await request.get<unknown, Profile>('/admin/auth/profile')
      this.profile = data
      this.loaded = true
      return data
    },

    async logout(silent = false) {
      if (!silent) {
        try {
          await request.post('/admin/auth/logout')
        } catch {
          // 登出失败不阻塞前端清理
        }
      }
      this.reset()
    },

    reset() {
      this.token = ''
      this.profile = null
      this.loaded = false
      this.routesLoaded = false
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(REFRESH_KEY)
    },

    /** 权限判断：超级管理员的 permissions 为 ["*"] */
    can(code: string) {
      const perms = this.profile?.permissions ?? []
      return perms.includes('*') || perms.includes(code)
    }
  }
})

/**
 * Pinia 的 HMR 支持
 *
 * 不加这段，热更时 store 定义被替换、已挂载的组件却仍持有旧实例，
 * 表现为「接口明明返回了数据，界面就是不更新」，而且刷新一下又好了——
 * 最难查的一类问题。开发期才有影响，生产构建不会走到。
 */
if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useUserStore, import.meta.hot))
}
