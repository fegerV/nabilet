import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// Тесты живут в tests/Unit (модуль @nabilet/core) и рядом с компонентами.
// Среда — jsdom: компоненты Vue/Konva-обёртки монтируются через @vue/test-utils.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    environmentOptions: {
      jsdom: {
        url: 'http://localhost/',
      },
    },
    globals: true,
    include: ['tests/Unit/**/*.{test,spec}.{ts,js}', 'resources/js/**/*.{test,spec}.ts'],
    exclude: ['**/node_modules/**', '**/vendor/**', '**/dist/**'],
  },
})
