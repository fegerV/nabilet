import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright E2E для NABILET.
 *
 * Тесты НЕ запускаются без явно заданного E2E_BASE_URL: это защита от
 * случайного прогона по рабочей базе/платежным ключам. Локальный запуск:
 *
 *   E2E_BASE_URL=http://127.0.0.1:8080 npm run e2e
 *
 * Стенд должен быть поднят на отдельной БД с demo-платежами (см.
 * docs/PLAYWRIGHT-E2E-PLAN.md).
 */
const baseURL = process.env.E2E_BASE_URL || ''

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: Number(process.env.E2E_WORKERS || 1),
  retries: Number(process.env.E2E_RETRIES || 0),
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  timeout: 120_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: baseURL || 'http://127.0.0.1:8080',
    // Loopback may be blocked by the browser sandbox in restricted runners.
    launchOptions: { args: ['--no-sandbox', '--disable-dev-shm-usage'] },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ...devices['Desktop Chrome'],
  },
  outputDir: 'test-results',
})
