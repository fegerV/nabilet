/**
 * Скриншот и текстовая выжимка страницы «Цены на ряды» конкретного сеанса.
 *
 * Нужен, чтобы отвечать на вопрос «как менять цены на места для мероприятия»
 * не по памяти, а по живому экрану: путь «Сеансы → сеанс → Цены», список рядов
 * с текущими ценами и подсказка «пустое поле — не менять».
 *
 * Запуск: node .shots/session-prices.mjs <token> <sessionId> [outName]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9417
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const SESSION_ID = process.argv[3] ?? '20'
const OUT = process.argv[4] ?? 'admin-session-prices.png'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-prices',
    '--window-size=1280,1100',
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

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
// Guard админки требует не только токен, но и сохранённого пользователя с
// ролью admin/manager (auth.ts: isAdminSession) — иначе редирект на логин.
await ev(`localStorage.setItem('nabilet_admin_user', JSON.stringify({
  id: 'seed-admin', email: 'admin@nabilet.local', first_name: 'Админ', roles: ['admin'],
}))`)
await ev(`location.hash = '#/admin/sessions/${SESSION_ID}/prices'`)
await sleep(800)
await ev(`location.reload()`)

await waitFor(`!!document.querySelector('main form, main h1')`, 'страница цен')
await sleep(2000)

console.log(
  await ev(`(() => {
    const h1 = document.querySelector('main h1');
    const sub = h1?.parentElement?.querySelector('p');
    const hint = [...document.querySelectorAll('main span')].map((s) => s.textContent.trim())
      .find((x) => /Пустое поле/i.test(x));
    const rows = [...document.querySelectorAll('main form > div')]
      .map((d) => d.innerText.replace(/\\s+/g, ' ').trim())
      .filter((x) => /Ряд|зона/i.test(x));
    return JSON.stringify({
      title: h1?.textContent.trim(),
      subtitle: sub?.textContent.trim(),
      hint,
      rowCount: rows.length,
      firstRows: rows.slice(0, 6),
      lastRows: rows.slice(-3),
    }, null, 1);
  })()`),
)

// `viewport` в argv — снимать только видимую область: страница цен легко
// растягивается на 54 ряда, и full-page скриншот в ответе нечитаем.
const VIEWPORT_ONLY = process.argv.includes('viewport')

const r = await send('Page.captureScreenshot', {
  format: 'png',
  captureBeyondViewport: !VIEWPORT_ONLY,
})
if (r?.data) {
  writeFileSync(`.shots/${OUT}`, Buffer.from(r.data, 'base64'))
  console.log(`скриншот: .shots/${OUT}`)
}

ws.close()
chrome.kill()
process.exit(0)
