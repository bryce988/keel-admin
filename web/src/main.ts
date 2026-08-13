import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElIcons from '@element-plus/icons-vue'

import 'element-plus/dist/index.css'
import 'element-plus/theme-chalk/dark/css-vars.css' // 深色模式令牌
import './styles/index.css'

import App from './App.vue'
import router from './router'
import { useAppStore } from './stores/app'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })

// 图标全局注册：菜单的 icon 字段由后端下发，需要按名解析
for (const [name, comp] of Object.entries(ElIcons)) {
  app.component(name, comp)
}

// 主题在挂载前应用，避免首屏闪白
useAppStore().initTheme()

app.mount('#app')
