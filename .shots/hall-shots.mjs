/**
 * Скриншоты конструктора залов и витрины мест (CDP, headless Chrome).
 *
 * Проверяем «в деле» ровно то, что видит человек:
 *   • конструктор схемы зала с банкетными столами (кольцо мест вокруг стола);
 *   • карту мест витрины для концертного зала (партер + амфитеатр-дуга + балкон
 *     + танцпол) — все сектора должны быть разнесены, а не «слеплены» в один;
 *   • карту мест витрины для банкетного зала (6 столов).
 *
 * Требует: сервер на :8099 (БД nabilet_hall_lab) и .shots/hall-lab-last.json.
 *
 * Запуск: node .shots/hall-shots.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9344
const BASE = 'http://127.0.0.1:8099'
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })

const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

// Токен админа: конструктор зала открыт только под auth:api + admin.
const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson.token ?? loginJson.access_token ?? loginJson?.data?.token
if (!TOKEN) throw new Error('login failed: ' + JSON.stringify(loginJson).slice(0, 300))
console.log('token ok')

const chrome = spawn(CHROME, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-hall-lab',
  '--window-size=1440,900',
  '--force-device-scale-factor=1',
  'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)
      const page = (await res.json()).find((t) => t.type === 'page')
      if (page) return page
    } catch { /* chrome стартует */ }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0

function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}

const consoleErrors = []
ws.addEventListener('message', (e) => {
  const msg = JSON.parse(e.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
    consoleErrors.push(msg.params.args?.map((a) => a.value ?? a.description).join(' '))
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    consoleErrors.push(msg.params?.exceptionDetails?.exception?.description ?? 'exception')
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return res?.result?.value ?? ''
}

async function go(hash, waitMs = 5000) {
  await send('Page.navigate', { url: `${BASE}/${hash}` })
  await sleep(waitMs)
}

/**
 * Переход по hash-маршруту.
 *
 * `Page.navigate` на URL, отличающийся только фрагментом, в headless-Chrome не
 * меняет hash (проверено: `location.href` оставался прежним), а in-page переход
 * на `/admin/...` откатывался обратно на `#/`. Поэтому: ставим hash, делаем
 * reload — приложение стартует СРАЗУ на нужном маршруте.
 */
async function goto(hash, readyExpr, waitMs = 15000) {
  const target = hash.startsWith('#') ? hash : '#' + hash
  await evaluate(`location.hash = ${JSON.stringify(target)}`)
  await sleep(500)
  await send('Page.reload', { ignoreCache: false })
  const ok = await waitFor(readyExpr, waitMs)
  console.log(`nav ${target} → готово: ${ok}`)
  return ok
}

async function waitFor(expression, timeoutMs) {
  const started = Date.now()
  while (Date.now() - started < timeoutMs) {
    const value = await evaluate(expression)
    if (value) return true
    await sleep(400)
  }
  return false
}

async function shoot(name) {
  const res = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  writeFileSync(`${OUT}/${name}.png`, Buffer.from(res.data, 'base64'))
  console.log(`saved ${OUT}/${name}.png`)
}

async function clickText(text) {
  const ok = await evaluate(
    `(() => { const el = [...document.querySelectorAll('button, a')]
        .find((n) => n.innerText.trim() === ${JSON.stringify(text)});
      if (!el) return 'not found'; el.click(); return 'clicked'; })()`,
  )
  await sleep(1500)
  return ok
}

// Прогреваем приложение и кладём админ-токен.
await go('#/', 4000)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
console.log('token in storage:', await evaluate(`localStorage.getItem('nabilet_admin_token')?.length ?? 0`))

// 1. Конструктор зала: банкетные столы.
// Ждём именно прогруженную схему: «0 мест» — это ещё пустой холст до ответа API.
await goto(
  `/admin/halls/${lab.banquetHall}/editor`,
  `document.body.innerText.includes('Редактор схем залов')
     && /\\b52\\b/.test(document.querySelector('main')?.innerText ?? '')`,
  25000,
)
await sleep(1200)
const editorProbe = await evaluate(
  `(() => { const main = document.querySelector('main') ?? document.body;
     return JSON.stringify({ chars: main.innerText.length, canvas: document.querySelectorAll('canvas').length,
       head: main.innerText.slice(0, 200) }); })()`,
)
console.log('editor probe:', editorProbe)
await shoot('hall-editor-banquet')

// 2. Витрина: карта мест концертного зала (партер + амфитеатр + балкон + танцпол).
await goto(
  `/event/${lab.eventSlug}/seats?session=${lab.concertSession}`,
  `document.querySelectorAll('svg circle, svg rect').length > 100`,
  20000,
)
const concertProbe = await evaluate(
  `(() => { const svg = document.querySelector('svg');
     const circles = document.querySelectorAll('svg circle, svg rect');
     return JSON.stringify({ hasSvg: !!svg, marks: circles.length,
       text: (document.querySelector('main') ?? document.body).innerText.slice(0, 200) }); })()`,
)
console.log('concert map probe:', concertProbe)
await shoot('storefront-seats-concert')

// 3. Витрина: карта мест банкетного зала (6 столов).
await goto(
  `/event/${lab.eventSlug}/seats?session=${lab.banquetSession}`,
  `document.querySelectorAll('svg circle, svg rect').length > 20`,
  20000,
)
const banquetProbe = await evaluate(
  `(() => { const marks = document.querySelectorAll('svg circle, svg rect');
     return JSON.stringify({ marks: marks.length,
       text: (document.querySelector('main') ?? document.body).innerText.slice(0, 220) }); })()`,
)
console.log('banquet map probe:', banquetProbe)
await shoot('storefront-seats-banquet')

// Горизонтальное переполнение на карте — реальный дефект, а не артефакт.
const overflow = await evaluate(
  `(() => { const de = document.documentElement;
     return JSON.stringify({ viewport: window.innerWidth, scrollWidth: de.scrollWidth,
       overflow: de.scrollWidth > window.innerWidth + 1 }); })()`,
)
console.log('OVERFLOW seats:', overflow)

console.log('console errors:', consoleErrors.length ? JSON.stringify(consoleErrors.slice(0, 8), null, 2) : 'нет')

ws.close()
chrome.kill()
process.exit(0)
