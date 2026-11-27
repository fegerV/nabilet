import { expect, test } from '@playwright/test'

/**
 * Браузерная проверка чистого стенда, поднятого job'ом `smoke` из
 * .github/workflows/release-hosting.yml. Отличается от purchase-flow тем,
 * что НЕ требует заранее подготовленных мероприятий: использует только
 * администратора, созданного мастером установки.
 *
 * Запуск в CI:
 *   E2E_BASE_URL=http://127.0.0.1:8088 \
 *   E2E_ADMIN_EMAIL=admin@smoke.local E2E_ADMIN_PASSWORD=adminpass123 \
 *   npx playwright test --grep "smoke stand"
 */
test.describe('smoke stand', () => {
  test.skip(!process.env.E2E_BASE_URL, 'Стенд не выбран (E2E_BASE_URL пуст)')

  test('installed system answers health, hides installer and lets admin log in', async ({ page, request }) => {
    const health = await request.get('/api/v1/health')
    expect(health.ok()).toBeTruthy()
    const body = await health.json()
    expect(body.version).toMatch(/^\d+\.\d+\.\d+/)

    const installer = await request.get('/install')
    expect(installer.status()).toBe(404)

    const storefront = await request.get('/')
    expect(storefront.ok()).toBeTruthy()

    await page.goto('/admin/login')
    await page.getByLabel('Email').fill(process.env.E2E_ADMIN_EMAIL ?? 'admin@smoke.local')
    await page.getByLabel('Пароль').fill(process.env.E2E_ADMIN_PASSWORD ?? 'adminpass123')
    await page.getByRole('button', { name: /войти/i }).click()

    await expect(page).toHaveURL(/\/admin/)
    await expect(page.getByLabel('Пароль')).toHaveCount(0)
  })
})
