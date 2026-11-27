import { expect, test } from '@playwright/test'

/**
 * Сценарий 1 из docs/PLAYWRIGHT-E2E-PLAN.md.
 *
 * Запускается только с E2E_BASE_URL, указывающим на отдельный test-стенд
 * (отдельная БД, demo-платежи). Рабочая БД никогда не используется.
 */
test.describe('clean install → ticket → refund', () => {
  test.skip(!process.env.E2E_BASE_URL, 'E2E_BASE_URL не задан — тест-стенд не выбран')

  test('administrator publishes a hall, buyer purchases and refunds a ticket', async ({ page, request }) => {
    const adminEmail = process.env.E2E_ADMIN_EMAIL || 'admin@example.test'
    const adminPassword = process.env.E2E_ADMIN_PASSWORD || 'password'

    // 1. Чистая установка: health и закрытый installer.
    const health = await request.get('/api/v1/health')
    expect(health.ok()).toBeTruthy()
    const installer = await request.get('/install')
    expect(installer.status()).toBe(403)

    // 2. Вход администратора.
    await page.goto('/admin/login')
    await page.getByLabel('Email').fill(adminEmail)
    await page.getByLabel('Пароль').fill(adminPassword)
    await page.getByRole('button', { name: /войти/i }).click()
    await expect(page).toHaveURL(/\/admin/)

    // 3. Площадка.
    await page.goto('/admin/venues')
    await page.getByRole('button', { name: /создать|новая площадка/i }).click()
    await page.getByLabel('Название').fill('E2E Площадка')
    await page.getByRole('button', { name: /сохранить/i }).click()
    await expect(page.getByText('E2E Площадка')).toBeVisible()

    // 4–6. Импорт схемы, сверка отчёта и публикация.
    await page.goto('/admin/halls')
    await page.getByRole('button', { name: /импорт/i }).click()
    await page.locator('input[type=file]').setInputFiles('tests/Fixtures/hallplans/surgu.json')
    await expect(page.getByText(/восстановлено/)).toBeVisible()
    await page.getByRole('button', { name: /опубликовать/i }).click()
    await expect(page.getByText(/опубликована/)).toBeVisible()

    // 7. Мероприятие и сеанс.
    await page.goto('/admin/events/create')
    await page.getByLabel('Название').fill('E2E Концерт')
    await page.getByLabel('Площадка').selectOption({ label: 'E2E Площадка' })
    await page.getByRole('button', { name: /сохранить/i }).click()
    await expect(page.getByText('E2E Концерт')).toBeVisible()

    // 8. Шаблон билета (вертикальный) и назначение.
    await page.goto('/admin/ticket-templates')
    await expect(page.getByText(/шаблон/i).first()).toBeVisible()

    // 9–11. Покупка в тестовом режиме, выпуск билета, PDF.
    await page.goto('/')
    await page.getByText('E2E Концерт').click()
    await page.getByRole('button', { name: /билет/i }).first().click()
    await page.getByRole('button', { name: /купить/i }).click()
    await page.getByLabel('Email').fill('buyer@example.test')
    await page.getByRole('button', { name: /оплатить/i }).click()
    await expect(page.getByText(/билет оформлен/i)).toBeVisible()
    const pdf = await page.waitForEvent('download')
    expect(pdf.suggestedFilename()).toMatch(/\.pdf$/)

    // 12. QR из PDF проверяется отдельным декодером в PHPUnit (TicketQrDecodeTest);
    // здесь фиксируем, что страница билета содержит подписанный payload.
    await expect(page.getByText(/NB1\./)).toBeVisible()

    // 13. Возврат.
    await page.goto('/admin/orders')
    await page.getByRole('button', { name: /возврат/i }).first().click()
    await expect(page.getByText(/возвращён/i)).toBeVisible()
  })
})
