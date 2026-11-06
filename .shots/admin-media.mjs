/**
 * Проверка библиотеки файлов живым браузером.
 *
 * Повод — раздела «Файлы» в админке не существовало: модуль Media отдавал пять
 * эндпоинтов, но посмотреть файлы организации было негде. Статический HTML
 * ничего не доказывает: список приходит своим запросом, а картинки рисуются по
 * абсолютному адресу из `APP_URL`.
 *
 * Запуск:
 *   node .shots/admin-media.mjs <token> [baseUrl]
 *
 * `baseUrl` нужен потому, что `APP_URL` в `.env` — заглушка
 * (`https://tickets.example.com`), и браузер не может открыть картинку по этому
 * адресу: DNS не разрешается. Сервер, запущенный с
 * `APP_URL=http://127.0.0.1:8001`, отдаёт абсолютные адреса, которые
 * действительно открываются, — и на нём видно, что превью рисуются, а не только
 * присутствуют в разметке.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9424
const BASE = process.argv[3] ?? 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-media-lib',
    '--window-size=1440,1400',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function target() {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const list = await res.json()
      const page = list.find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* not up yet */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const t = await target()
const ws = new WebSocket(t.webSocketDebuggerUrl)
const pending = new Map()
const consoleErrors = []
let seq = 0

function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}

ws.addEventListener('message', (e) => {
  const msg = JSON.parse(e.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    consoleErrors.push(
      'EXC: ' + (msg.params?.exceptionDetails?.exception?.description ?? msg.params?.exceptionDetails?.text),
    )
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
    consoleErrors.push(
      'CONSOLE.error: ' + (msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ').slice(0, 240),
    )
  }
})

await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expression) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return r?.result?.value ?? r?.exceptionDetails?.text ?? ''
}

async function waitFor(expression, label, tries = 40) {
  for (let i = 0; i < tries; i++) {
    if ((await ev(expression)) === true) return true
    await sleep(500)
  }
  throw new Error(`не дождались: ${label}`)
}

async function shot(name) {
  const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  if (r?.data) {
    writeFileSync(`.shots/${name}`, Buffer.from(r.data, 'base64'))
    console.log(`скриншот: .shots/${name}`)
  }
}

/* ── Страница файлов ────────────────────────────────────────────────────── */
await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/media'`)
await sleep(1200)
await ev(`location.reload()`)

await waitFor(`[...document.querySelectorAll('h1')].some((x) => x.textContent.trim() === 'Файлы')`, 'заголовок «Файлы»')

// Список приходит своим запросом: заголовок рисуется сразу, а «Загрузка файлов…»
// живёт до ответа сервера.
await waitFor(`!/Загрузка файлов/.test(document.body.innerText)`, 'список догрузился')

// И пока изображения не перестанут быть «в пути»: иначе скриншот покажет пустые
// рамки, и «не отрисовалось» неотличимо от «ещё грузится».
await waitFor(`[...document.querySelectorAll('img')].every((i) => i.complete)`, 'превью догрузились')

console.log('\n=== Библиотека файлов ===')
console.log(
  await ev(`(() => {
    const h1 = [...document.querySelectorAll('h1')].find((x) => x.textContent.trim() === 'Файлы');
    const cards = [...document.querySelectorAll('li')].filter((li) => li.querySelector('img, [aria-hidden="true"]'));
    const imgs = [...document.querySelectorAll('img')];
    return JSON.stringify({
      heading: h1 ? h1.textContent.trim() : 'ЗАГОЛОВКА НЕТ',
      counter: h1 ? h1.parentElement.querySelector('p')?.textContent.trim() : null,
      cards: cards.length,
      thumbnails: imgs.length,
      brokenImages: imgs.filter((i) => i.complete && i.naturalWidth === 0).length,
      usageBadges: [...document.querySelectorAll('li.rounded-full')].map((s) => s.textContent.trim()).slice(0, 6),
      unusedLabels: [...document.querySelectorAll('p')].filter((p) => p.textContent.trim() === 'нигде не используется').length,
      hasDropZone: /Перетащите файлы/.test(document.body.innerText),
    }, null, 1);
  })()`),
)

await shot('admin-media.png')

/* ── Постраничность ─────────────────────────────────────────────────────── */
const pagerState = `(() => {
  const buttons = [...document.querySelectorAll('button')];
  const next = buttons.find((b) => b.textContent.trim() === 'Вперёд');
  const prev = buttons.find((b) => b.textContent.trim() === 'Назад');
  return JSON.stringify({
    hasPager: !!next && !!prev,
    label: (document.body.innerText.match(/Страница \\d+ из \\d+/) || ['ПЕЙДЖЕРА НЕТ'])[0],
    prevDisabled: prev ? prev.disabled : null,
    nextDisabled: next ? next.disabled : null,
  }, null, 1);
})()`

console.log('\n=== Постраничность: первая страница ===')
console.log(await ev(pagerState))

await ev(`(() => {
  const next = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Вперёд');
  if (next) next.click();
  return !!next;
})()`)
await sleep(1500)
await waitFor(`/Страница 2 из 2/.test(document.body.innerText)`, 'вторая страница')

console.log('\n=== Постраничность: вторая страница ===')
console.log(await ev(pagerState))
console.log(
  await ev(`(() => JSON.stringify({
    cards: [...document.querySelectorAll('li')].filter((li) => li.querySelector('img, [aria-hidden="true"]')).length,
  }, null, 1))()`),
)

await shot('admin-media-page2.png')

/* ── Модалка метаданных ─────────────────────────────────────────────────── */
await ev(`(() => {
  const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Изменить');
  if (btn) btn.click();
  return !!btn;
})()`)
await sleep(900)

console.log('\n=== Модалка метаданных ===')
console.log(
  await ev(`(() => {
    const dlg = document.querySelector('[role="dialog"]') || document.querySelector('.fixed.inset-0');
    if (!dlg) return 'ОКНА НЕТ';
    return JSON.stringify({
      text: dlg.innerText.replace(/\\s+/g, ' ').slice(0, 200),
      inputs: dlg.querySelectorAll('input').length,
      values: [...dlg.querySelectorAll('input')].map((i) => i.value),
    }, null, 1);
  })()`),
)

await shot('admin-media-metadata.png')

console.log('\n=== Ошибки консоли ===')
console.log(consoleErrors.length ? consoleErrors.join('\n') : 'нет')

ws.close()
chrome.kill()
