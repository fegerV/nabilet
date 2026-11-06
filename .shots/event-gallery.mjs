/**
 * Проверка галереи мероприятия живым браузером: админская секция
 * «Дополнительные фото и видео» и публичный блок «Фотографии и видео».
 *
 * Повод — «Дополнительные фото и видео: отсутствует полностью». Статический HTML
 * здесь ничего не доказывает: и админский список, и публичный блок зависят от
 * `media_links`, и «блок есть в разметке» не значит «файл виден».
 *
 * Запуск:
 *   node .shots/event-gallery.mjs <token> <eventId> <slug> [baseUrl]
 *
 * `baseUrl` нужен потому, что `APP_URL` в `.env` — заглушка
 * (`https://tickets.example.com`), и браузер не может открыть картинку по этому
 * адресу: DNS не разрешается. Сервер, запущенный с `APP_URL=http://127.0.0.1:8001`,
 * отдаёт абсолютные адреса, которые действительно открываются, — и на нём видно,
 * что изображения рисуются, а не только присутствуют в разметке.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9423
const BASE = process.argv[5] ?? 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const EVENT_ID = process.argv[3] ?? '1'
const SLUG = process.argv[4] ?? 'test-concert-probe'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-gallery',
    '--window-size=1440,1600',
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
    console.log('EXC:', msg.params?.exceptionDetails?.exception?.description ?? msg.params?.exceptionDetails?.text)
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
    console.log('CONSOLE.error:', (msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ').slice(0, 240))
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

/* ── 1. Админка: секция галереи ─────────────────────────────────────────── */
await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/events/${EVENT_ID}'`)
await sleep(1500)
await ev(`location.reload()`)

await waitFor(`!!document.querySelector('main form')`, 'карточка мероприятия')

// Секция грузится своим запросом, поэтому ждём именно её содержимое.
await waitFor(
  `[...document.querySelectorAll('h2')].some((x) => /Дополнительные фото и видео/i.test(x.textContent))`,
  'секция галереи',
)

// И ждём, пока список перестанет быть в состоянии загрузки: заголовок
// отрисовывается сразу, а «Загрузка галереи…» живёт до ответа сервера.
await waitFor(
  `(() => {
    const h = [...document.querySelectorAll('h2')].find((x) => /Дополнительные фото и видео/i.test(x.textContent));
    const s = h && h.closest('section');
    return !!s && !/Загрузка галереи/.test(s.innerText);
  })()`,
  'галерея догрузилась',
)

// И пока изображения не перестанут быть «в пути»: без этого скриншот
// показывает пустые рамки, и нельзя отличить «не отрисовалось» от «ещё грузится».
await waitFor(
  `[...document.querySelectorAll('img')].every((i) => i.complete)`,
  'изображения догрузились',
)

console.log('\n=== 1. Админская секция «Дополнительные фото и видео» ===')
console.log(
  await ev(`(() => {
    const h = [...document.querySelectorAll('h2')].find((x) => /Дополнительные фото и видео/i.test(x.textContent));
    if (!h) return 'СЕКЦИИ НЕТ';
    const section = h.closest('section');
    return JSON.stringify({
      heading: h.textContent.trim(),
      text: section.innerText.replace(/\\s+/g, ' ').slice(0, 400),
      thumbnails: section.querySelectorAll('img').length,
      brokenImages: [...section.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
    }, null, 1);
  })()`),
)

await shot('admin-event-gallery.png')

/* ── 2. Витрина: публичный блок ─────────────────────────────────────────── */
await ev(`location.hash = '#/event/${SLUG}'`)
await sleep(1500)
await ev(`location.reload()`)

await waitFor(
  `[...document.querySelectorAll('h2')].some((x) => /Фотографии и видео/i.test(x.textContent))`,
  'публичный блок галереи',
)

await waitFor(
  `[...document.querySelectorAll('img')].every((i) => i.complete)`,
  'изображения витрины догрузились',
)

console.log('\n=== 2. Публичный блок «Фотографии и видео» ===')
console.log(
  await ev(`(() => {
    const h = [...document.querySelectorAll('h2')].find((x) => /Фотографии и видео/i.test(x.textContent));
    if (!h) return 'БЛОКА НЕТ';
    const section = h.closest('section');
    return JSON.stringify({
      heading: h.textContent.trim(),
      tiles: section.querySelectorAll('button').length,
      sources: [...section.querySelectorAll('img')].map((i) => i.getAttribute('src')),
      brokenImages: [...section.querySelectorAll('img')].filter((i) => i.complete && i.naturalWidth === 0).length,
    }, null, 1);
  })()`),
)

await shot('storefront-event-gallery.png')

/* ── 3. Просмотр во весь экран ──────────────────────────────────────────── */
await ev(`(() => {
  const h = [...document.querySelectorAll('h2')].find((x) => /Фотографии и видео/i.test(x.textContent));
  const btn = h && h.closest('section') && h.closest('section').querySelector('button');
  if (btn) btn.click();
  return !!btn;
})()`)
await sleep(1200)

console.log('\n=== 3. Просмотр во весь экран ===')
console.log(
  await ev(`(() => {
    const dlg = document.querySelector('[role="dialog"]');
    if (!dlg) return 'ОКНА НЕТ';
    return JSON.stringify({
      ariaModal: dlg.getAttribute('aria-modal'),
      hasImage: !!dlg.querySelector('img'),
      imageLoaded: (() => { const i = dlg.querySelector('img'); return i ? i.complete && i.naturalWidth > 0 : null; })(),
    }, null, 1);
  })()`),
)

await shot('storefront-event-gallery-lightbox.png')

ws.close()
chrome.kill()
