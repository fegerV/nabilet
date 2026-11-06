/**
 * Скриншоты НОВОЙ раскладки редактора залов (CDP, headless Chrome).
 *
 * Проверяем то, ради чего раскладка переделывалась: холст занимает почти весь
 * экран, панели сворачиваются и раскрываются, состояние переживает F5.
 *
 * Требует: сервер на :8099 (БД nabilet_hall_lab) и .shots/hall-lab-last.json.
 * Запуск: node .shots/hall-layout.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9355
const BASE = 'http://127.0.0.1:8099'
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })

const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson?.data?.token ?? loginJson.token
if (!TOKEN) throw new Error('login failed: ' + JSON.stringify(loginJson).slice(0, 300))
console.log('token ok')

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-hall-layout',
  '--window-size=1440,900', '--force-device-scale-factor=1', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)
      const page = (await res.json()).find((t) => t.type === 'page')
      if (page) return page
    } catch { /* старт */ }
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
  if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg.result); pending.delete(msg.id); return }
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
async function goto(hash, readyExpr, waitMs = 20000) {
  const t = hash.startsWith('#') ? hash : '#' + hash
  await evaluate(`location.hash = ${JSON.stringify(t)}`)
  await sleep(500)
  await send('Page.reload', { ignoreCache: false })
  return waitFor(readyExpr, waitMs)
}
async function waitFor(expression, timeoutMs) {
  const started = Date.now()
  while (Date.now() - started < timeoutMs) {
    if (await evaluate(expression)) return true
    await sleep(400)
  }
  return false
}
async function shoot(name) {
  const res = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
  writeFileSync(`${OUT}/${name}.png`, Buffer.from(res.data, 'base64'))
  console.log(`saved ${OUT}/${name}.png`)
}

/** Замер раскладки: высота холста, ширина панелей, переполнение. */
const LAYOUT_PROBE = `(() => {
  const canvas = document.querySelector('canvas');
  const host = canvas?.parentElement;
  const toolbars = [...document.querySelectorAll('main header, main section > div')];
  const main = document.querySelector('main');
  const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; };
  return JSON.stringify({
    viewport: { w: window.innerWidth, h: window.innerHeight },
    canvas: r(canvas),
    canvasHost: r(host),
    main: r(main),
    panels: [...document.querySelectorAll('main aside')].map((a) => r(a)),
    overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
    pageScrollY: document.documentElement.scrollHeight > window.innerHeight + 1,
  });
})()`

await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await goto('#/', `document.body.innerText.length > 10`, 15000)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

// 1. Раскладка по умолчанию на широком экране
const navOk = await goto(
  `/admin/halls/${lab.banquetHall}/editor`,
  `document.querySelector('canvas') !== null && document.body.innerText.includes('Схема зала')`,
  30000,
)
console.log('editor nav ok:', navOk)
await sleep(2500)
console.log('LAYOUT default:', await evaluate(LAYOUT_PROBE))
await shoot('layout-01-default')

// 2. Свернуть инструменты — холст должен вырасти
await evaluate(`(() => {
  const b = [...document.querySelectorAll('main aside button')].find((n) => n.title.includes('Скрыть инструменты'));
  if (b) b.click(); return !!b;
})()`)
await sleep(900)
console.log('LAYOUT tools-hidden:', await evaluate(LAYOUT_PROBE))
await shoot('layout-02-tools-hidden')

// 3. Свернуть свойства — холст должен вырасти ещё
await evaluate(`(() => {
  const b = [...document.querySelectorAll('main aside button')].find((n) => n.title.includes('Скрыть свойства'));
  if (b) b.click(); return !!b;
})()`)
await sleep(900)
console.log('LAYOUT both-hidden:', await evaluate(LAYOUT_PROBE))
await shoot('layout-03-both-hidden')

// 4. Вернуть инструменты компактной колонкой
await evaluate(`(() => {
  const b = [...document.querySelectorAll('main button')].find((n) => n.innerText.trim() === 'Инструменты');
  if (b) b.click(); return !!b;
})()`)
await sleep(900)
await evaluate(`(() => {
  const b = [...document.querySelectorAll('main aside button')].find((n) => n.title.includes('Компактный вид'));
  if (b) b.click(); return !!b;
})()`)
await sleep(700)
console.log('LAYOUT compact-tools:', await evaluate(LAYOUT_PROBE))
await shoot('layout-04-compact-rail')

// 5. Состояние переживает F5? (панели должны остаться как были: rail + скрытые свойства)
await send('Page.reload', { ignoreCache: false })
await waitFor(`document.querySelector('canvas') !== null`, 25000)
await sleep(2000)
console.log('LAYOUT after-reload:', await evaluate(LAYOUT_PROBE))
await shoot('layout-05-after-reload')

// 6. Проверка горячих клавиш: выбор инструмента буквой
const toolBefore = await evaluate(`document.body.innerText.includes('Выделение')`)
await send('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 's', code: 'KeyS', windowsVirtualKeyCode: 83 })
await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 's', code: 'KeyS', windowsVirtualKeyCode: 83 })
await sleep(600)
const toolAfter = await evaluate(
  `(() => { const el = document.querySelector('main .bg-surface-3'); return el ? el.innerText.trim() : ''; })()`,
)
console.log('tool after key "s":', JSON.stringify(toolAfter), '(до:', toolBefore, ')')

// 7. Snapping toggle + select all
console.log('snap toggle:', await evaluate(`(() => {
  const b = [...document.querySelectorAll('main button')].find((n) => n.innerText.trim() === 'Сетка');
  if (!b) return 'not found'; b.click();
  return localStorage.getItem('nabilet:hallEditor.snap');
})()`))

console.log('console errors:', consoleErrors.length ? JSON.stringify(consoleErrors.slice(0, 6), null, 2) : 'нет')
ws.close()
chrome.kill()
process.exit(0)
