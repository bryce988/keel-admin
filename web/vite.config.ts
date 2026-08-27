import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [
    vue(),
    /**
     * Element Plus 按需导入
     *
     * 原来 `main.ts` 里是 `app.use(ElementPlus)` + `import 'element-plus/dist/index.css'`，
     * 整个组件库进主 chunk：1.27MB / gzip 413KB。而且 element-plus 自身依赖
     * `@element-plus/icons-vue`，全量导入会把 293 个图标一起带进来——
     * 这也是为什么单独去掉图标的全局注册一点效果都没有，得先把这里改掉。
     *
     * 两个插件分工：
     *   Components  扫模板里的 <el-xxx> 与 v-loading 这类指令，按需注入 import
     *   AutoImport  扫脚本里未声明的 ElMessage / ElMessageBox 等，注入 import + 样式
     *
     * ⚠️ 已经显式 `import { ElMessage } from 'element-plus'` 的文件，AutoImport
     * 不会介入（标识符已声明），也就不会帮它带上样式。那几个组件的样式在 main.ts
     * 里单独 import 了一次，见那边的注释。
     */
    // dts 落在 src/types/ 下是有意的：tsconfig 的 include 只覆盖 src/**，
    // 放在仓库根目录（插件默认）等于 vue-tsc 根本看不到这两份声明，
    // 模板里那些自动导入的 <el-xxx> 就得不到类型。两份声明要提交进 Git——
    // CI 是先 vue-tsc 再 vite build，不提交的话第一步跑在「声明还没生成」的状态上
    AutoImport({
      resolvers: [ElementPlusResolver()],
      dts: 'src/types/auto-imports.d.ts',
    }),
    Components({
      resolvers: [ElementPlusResolver()],
      dts: 'src/types/components.d.ts',
    }),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) }
  },
  build: {
    rollupOptions: {
      output: {
        /**
         * 手动分包：只切「整体都在首屏、且没有懒加载专属消费者」的第三方包
         *
         * 目的**不是**减少首屏字节数——这几个包本来就要在首屏下完，切开总量不变。
         * 目的是缓存粒度：它们只在升级依赖时才变，而业务代码每次发版都变。
         * 混在一个文件里，改一行业务就得让用户重下整包。
         *
         * ⚠️ 只能放**首屏本来就要加载**的包。像 `element-plus` 这种按需导入的，
         * 千万别整包切一个 chunk：`el-table` / `el-date-picker` 现在是跟着
         * ProTable / SearchForm 落在异步 chunk 里的，一旦点名进 manualChunks
         * 就会被提到首屏，等于把上面「去掉全局注册」省下的 300KB 又还回去。
         * 同理 `lodash-es`、`dayjs`、`@vueuse` 都有只被懒加载组件用到的部分，也不切。
         * EP 自己的分包交给 Rollup 按引用关系自动做，它做得对。
         */
        manualChunks: {
          // 框架与 HTTP 客户端：全站每个页面都要用
          vendor: ['vue', 'vue-router', 'pinia', 'axios'],
          // 293 个图标一个扁平文件，内容固定，最适合长期缓存
          // （为什么整包进首屏而不是懒加载，见 src/utils/icons.ts 的注释）
          icons: ['@element-plus/icons-vue']
        }
      }
    }
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    watch: {
      /**
       * Docker 的 bind mount 在 macOS / Windows 上不可靠地转发 inotify 事件：
       * 改已有文件的 HMR 正常，但**新建文件**常常不触发 import.meta.glob 重新求值。
       * 症状很隐蔽——新加的页面路由注册不上，点菜单直接落到 404，
       * 而控制台只有一条 warn，容易被当成后端没下发菜单。
       * 容器里开轮询规避；本机直跑用原生 fsevents，不必付这个开销。
       */
      usePolling: process.env.VITE_USE_POLLING === '1',
      interval: 300,
      /*
       * ⚠️ 生成的 d.ts 必须排除，否则开发时表现得像「点什么都没反应」：
       * 打开一个此前没用过的组件（比如第一次点开编辑抽屉）→ unplugin 发现新组件
       * → 重写 src/types/components.d.ts → 轮询监听捕捉到 → **整页 reload**，
       * 抽屉刚弹出就被刷没了。控制台一条错误都没有，只有 [vite] connecting 一闪而过
       */
      ignored: [
        '**/node_modules/**',
        '**/dist/**',
        '**/.git/**',
        '**/src/types/auto-imports.d.ts',
        '**/src/types/components.d.ts'
      ]
    },
    // 容器内通过服务名访问后端；本机直跑时改成 http://127.0.0.1:8787
    proxy: {
      '/admin': { target: process.env.VITE_PROXY_TARGET || 'http://server:8787', changeOrigin: true },
      '/ping':  { target: process.env.VITE_PROXY_TARGET || 'http://server:8787', changeOrigin: true },
      // 上传的头像等文件由后端的 public/ 提供，不在前端产物里，得转发过去
      '/uploads': { target: process.env.VITE_PROXY_TARGET || 'http://server:8787', changeOrigin: true }
    }
  }
})
