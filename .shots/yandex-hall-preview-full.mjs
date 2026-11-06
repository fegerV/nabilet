/**
 * Полноразмерный скриншот импортированного зала (captureBeyondViewport),
 * чтобы в кадр попали партер, приставные места и сцена снизу.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const HALL = process.argv[2]
const BASE = process.argv[3] ?? 'http://127.0.0.1:8000'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9356
const PROFILE = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-neftyanik-full'
const OUT = 'outputs'

mkdirSync(OUT, { recursive: true })

const login = await (await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})).json()
const TOKEN = login?.data?.token ?? login.token
if (!TOKEN) throw new Error('login failed')

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${PROFILE}`,
  '--window-size=1600,1400', '--force-device-scale-factor=1', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 60; i += 1) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page')
      if (page) return page
    } catch { /* стартует */ }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map(); let seq = 0
function send(method, params = {}) { const id = ++seq; ws.send(JSON.stringify({ id, method, params })); return new Promise((r) => pending.set(id, r)) }
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable'); await send('Page.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  return r?.result?.value
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(3500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/halls/${HALL}/editor'`)
await sleep(600)
await send('Page.reload')

let ready = false
for (let i = 0; i < 40; i += 1) {
  await sleep(1000)
  const s = await ev(`(() => ({ n: document.querySelectorAll('canvas').length, seats: /1073/.test(document.body.innerText) }))()`)
  if (s && s.n > 0 && s.seats) { ready = true; console.log(`[✓] готов за ${i + 1}с`); break }
}
if (!ready) console.log('[!] метка 1073 не появилась, продолжаем')
await sleep(2500)

// Скроллим редактор к нижней части холста, чтобы в кадр попали партер,
// приставные места и сцена.
await ev(`(() => {
  const stage = document.querySelector('.canvas-stage') || document.querySelector('.konvajs-content')?.parentElement || document.body
  if (stage && stage.scrollHeight > stage.clientHeight) stage.scrollTop = stage.scrollHeight
  window.scrollTo(0, document.body.scrollHeight)
  return true
})()`)
await sleep(2000)

const shot = await send('Page.captureScreenshot', { format: 'png' })
writeFileSync(`${OUT}/hall-neftyanik-editor-bottom.png`, Buffer.from(shot.data, 'base64'))
console.log(`[✓] ${OUT}/hall-neftyanik-editor-bottom.png`)

ws.close()
chrome.kill()
process.exit(0)