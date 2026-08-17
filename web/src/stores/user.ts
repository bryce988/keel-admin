import { defineStore } from 'pinia'
import request from '@/utils/request'

export interface MenuNode {
  id: number
  name: string
  path: string
  component: string
  icon: string
  permCode: string
  visible: boolean
  keepAlive: boolean
  children?: MenuNode[]
}

export interface Profile {
  user: {
    id: number
    username: string
    realName: string
    avatar: string
    deptId: number
    deptName: string
    isSuper: boolean
  }
  roles: string[]
  permissions: string[]
  dataScope: number
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
    nickname: (s) => s.profile?.user.realName || s.profile?.user.username || '',
    isSuper: (s) => s.profile?.user.isSuper ?? false,
    menus: (s) => s.profile?.menus ?? []
  },

  actions: {
    async login(payload: {
      username: string
      password: string
      captchaKey: string
      captchaCode: string
    }) {
      const data = await request.post<
        unknown,
        { accessToken: string; refreshToken: string; expiresIn: number; mustChangePassword: boolean }
      >('/admin/auth/login', payload)

      this.token = data.accessToken
      localStorage.setItem(TOKEN_KEY, data.accessToken)
      localStorage.setItem(REFRESH_KEY, data.refreshToken)

      return data
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
