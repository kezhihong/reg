import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 开发代理：/api → 后端（生产由 nginx 同源反代，docs/05 §2.2）
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:9501',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
  },
})
