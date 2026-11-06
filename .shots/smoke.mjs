// Смоук-проверка собранной витрины и админки в headless Chrome через CDP.
//  1) открываем SPA, кладём админский токен в localStorage,
//  2) проходим по ключевым маршрутам,
//  3) собираем ошибки консоли и текст страницы.
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9333
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-smoke',
    '--window-size=1440,900',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function targets() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const list = await res.json()
      const page = list.find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* chrome ещё поднимается */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await targets()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0
const consoleErrors = []

function send(method, params = {}, sessionId) {
  const id = ++seq
  const payload = { id, method, params }
  if (sessionId) payload.sessionId = sessionId
  ws.send(JSON.stringify(payload))
  return new Promise((resolve) => pending.set(id, resolve))
}

ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    const details = msg.params?.exceptionDetails
    consoleErrors.push(`EXCEPTION: ${details?.exception?.description ?? details?.text ?? 'unknown'}`)
  }
  if (msg.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(msg.params?.type)) {
    const text = (msg.params.args ?? [])
      .map((a) => a.value ?? a.description ?? a.type)
      .join(' ')
    consoleErrors.push(`CONSOLE.${msg.params.type}: ${text}`)
  }
})

await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
await send('Log.enable')

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return res?.result?.value ?? res?.exceptionDetails?.text ?? ''
}

async function visit(hash, waitMs = 5000) {
  await send('Page.navigate', { url: `${BASE}/${hash}` })
  await sleep(waitMs)
  // Берём только <main>: сайдбар админки один и тот же на всех страницах и
  // съедает всю выборку текста.
  return evaluate(
    `(document.querySelector('main') ?? document.body).innerText.replace(/\\s+/g, ' ').slice(0, 700)`,
  )
}

// Кладём токен: админка требует Bearer, а логин-форму в смоуке не проходим.
await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

const routes = [
  ['#/', 'витрина'],
  ['#/admin', 'обзор'],
  ['#/admin/storefront', 'конструктор витрины'],
  ['#/admin/tickets', 'билеты'],
  ['#/admin/payments', 'платежи'],
  ['#/admin/users', 'пользователи'],
  ['#/admin/analytics', 'аналитика'],
  ['#/admin/integrations', 'интеграции'],
]

const report = []
for (const [hash, name] of routes) {
  const text = await visit(hash)
  // На витрине дополнительно проверяем, что кисти доехали до CSS-переменных.
  const theme =
    hash === '#/'
      ? await evaluate(
          `(() => { const s = getComputedStyle(document.documentElement);
             return 'brand-500=' + s.getPropertyValue('--brand-500').trim()
               + ' | accent-500=' + s.getPropertyValue('--accent-500').trim()
               + ' | radius=' + s.getPropertyValue('--radius').trim()
               + ' | surface=' + (document.documentElement.getAttribute('data-surface') ?? '—')
               + ' | title=' + document.title; })()`,
        )
      : ''
  report.push({ name, hash, length: text.length, sample: text.slice(0, 260), theme })
}

console.log('=== ERRORS ===')
console.log(consoleErrors.length ? [...new Set(consoleErrors)].join('\n') : '(нет)')
console.log('=== PAGES ===')
for (const row of report) {
  console.log(`\n[${row.name}] ${row.hash} — текста ${row.length} симв.`)
  if (row.theme) console.log(`ТЕМА: ${row.theme}`)
  console.log(row.sample)
}

ws.close()
chrome.kill()
process.exit(0)
