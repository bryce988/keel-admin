import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) }
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
      ignored: ['**/node_modules/**', '**/dist/**', '**/.git/**']
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
