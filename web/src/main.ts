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
import directives from './directives/permission'
import components from './components'

const app = createApp(App)

app.use(createPinia())
app.use(router)
/**
 * 全局尺寸 small（24px / 12px），不是默认的 32px / 14px
 *
 * 后台是密集型界面，default 尺寸在一屏里堆按钮与筛选框显得笨重。
 * 走全局配置而不是逐个加 `size="small"`：67 个按钮逐个加迟早漏一个，
 * 而且按钮与输入框必须同尺寸——搜索栏里它们并排，差一截底边就对不齐。
 * 表格行高由 ProTable 的密度开关单独控制，不受这里影响。
 */
app.use(ElementPlus, { locale: zhCn, size: 'small' })
app.use(directives) // v-permission / v-role
app.use(components) // ProTable / SearchForm / DictSelect / DictTag

// 图标全局注册：菜单的 icon 字段由后端下发，需要按名解析
for (const [name, comp] of Object.entries(ElIcons)) {
  app.component(name, comp)
}

// 主题在挂载前应用，避免首屏闪白
useAppStore().initTheme()

app.mount('#app')
