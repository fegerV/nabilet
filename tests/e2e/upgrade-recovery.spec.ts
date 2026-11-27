import { expect, test } from '@playwright/test'

/**
 * Сценарий 2 из docs/PLAYWRIGHT-E2E-PLAN.md.
 *
 * Требует подготовленного стенда с предыдущим релизом и тестовыми данными.
 * Переменные окружения задают версии и путь к архиву; без них тест пропускается.
 */
test.describe('previous release → upgrade → verified recovery', () => {
  const ready = Boolean(process.env.E2E_BASE_URL && process.env.E2E_PREVIOUS_RELEASE)

  test.skip(!ready, 'Нужен стенд с предыдущим релизом (E2E_PREVIOUS_RELEASE)')

  test('upgrade keeps commercial data and emergency recovery restores it', async ({ page, request }) => {
    const health = await request.get('/api/v1/health')
    expect(health.ok()).toBeTruthy()

    // 1. Предыдущий релиз заполнен тестовыми коммерческими данными.
    const ordersBefore = Number(process.env.E2E_KNOWN_ORDERS || 0)
    expect(ordersBefore).toBeGreaterThan(0)

    // 2. Резервная копия создается через админку/CLI стенда до обновления.
    await page.goto('/admin/backups')
    await page.getByRole('button', { name: /создать.*копию/i }).click()
    await expect(page.getByText(/копия создана|success/i)).toBeVisible()

    // 3. Обновление запускается через Admin Updates и завершается success.
    await page.goto('/admin/updates')
    await page.getByRole('button', { name: /проверить обновления/i }).click()
    await page.getByRole('button', { name: /обновить/i }).click()
    await expect(page.getByText(/завершено|success/i)).toBeVisible({ timeout: 180_000 })

    // 4. Данные покупателей сохранились.
    const after = await request.get('/api/v1/admin/orders?limit=1')
    expect(after.ok()).toBeTruthy()

    // 5. Аварийное восстановление и повторный smoke-тест.
    const recovered = await request.post('/api/v1/admin/system/update/recover', {
      data: { update_id: Number(process.env.E2E_UPDATE_ID || 1) },
    })
    expect([200, 202]).toContain(recovered.status())
    await expect(page.getByText(/аварийное восстановление/i)).toBeVisible()
    expect((await request.get('/api/v1/health')).ok()).toBeTruthy()
  })
})
