const { chromium } = require('C:/Project/Ticket_Admin/frontend/node_modules/playwright-core');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({ executablePath: 'C:/Users/Professional/AppData/Local/ms-playwright/chromium-1194/chrome-win/chrome.exe', headless: true });
  const detail = { public_id: 'sample', title: 'Розыгрыш концерта', description: 'Призы участникам', status: 'active', starts_at: '2026-11-01T12:00:42Z', ends_at: null, drawn_at: null, entries_count: 20, prizes: [{ public_id: 'p1', place: 1, title: 'Приглашение', description: '' }], winners: [] };
  const writes = [], errors = [];
  try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => {
      localStorage.setItem('nabilet_admin_token', 'ui-test-only');
      localStorage.setItem('nabilet_admin_user', JSON.stringify({ id: 1, name: 'UI check', roles: ['admin'] }));
    });
    await page.route('http://127.0.0.1:8000/**', async route => {
      const response = await fetch(route.request().url());
      await route.fulfill({ status: response.status, contentType: response.headers.get('content-type') || 'text/plain', body: Buffer.from(await response.arrayBuffer()) });
    });
    await page.route('**/api/v1/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (route.request().method() === 'PUT') { const body = route.request().postDataJSON(); writes.push(body); Object.assign(detail, body); }
      await route.fulfill({ json: { data: path.endsWith('/raffles/sample') ? detail : path.endsWith('/raffles') ? [{ ...detail, prizes_count: 1, winners_count: 0 }] : [] } });
    });
    await page.goto('http://127.0.0.1:8000/admin/raffles');
    await page.getByRole('button', { name: 'Открыть', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Название', { exact: true }).fill('Исправленное название');
    assert(await dialog.getByLabel('Окно с', { exact: true }).isDisabled());
    await dialog.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(writes.length, 1);
    assert(!('prizes' in writes[0]));
    assert(!('starts_at' in writes[0]));
    await page.getByRole('button', { name: 'Создать розыгрыш', exact: true }).click();
    await dialog.getByLabel('Название', { exact: true }).fill('Новый концерт');
    await dialog.getByLabel('Приз', { exact: true }).fill('Первый приз');
    await dialog.getByRole('button', { name: 'Добавить место' }).click();
    await dialog.getByLabel('Приз', { exact: true }).nth(1).fill('Второй приз');
    await dialog.getByLabel('Место', { exact: true }).nth(1).fill('1');
    assert(await dialog.getByRole('button', { name: 'Создать', exact: true }).isDisabled());
    assert(await dialog.getByText('Призовые места не должны повторяться').isVisible());
    await page.screenshot({ path: 'outputs/raffles-review/mobile.png', fullPage: true });
    const size = await dialog.evaluate(el => ({ width: el.clientWidth, content: el.scrollWidth, height: el.clientHeight, viewport: innerHeight }));
    assert(size.content <= size.width);
    assert(size.height < size.viewport);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.screenshot({ path: 'outputs/raffles-review/desktop.png', fullPage: true });
    assert.deepEqual(errors, []);
    fs.writeFileSync('outputs/raffles-review/result.json', JSON.stringify({ writes, size, errors }, null, 2));
    console.log(JSON.stringify({ writes, size, errors }));
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
