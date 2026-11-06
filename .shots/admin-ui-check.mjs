/**
 * Визуальная проверка переработки админки (NCard / NPageHeader + логин).
 * Бэкенд не нужен: логин рисуется статикой, а разделы показывают каркас даже
 * при ошибке загрузки данных — этого достаточно, чтобы увидеть новые карточки.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP = 9431
const BASE = 'http://127.0.0.1:4173'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${CDP}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-ui-check',
    '--window-size=1440,1400',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function target() {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP}/json/list`)
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
    consoleErrors.push('EXC: ' + (msg.params?.exceptionDetails?.exception?.description ?? msg.params?.exceptionDetails?.text))
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
    consoleErrors.push('CONSOLE.error: ' + (msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ').slice(0, 240))
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

// 1) Логин (без бэкенда)
await send('Page.navigate', { url: `${BASE}/#/admin/login` })
await sleep(2500)
await waitFor(`!!document.querySelector('form[aria-label="Вход в админку"]')`, 'форма логина')
await shot('admin-login-redesign.png')

// 2) Дашборд — ставим токен, чтобы guard не кинул на логин
await ev(`localStorage.setItem('nabilet_admin_token','demo-token')`)
await ev(`location.hash = '#/admin'`)
await sleep(1200)
await waitFor(`!!document.querySelector('h1')`, 'заголовок дашборда')
await sleep(1500)
await shot('admin-dashboard-redesign.png')

// 3) Интеграции
await ev(`location.hash = '#/admin/integrations'`)
await sleep(1500)
await shot('admin-integrations-redesign.png')

// 4) Почта
await ev(`location.hash = '#/admin/mail'`)
await sleep(1500)
await shot('admin-mail-redesign.png')

console.log('\n=== Ошибки консоли ===')
console.log(consoleErrors.length ? consoleErrors.join('\n') : 'нет')

ws.close()
chrome.kill()
