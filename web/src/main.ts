import { createApp } from 'vue'
import { createPinia } from 'pinia'

/*
 * Element Plus 走按需导入（配置见 vite.config.ts），这里不再 `app.use(ElementPlus)`，
 * 也不再 `import 'element-plus/dist/index.css'` —— 组件与样式都由插件按用到的部分注入。
 *
 * 只剩三类样式要手动引：
 *
 * 1. 深色模式令牌：全站的 CSS 变量表，不属于任何单个组件
 * 2. 基础重置（base）：按需导入只会带上用到的**组件**样式，
 *    EP 的 reset/base 没有任何组件引用它，漏了会看到字体与行高整体不对
 * 3. ElMessage / ElMessageBox：它们在各页面里是显式 `import { ElMessage } from 'element-plus'`
 *    的，AutoImport 对已声明的标识符不介入，也就带不上样式。
 *    不引这两行的表现是「提示框弹出来了但没有底色和边框」，
 *    而且只在生产构建里出现（dev 下 EP 的样式常被别的路径捎带进来），最容易漏
 */
import 'element-plus/theme-chalk/base.css'
import 'element-plus/theme-chalk/dark/css-vars.css'
import 'element-plus/theme-chalk/el-message.css'
import 'element-plus/theme-chalk/el-message-box.css'
import './styles/index.css'

import App from './App.vue'
import router, { applyDocumentTitle } from './router'
import { useAppStore } from './stores/app'
import directives from './directives/permission'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(directives) // v-permission / v-role

/*
 * 这里原来还有一行 `app.use(components)` 注册 ProTable / SearchForm 等通用组件。
 * 它是**多余的**：`src/components/` 本就在 unplugin-vue-components 的扫描目录里，
 * 用到的页面早已被按页注入 import。而多这一层的代价是首屏——
 * 详见 `components/index.ts` 的注释。
 */

/*
 * 这里原来把 293 个图标全部 app.component() 注册了一遍，理由是「菜单的 icon
 * 由后端下发，需要按名解析」。代价是整包图标进主 chunk，而实际用到的不足二十个。
 *
 * 现在按名解析收口在 utils/icons.ts：seed 用到的十几个静态 import，
 * 其余动态 import。模板里用到的图标一律显式 import（已全仓核对过），
 * 不依赖全局注册。
 */

/*
 * 让 store 与 DOM 上已有的 `.dark` 对齐
 *
 * 真正防闪白的是 `index.html` 里的内联脚本——module 脚本是 defer 的，
 * 跑到这一行时首帧早画完了，指望这里"挂载前应用"是防不住的。
 */
useAppStore().initTheme()

/*
 * 站点标识（系统名、Logo、页脚）从后端参数拉一次
 *
 * 刻意不 await：这是装饰性数据，让整个应用等一个网络往返不划算，
 * 后端不可用时更会把首屏卡成白屏。store 里的兜底值就是为这一刻准备的。
 * 免登录接口，所以放在挂载前、登录页也能用。
 */
void useAppStore()
  .loadSite()
  /*
   * 站点名多半比首次路由跳转晚到，那时 afterEach 已经用兜底名写过标题了，
   * 所以拿到之后要补写一次。不补的话首屏标题一直是兜底名，
   * 直到用户点第二个菜单才对——而这恰恰是最不容易被发现的那类不一致。
   */
  .then(applyDocumentTitle)

app.mount('#app')
