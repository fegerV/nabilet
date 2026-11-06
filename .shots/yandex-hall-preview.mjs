/**
 * Скриншот импортированного зала «Дворец искусств Нефтяник» в конструкторе.
 *
 * Цель — увидеть схему глазами: сектора должны стоять на своих местах
 * (балкон сверху, ниже бельэтаж, затем партер и приставные места у сцены),
 * а не «слепиться» в одну кучу после импорта.
 *
 * Запуск: node .shots/yandex-hall-preview.mjs [hallPublicId] [baseUrl]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const HALL = process.argv[2]
const BASE = process.argv[3] ?? 'http://127.0.0.1:8000'
const EXPECTED = process.argv[4] ?? '1073'
const LABEL = process.argv[5] ?? 'hall'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9355
const PROFILE = `C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-${LABEL}`
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson?.data?.token ?? loginJson.token
if (!TOKEN) throw new Error('login failed')

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  `--user-data-dir=${PROFILE}`,
  '--window-size=1600,1100', '--force-device-scale-factor=1', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 60; i += 1) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page')
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
const errors = []
function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return }
  if (m.method === 'Runtime.consoleAPICalled' && m.params?.type === 'error') {
    errors.push(m.params.args?.map((a) => a.value ?? a.description).join(' ').slice(0, 200))
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  return r?.result?.value
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(3500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(JSON.stringify(loginJson?.data?.user ?? { id: '1', email: 'admin@nabilet.local', roles: ['admin'] }))})`)
await ev(`location.hash = '#/admin/halls/${HALL}/editor'`)
await sleep(600)
await send('Page.reload')

// Ждём, пока редактор нарисует места: на холсте появляется канвас Konva.
let ready = false
for (let i = 0; i < 40; i += 1) {
  await sleep(1000)
  const state = await ev(`(() => {
    const canvases = document.querySelectorAll('canvas').length
    const text = document.body.innerText
    return { canvases, seats: new RegExp(${JSON.stringify(EXPECTED)}).test(text), head: text.slice(0, 120) }
  })()`)
  if (state && state.canvases > 0 && state.seats) { ready = true; console.log(`[✓] редактор готов (${i + 1}с)`); break }
}
if (!ready) console.log(`[!] редактор не показал ${EXPECTED} мест — скриншот всё равно сделаем`)
await sleep(2500)

const shot = await send('Page.captureScreenshot', { format: 'png' })
const shotFile = `${OUT}/${LABEL}-editor.png`
writeFileSync(shotFile, Buffer.from(shot.data, 'base64'))
console.log(`[✓] скриншот: ${shotFile}`)
console.log(`ошибок консоли: ${errors.length}${errors.length ? '\n  ' + errors.slice(0, 5).join('\n  ') : ''}`)

ws.close()
chrome.kill()
process.exit(0)
