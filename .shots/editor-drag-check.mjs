/**
 * Живая проверка перетаскивания места в конструкторе (CDP mouse + autosave diff).
 *
 * 1. Открываем черновик зала, читаем схему черновика через API (до).
 * 2. Ищем пиксель места на холсте по цвету, тянем его мышью (Input.dispatchMouseEvent).
 * 3. Ждём автосохранение (500 ms debounce), читаем схему (после), сравниваем.
 *
 * Ожидание: координата КАКОГО-ТО места изменилась, а x/y сектора — нет
 * (раньше тяга по несинему месту двигала весь сектор).
 *
 * Запуск: node .shots/editor-drag-check.mjs <hallPublicId> [baseUrl]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

const PID = process.argv[2]
const BASE = process.argv[3] ?? 'http://127.0.0.1:8090'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9363
const PROFILE = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-drag-check'

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson?.data?.token
if (!TOKEN) throw new Error('login failed')

async function draftSchema() {
  const res = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, {
    headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' },
  })
  const j = await res.json()
  const list = j?.data ?? []
  const draft = Array.isArray(list) ? list.find((v) => v.status === 'draft') : null
  if (!draft?.schema) return null
  return { data: { payload: draft.schema } }
}

const before = await draftSchema()
if (!before) { console.log('[!] черновика нет — перетащить нечего'); process.exit(1) }
const seatsBefore = new Map()
for (const s of before?.data?.payload?.sectors ?? []) {
  for (const seat of s.seats ?? []) seatsBefore.set(seat.id, { x: seat.x, y: seat.y })
}
const sectorXYBefore = new Map()
for (const s of before?.data?.payload?.sectors ?? []) sectorXYBefore.set(s.id, { x: s.x, y: s.y })
console.log(`[i] мест в черновике: ${seatsBefore.size}`)

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
const savedPayloads = []
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
  if (m.method === 'Network.requestWillBeSent') {
    const url = m.params?.request?.url ?? ''
    const body = m.params?.request?.postData
    if (url.includes('schema-versions/draft') && m.params.request.method === 'POST' && body) {
      savedPayloads.push(body)
    }
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
await send('Network.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  if (r?.exceptionDetails) console.log('[js-error]', JSON.stringify(r.exceptionDetails).slice(0, 300))
  return r?.result?.value
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(JSON.stringify(loginJson?.data?.user ?? {}))})`)
await ev(`location.hash = '#/admin/halls/${PID}/editor'`)
await sleep(600)
await send('Page.reload')
for (let i = 0; i < 30; i += 1) {
  await sleep(1000)
  const st = await ev(`document.querySelectorAll('.konvajs-content canvas').length`)
  if (st > 0) { console.log(`[✓] холст готов (${i + 1}с)`); break }
}
await sleep(2000)

// Пиксель места: ищем заметно цветной кружок (не фон, не белый) в средней зоне холста.
const canvasBox = await ev(`(() => {
  const c = document.querySelector('.konvajs-content canvas')
  const r = c.getBoundingClientRect()
  return { left: r.left, top: r.top, width: r.width, height: r.height }
})()`)
// Пиксель места: Konva рисует каждый слой в СВОЙ canvas — первый (фон) обычно
// прозрачный. Берём canvas с наибольшим числом непрозрачных пикселей.
const seatPx = await ev(`(() => {
  const canvases = [...document.querySelectorAll('.konvajs-content canvas')]
  let best = null, bestCount = 0
  for (const c of canvases) {
    const ctx = c.getContext('2d')
    const { width: W, height: H } = c
    const img = ctx.getImageData(0, 0, W, H).data
    let opaque = 0
    for (let i = 3; i < img.length; i += 64) if (img[i] > 200) opaque += 1
    if (opaque > bestCount) { best = { c, ctx, W, H, img }; bestCount = opaque }
  }
  if (!best) return { null: true, reason: 'все слои пустые' }
  const { c, ctx, W, H } = best
  const img = ctx.getImageData(0, 0, W, H).data
  const counts = new Map()
  for (let i = 0; i < img.length; i += 64) {
    if (img[i + 3] < 200) continue
    const k = (img[i] >> 4) + ',' + (img[i + 1] >> 4) + ',' + (img[i + 2] >> 4)
    counts.set(k, (counts.get(k) ?? 0) + 1)
  }
  const bgKey = [...counts.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? ''
  const isBg = (r, g, b) => ((r >> 4) + ',' + (g >> 4) + ',' + (b >> 4)) === bgKey
  for (let y = Math.floor(H * 0.3); y < H * 0.85; y += 2) {
    for (let x = Math.floor(W * 0.2); x < W * 0.8; x += 2) {
      const i = (y * W + x) * 4
      const r = img[i], g = img[i + 1], b = img[i + 2], a = img[i + 3]
      if (a > 200 && !isBg(r, g, b)) {
        let ok = 0
        for (const [dx, dy] of [[-3,0],[3,0],[0,-3],[0,3]]) {
          const j = ((y + dy) * W + (x + dx)) * 4
          if (img[j + 3] > 200 && !isBg(img[j], img[j+1], img[j+2])) ok += 1
        }
        if (ok >= 3) return { x, y, bgKey, W, H }
      }
    }
  }
  return { null: true, bgKey, W, H, reason: 'слой без мест' }
})()`)
if (!seatPx || seatPx.null) { console.log(`[!] место не найдено на холсте (bg=${seatPx?.bgKey}, canvas=${seatPx?.W}x${seatPx?.H})`); process.exit(1) }
console.log(`[i] тяну место в canvas-координатах: ${JSON.stringify(seatPx)} (canvas at ${canvasBox.left},${canvasBox.top})`)

const px = canvasBox.left + seatPx.x
const py = canvasBox.top + seatPx.y

async function mouse(type, x, y, extra = {}) {
  await send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: type === 'mousePressed' || type === 'mouseReleased' ? 1 : 0, ...extra })
}

await mouse('mouseMoved', px, py)
await mouse('mousePressed', px, py)
await sleep(80)
// Тянем вправо-вниз на 60px с привязкой к движению мыши.
for (let i = 1; i <= 12; i += 1) {
  await mouse('mouseMoved', px + i * 5, py + i * 5)
  await sleep(25)
}
await mouse('mouseReleased', px + 60, py + 60)
await sleep(2000) // autosave debounce 500ms + сеть

const after = await draftSchema()
const seatsAfter = new Map()
for (const s of after?.data?.payload?.sectors ?? []) {
  for (const seat of s.seats ?? []) seatsAfter.set(seat.id, { x: seat.x, y: seat.y })
}
let movedSeats = 0
let movedSector = 0
for (const [id, p] of seatsBefore) {
  const q = seatsAfter.get(id)
  if (!q) continue
  if (q.x !== p.x || q.y !== p.y) movedSeats += 1
}
for (const [id, p] of sectorXYBefore) {
  const q = (after?.data?.payload?.sectors ?? []).find((s) => s.id === id)
  if (q && (q.x !== p.x || q.y !== p.y)) movedSector += 1
}
console.log(`[i] мест с изменившимися координатами: ${movedSeats}`)
console.log(`[i] секторов с изменившимся x/y: ${movedSector}`)
console.log(`[i] автосохранений поймано: ${savedPayloads.length}`)
console.log(`ошибок консоли: ${errors.length}${errors.length ? '\n  ' + errors.slice(0, 6).join('\n  ') : ''}`)

const verdict = movedSeats > 0 && movedSector === 0
console.log(verdict ? '\n[✓] МЕСТО ПЕРЕМЕЩАЕТСЯ, сектор остаётся на месте' : '\n[✗] перетаскивание не подтвердилось')
ws.close()
chrome.kill()
process.exit(verdict ? 0 : 2)
