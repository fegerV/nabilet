import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  // Абсолютные пути от корня: приложение монтируется и на /, и на под-путях
  // вроде /event/:slug/seats. С base './' динамические import("./Chunk...") ломаются
  // на SEO-страницах (резолвятся относительно под-пути → 404 → пустой #app).
  base: '/',
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 900,
  },
})
