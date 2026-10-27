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
    // Заглушки окружения — ДО импорта тестов: `stores/ui` спрашивает системную
    // тему в момент создания, а jsdom не умеет matchMedia.
    setupFiles: ['./tests/Unit/setup.ts'],
    // ПОЧЕМУ НЕ ДЕФОЛТНЫЕ 5 СЕКУНД.
    // Тесты здесь монтируют настоящие страницы (`HallEditorPage`, витрину) в
    // jsdom с двойниками Konva, и один только первый рендер раскладки редактора
    // занимает ~4.3 с на незагруженной машине — это 86% дефолтного лимита.
    // Под нагрузкой (рядом шёл полный PHPUnit) тот же тест падал по таймауту,
    // хотя код был зелёным: ровно так сторож начинает врать, а «перезапусти,
    // пока не станет зелёным» — привычка, которая обесценивает весь набор.
    // Значение — запас на загрузку машины, а не разрешение писать медленные
    // тесты: если тест перестал укладываться и в 30 с, это дефект теста.
    testTimeout: 30_000,
    include: ['tests/Unit/**/*.{test,spec}.{ts,js}', 'resources/js/**/*.{test,spec}.ts'],
    exclude: ['**/node_modules/**', '**/vendor/**', '**/dist/**'],
  },
})
