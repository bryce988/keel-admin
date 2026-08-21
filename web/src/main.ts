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
 * 全局尺寸 default（32px / 14px）
 *
 * 原先是 small（24px / 12px），理由是「后台密集型界面，default 显得笨重」。
 * 实际用下来相反：12px 的正文在 1440 屏上偏小，筛选框里的长占位符
 * （「请输入操作人 / 描述 / 对象」）也塞不下。改回 default 之后
 * 字号与控件高度跟主流后台一致，可读性明显好转。
 *
 * 走全局配置而不是逐个加 size：67 个按钮逐个加迟早漏一个，
 * 而且按钮与输入框必须同尺寸——搜索栏里它们并排，差一截底边就对不齐。
 * 表格行高由 ProTable 的密度开关单独控制，不受这里影响。
 */
app.use(ElementPlus, { locale: zhCn, size: 'default' })
app.use(directives) // v-permission / v-role
app.use(components) // ProTable / SearchForm / DictSelect / DictTag

// 图标全局注册：菜单的 icon 字段由后端下发，需要按名解析
for (const [name, comp] of Object.entries(ElIcons)) {
  app.component(name, comp)
}

/*
 * 让 store 与 DOM 上已有的 `.dark` 对齐
 *
 * 真正防闪白的是 `index.html` 里的内联脚本——module 脚本是 defer 的，
 * 跑到这一行时首帧早画完了，指望这里"挂载前应用"是防不住的。
 */
useAppStore().initTheme()

app.mount('#app')
