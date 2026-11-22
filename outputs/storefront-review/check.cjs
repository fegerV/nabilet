const { chromium } = require('C:/Project/Ticket_Admin/frontend/node_modules/playwright-core');
const fs = require('node:fs');
(async () => {
  const original = await (await fetch('http://127.0.0.1:8000/api/v1/storefront')).json();
  let config = original.data;
  config.theme.mode = 'light';
  config.theme.surface = 'tinted';
  let saves = 0;
  const browser = await chromium.launch({ executablePath: 'C:/Users/Professional/AppData/Local/ms-playwright/chromium-1194/chrome-win/chrome.exe', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => {
      localStorage.setItem('nabilet_admin_token', 'ui-test-only');
      localStorage.setItem('nabilet_admin_user', JSON.stringify({ id: 1, name: 'UI check', roles: ['admin'] }));
    });
    // This Chromium build cannot open loopback directly; serve local responses via Node.
    await page.route('http://127.0.0.1:8000/**', async route => {
      const response = await fetch(route.request().url());
      await route.fulfill({ status: response.status, contentType: response.headers.get('content-type') || 'text/plain', body: Buffer.from(await response.arrayBuffer()) });
    });
    await page.route('**/api/v1/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/admin/storefront') && route.request().method() === 'PUT') {
        config = route.request().postDataJSON().config;
        saves++;
      }
      const body = path.endsWith('/storefront')
        ? { data: config, meta: { is_default: false, organization_id: '1', warnings: [] } }
        : { data: [], meta: { total: 0, last_page: 1 } };
      await route.fulfill({ json: body });
    });
    await page.goto('http://127.0.0.1:8000/admin/storefront');
    await page.getByRole('tab', { name: 'Предпросмотр', exact: true }).click();
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    const lightCanvas = await page.locator('[data-preview-mode]').evaluate(el => getComputedStyle(el).getPropertyValue('--canvas').trim());
    if (lightCanvas !== '246 245 250') throw new Error('Light preview inherited admin theme: ' + lightCanvas);
    await page.getByRole('tab', { name: 'Цвета', exact: true }).click();
    await page.getByLabel('Тема по умолчанию', { exact: true }).selectOption('dark');
    await page.getByRole('tab', { name: 'Предпросмотр', exact: true }).click();
    const darkCanvas = await page.locator('[data-preview-mode]').evaluate(el => getComputedStyle(el).getPropertyValue('--canvas').trim());
    if (darkCanvas !== '11 9 24') throw new Error('Dark preview incorrect: ' + darkCanvas);
    await page.getByRole('tab', { name: 'Меню и подвал', exact: true }).click();
    const count = config.footer.columns.length;
    if (count >= 4) await page.getByRole('button', { name: 'Удалить колонку', exact: true }).last().click();
    await page.getByRole('button', { name: 'Добавить колонку', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.getByRole('button', { name: 'Сохранить', exact: true }).waitFor();
    await page.screenshot({ path: 'outputs/storefront-review/desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    const widths = {};
    for (const tab of ['Секции', 'Брендинг', 'Цвета', 'Меню и подвал', 'Контакты', 'Предпросмотр']) {
      await page.getByRole('tab', { name: tab, exact: true }).click();
      widths[tab] = await page.evaluate(() => ({ viewport: innerWidth, content: document.documentElement.scrollWidth }));
    }
    await page.getByRole('tab', { name: 'Меню и подвал', exact: true }).click();
    await page.screenshot({ path: 'outputs/storefront-review/mobile.png', fullPage: true });
    const result = { saves, errors, widths, lightCanvas, darkCanvas };
    if (saves !== 1 || errors.length || Object.values(widths).some(w => w.content > w.viewport)) throw new Error(JSON.stringify(result));
    fs.writeFileSync('outputs/storefront-review/result.json', JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result));
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
