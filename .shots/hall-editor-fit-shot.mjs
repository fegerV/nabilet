/**
 * Скриншот конструктора зала с общим планом (zoom out).
 *
 * Редактор открывается на 100% и показывает только часть зала — для сверки
 * с Яндексом нужен весь план целиком, поэтому после загрузки жмём «−» в
 * панели масштаба несколько раз.
 *
 * Запуск: node .shots/hall-editor-fit-shot.mjs <hallPublicId> <outName> [zoomOutClicks]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const HALL = process.argv[2]
const LABEL = process.argv[3] ?? 'hall-fit'
const CLICKS = Number(process.argv[4] ?? 5)
const BASE = 'http://127.0.0.1:8000'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9361
const PROFILE = `C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-${LABEL}`
mkdirSync('outputs', { recursive: true })

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson?.data?.token
const USER = JSON.stringify(loginJson?.data?.user ?? { id: '1', email: 'admin@nabilet.local', roles: ['admin'] })
if (!TOKEN) throw new Error('login failed')

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${PROFILE}`,
  '--window-size=1600,1100', '--force-device-scale-factor=1', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 80; i += 1) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page')
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
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id) }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
const ev = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }))?.result?.value

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(3000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(USER)})`)
await ev(`location.hash = '#/admin/halls/${HALL}/editor'`)
await sleep(500)
await send('Page.reload')

for (let i = 0; i < 40; i += 1) {
  await sleep(1000)
  const ready = await ev(`document.querySelectorAll('canvas').length > 0 && /мест/.test(document.body.innerText)`)
  if (ready) { console.log(`[✓] редактор готов (${i + 1}с)`); break }
}
await sleep(2500)

// Кнопка «−» в панели масштаба (центр холста, верхняя строка инструментов).
let rect = null
for (let i = 0; i < 20; i += 1) {
  rect = await ev(`(() => {
    const el = [...document.querySelectorAll('button')].find((b) => (b.textContent || '').trim() === '−')
      || [...document.querySelectorAll('button')].find((b) => /уменьш/i.test(b.getAttribute('aria-label') || ''))
    if (!el) return null
    const r = el.getBoundingClientRect()
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
  })()`)
  if (rect) break
  await sleep(500)
}
if (!rect) {
  console.log('кнопка масштаба не найдена; текст страницы:', (await ev('document.body.innerText')).slice(0, 300))
  throw new Error('кнопка масштаба не найдена')
}
for (let i = 0; i < CLICKS; i += 1) {
  for (const type of ['mousePressed', 'mouseReleased']) {
    await send('Input.dispatchMouseEvent', { type, x: rect.x, y: rect.y, button: 'left', clickCount: 1 })
  }
  await sleep(220)
}
await sleep(1200)
const zoom = await ev(`(() => { const m = document.body.innerText.match(/(\\d+)%/); return m ? m[1] : '?' })()`)
const seats = await ev(`(() => { const m = document.body.innerText.match(/(\\d+)\\s*мест/); return m ? m[1] : '?' })()`)
const shot = await send('Page.captureScreenshot', { format: 'png' })
const file = `outputs/${LABEL}.png`
writeFileSync(file, Buffer.from(shot.data, 'base64'))
console.log(`[✓] ${file} — масштаб ${zoom}%, мест в шапке: ${seats}`)
ws.close()
chrome.kill()
process.exit(0)
