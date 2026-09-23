import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * Отдельная конфигурация только для предпросмотра дизайна.
 *
 * Продакшен-сборка остаётся с разделением на чанки — там это правильно.
 * Здесь же нужен один самодостаточный файл: дизайн-ревью открывают пересылкой,
 * а не разворачиванием сервера, и битые относительные пути убивают впечатление
 * быстрее, чем любой спор о цвете.
 */
export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
  },
  build: {
    outDir: 'dist-preview',
    emptyOutDir: true,
    cssCodeSplit: false,
    assetsInlineLimit: 100_000_000,
    rollupOptions: {
      output: { inlineDynamicImports: true },
    },
  },
})
