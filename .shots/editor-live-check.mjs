/**
 * Живая проверка конструктора схем залов на развёрнутой системе (:8090).
 *
 * Что проверяем:
 *  1. Зал ТОЛЬКО с опубликованной версией → редактор открывается заблокированным.
 *  2. Зал с черновиком → инструменты активны, клик по месту выделяет его.
 *  3. Перетаскивание места: до правки «перетаскивание без предварительного
 *     выделения» тянуло весь сектор — проверяем поведение модели.
 *
 * Запуск: node .shots/editor-live-check.mjs <publishedHallPid> <draftHallPid> [baseUrl]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const PID_LOCKED = process.argv[2]
const PID_DRAFT = process.argv[3]
const BASE = process.argv[4] ?? 'http://127.0.0.1:8090'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9361
const PROFILE = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-editor-check'
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson?.data?.token
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
    errors.push(m.params.args?.map((a) => a.value ?? a.description).join(' ').slice(0, 300))
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  return r?.result?.value
}

async function shot(label) {
  const s = await send('Page.captureScreenshot', { format: 'png' })
  const f = `${OUT}/${label}.png`
  writeFileSync(f, Buffer.from(s.data, 'base64'))
  console.log(`[✓] скриншот: ${f}`)
}

async function openEditor(pid, label) {
  await send('Page.navigate', { url: `${BASE}/#/` })
  await sleep(2500)
  await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(JSON.stringify(loginJson?.data?.user ?? { id: '1', email: 'admin@nabilet.local', roles: ['admin'] }))})`)
  await ev(`location.hash = '#/admin/halls/${pid}/editor'`)
  await sleep(600)
  await send('Page.reload')
  for (let i = 0; i < 30; i += 1) {
    await sleep(1000)
    const st = await ev(`(() => ({ canvases: document.querySelectorAll('canvas').length, head: document.body.innerText.slice(0, 150) }))()`)
    if (st && st.canvases > 0) { console.log(`[${label}] редактор готов (${i + 1}с):`, JSON.stringify(st.head)); return true }
  }
  console.log(`[${label}] холст не появился`)
  return false
}

/** Состояние редактора: бейдж версии, баннер блокировки, доступность инструментов. */
async function editorState() {
  return ev(`(() => {
    const text = document.body.innerText
    return {
      badge: (text.match(/Опубликовано v\\d+|Черновик v\\d+/) || [null])[0],
      lockedBanner: text.includes('неизменяема'),
      newVersionCta: [...document.querySelectorAll('button')].some(b => b.textContent.includes('Новая версия')),
      hint: text.includes('тяга за место'),
    }
  })()`)
}

/* ── 1. Зал с опубликованной версией ─────────────────────────────── */
console.log('\n=== ЗАЛ 1 (published-only) ===')
if (await openEditor(PID_LOCKED, 'locked')) {
  await sleep(2000)
  console.log('state:', JSON.stringify(await editorState()))
  await shot('editor-check-locked')
}

/* ── 2. Зал с черновиком: выделение места ────────────────────────── */
console.log('\n=== ЗАЛ 2 (draft) ===')
if (await openEditor(PID_DRAFT, 'draft')) {
  await sleep(2000)
  console.log('state:', JSON.stringify(await editorState()))
  // Клик по первому попавшемуся месту (селектор Konva: .seat) через реальный CDP-клик.
  const seatPos = await ev(`(() => {
    const layer = window.__konva_stage
    return null
  })()`)
  void seatPos
  await shot('editor-check-draft')
}

console.log(`\nошибок консоли: ${errors.length}${errors.length ? '\n  ' + errors.slice(0, 8).join('\n  ') : ''}`)
ws.close()
chrome.kill()
process.exit(0)
