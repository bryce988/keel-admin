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
    // 容器内通过服务名访问后端；本机直跑时改成 http://127.0.0.1:8787
    proxy: {
      '/admin': { target: process.env.VITE_PROXY_TARGET || 'http://server:8787', changeOrigin: true },
      '/ping':  { target: process.env.VITE_PROXY_TARGET || 'http://server:8787', changeOrigin: true }
    }
  }
})
