import { defineStore } from 'pinia'

const SIDEBAR_KEY = 'keel_sidebar_collapsed'
const THEME_KEY = 'keel_theme'

export type ThemeMode = 'light' | 'dark'

/** 全局界面状态：侧栏折叠、主题，均持久化到 localStorage */
export const useAppStore = defineStore('app', {
  state: () => ({
    sidebarCollapsed: localStorage.getItem(SIDEBAR_KEY) === '1',
    theme: (localStorage.getItem(THEME_KEY) as ThemeMode) || 'light'
  }),

  actions: {
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      localStorage.setItem(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0')
    },

    toggleTheme() {
      this.setTheme(this.theme === 'dark' ? 'light' : 'dark')
    },

    setTheme(mode: ThemeMode) {
      this.theme = mode
      localStorage.setItem(THEME_KEY, mode)
      document.documentElement.classList.toggle('dark', mode === 'dark')
    },

    initTheme() {
      this.setTheme(this.theme)
    }
  }
})
