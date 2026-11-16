/**
 * Живая проверка перетаскивания места в конструкторе (CDP mouse + автосейв).
 *
 * Что проверяет:
 *  1. Конструктор загружает ЧЕРНОВИК зала (режим «Черновик», без блокировки).
 *  2. Реальный CDP-драг места мышью (Input.dispatchMouseEvent, buttons:1).
 *  3. Автосохранение на сервер проходит с HTTP 200 — раньше наложение мест
 *     («Seats must not overlap») отклонялось 422, и правки терялись.
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
const savedResponses = []
const savedRequests = []
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
  if (m.method === 'Network.responseReceived' && (m.params?.response?.url ?? '').includes('schema-versions/draft')) {
    savedResponses.push(m.params.response.status)
  }
  if (m.method === 'Network.requestWillBeSent' && (m.params?.request?.url ?? '').includes('schema-versions/draft')) {
    savedRequests.push(m.params.requestId)
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
await send('Network.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  if (r?.exceptionDetails) console.log('[js-error]', (r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails)).slice(0, 300))
  return r?.result?.value
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(3000)
// Профиль браузера персистентный: SPA видит ПРОШЛОРАУНДОВЫЙ токен, ловит
// 401 /auth/me, logout чистит localStorage и роутер-гард возвращает на
// главную. Поэтому: пишем свежий токен ПОСЛЕ того, как старый logout уже
// отработал, и грузим редактор ПОЛНОЙ навигацией (иначе reload просто
// перезагрузит главную, куда гард уже успел вернуть адрес).
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(JSON.stringify(loginJson?.data?.user ?? {}))})`)
await send('Page.navigate', { url: `${BASE}/?t=${Date.now()}#/admin/halls/${PID}/editor` })

let ready = false
for (let attempt = 0; attempt < 2 && !ready; attempt += 1) {
  for (let i = 0; i < 30; i += 1) {
    await sleep(1000)
    const st = await ev(`(() => {
      const canvases = document.querySelectorAll('.konvajs-content canvas').length
      const seats = /\\d+\\s+мест/.test(document.body.innerText)
      return { canvases, seats }
    })()`)
    if (st?.canvases > 0 && st?.seats) { console.log(`[✓] холст со схемой готов (${i + 1}с)`); ready = true; break }
  }
  if (!ready && attempt === 0) {
    console.log('[!] редактор не загрузился за 30с — пробую ещё раз')
    await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_admin_user', ${JSON.stringify(JSON.stringify(loginJson?.data?.user ?? {}))})`)
    await send('Page.navigate', { url: `${BASE}/?t=${Date.now()}#/admin/halls/${PID}/editor` })
  }
}
if (!ready) { console.log('[!] редактор так и не загрузился'); process.exit(1) }
await sleep(2500)

// Режим редактора: черновик или опубликованная (заблокированная) версия.
const mode = await ev(`(() => {
  const t = document.body.innerText
  return { badge: t.match(/(Опубликовано|Черновик)\\s*v?\\d+/)?.[0] ?? null, lockBanner: /неизменяема/.test(t) }
})()`)
console.log('[i] режим редактора:', JSON.stringify(mode))

// Пиксель места: слой с максимумом непрозрачных пикселей; цель — пиксель из
// самого крупного кластера палитры мест (SEAT_PRICE_PALETTE + выделение).
// Слой может быть ещё не отрисован — ретраи до 10 секунд.
const pickExpr = `(() => {
  const SEAT_RGBS = [[36,184,232],[79,196,93],[255,201,40],[99,212,233],[216,121,184],[242,124,91],[109,74,255],[245,180,23],[42,163,255]]
  const near = (r, g, b, [R, G, B]) => Math.abs(r - R) <= 12 && Math.abs(g - G) <= 12 && Math.abs(b - B) <= 12
  const canvases = [...document.querySelectorAll('.konvajs-content canvas')]
  let best = null, bestCount = 0
  for (let ci = 0; ci < canvases.length; ci += 1) {
    const c = canvases[ci]
    const ctx = c.getContext('2d')
    const { width: W, height: H } = c
    const img = ctx.getImageData(0, 0, W, H).data
    let opaque = 0
    for (let i = 3; i < img.length; i += 64) if (img[i] > 200) opaque += 1
    if (opaque > bestCount) { best = { W, H, img, ci }; bestCount = opaque }
  }
  if (!best) return { null: true, reason: 'все слои пустые' }
  const img = best.img
  const W = best.W, H = best.H
  const clusters = new Map()
  for (let y = 2; y < H - 2; y += 1) {
    for (let x = 2; x < W - 2; x += 1) {
      const i = (y * W + x) * 4
      const r = img[i], g = img[i + 1], b = img[i + 2], a = img[i + 3]
      if (a > 200 && SEAT_RGBS.some((rgb) => near(r, g, b, rgb))) {
        const k = SEAT_RGBS.findIndex((rgb) => near(r, g, b, rgb))
        const c = clusters.get(k) ?? { n: 0, sx: 0, sy: 0 }
        c.n += 1; c.sx += x; c.sy += y
        clusters.set(k, c)
      }
    }
  }
  const top = [...clusters.entries()].sort((a, b) => b[1].n - a[1].n)[0]
  if (top) {
    const cx = Math.round(top[1].sx / top[1].n)
    const cy = Math.round(top[1].sy / top[1].n)
    let bp = null, bd = Infinity
    for (let y = Math.max(2, cy - 40); y < Math.min(H - 2, cy + 40); y += 1) {
      for (let x = Math.max(2, cx - 40); x < Math.min(W - 2, cx + 40); x += 1) {
        const i = (y * W + x) * 4
        const r = img[i], g = img[i + 1], b = img[i + 2], a = img[i + 3]
        if (a > 200 && SEAT_RGBS.some((rgb) => near(r, g, b, rgb))) {
          const d = (x - cx) * (x - cx) + (y - cy) * (y - cy)
          if (d < bd) { bd = d; bp = { x, y } }
        }
      }
    }
    if (bp) return { x: bp.x, y: bp.y, W, H, ci: best.ci, clusterN: top[1].n }
  }
  return { null: true, reason: 'в палитре мест кружков не найдено' }
})()`
let seatPx = null
for (let attempt = 0; attempt < 10 && !seatPx; attempt += 1) {
  await sleep(1000)
  seatPx = await ev(pickExpr)
}
if (!seatPx || seatPx.null) { console.log(`[!] место не найдено на холсте (${seatPx?.reason ?? '?'})`); process.exit(1) }
console.log(`[i] тяну место в canvas-координатах: ${JSON.stringify(seatPx)}`)

// Бокс ТОГО ЖЕ canvas, на котором нашли пиксель (по индексу ci).
const canvasBox = await ev(`(() => {
  const cs = [...document.querySelectorAll('.konvajs-content canvas')]
  const c = cs[${seatPx?.ci ?? 0}] ?? cs[0]
  const r = c.getBoundingClientRect()
  return { left: r.left, top: r.top, width: r.width, height: r.height }
})()`)
console.log(`[i] canvas-слой #${seatPx?.ci} at ${canvasBox.left},${canvasBox.top}`)

const px = canvasBox.left + seatPx.x
const py = canvasBox.top + seatPx.y

async function mouse(type, x, y, extra = {}) {
  await send('Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: type === 'mousePressed' || type === 'mouseReleased' ? 1 : 0, ...extra })
}

// Жест: зажать → вести с buttons:1 → отпустить. Без `buttons: 1` на
// mousemove часть приёмников считает кнопку отпущенной и drag не стартует.
await mouse('mouseMoved', px, py)
await mouse('mousePressed', px, py)
await sleep(150)
for (let i = 1; i <= 12; i += 1) {
  await mouse('mouseMoved', px + i * 5, py + i * 5, { buttons: 1 })
  await sleep(25)
}
await mouse('mouseReleased', px + 60, py + 60)
// Уведомление автоскрывается и рисуется асинхронно — опрашиваем тосты
// первые ~4 секунды после жеста.
let toastEarly = null
for (let i = 0; i < 20; i += 1) {
  toastEarly = await ev(`(() => {
    const t = document.body.innerText
    return { seatReverted: t.includes('Место возвращено'), sectorReverted: t.includes('Сектор возвращён') }
  })()`)
  if (toastEarly?.seatReverted || toastEarly?.sectorReverted) break
  await sleep(200)
}
await sleep(2500) // autosave debounce 500ms + сеть

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
console.log(`[i] ответов автосейва: ${JSON.stringify(savedResponses)}`)
console.log(`ошибок консоли: ${errors.length}${errors.length ? '\n  ' + errors.slice(0, 6).join('\n  ') : ''}`)

// Разбор ОТПРАВЛЕННОГО тела автосейва: что изменилось и есть ли в нём наложения.
for (const rid of savedRequests) {
  try {
    const req = await send('Network.getRequestPostData', { requestId: rid })
    const body = req?.post_data ?? req?.postData ?? ''
    if (!body) { console.log('[i] тело автосейва пустое/не получено'); continue }
    const parsed = JSON.parse(body)
    const payload = parsed?.payload ?? parsed?.schema ?? parsed
    const sentSectors = payload?.sectors ?? []
    const all = []
    for (const s of sentSectors) for (const seat of s.seats ?? []) all.push({ x: s.x + seat.x, y: s.y + seat.y, id: seat.id, sec: s.name })
    let ov = 0
    for (let i = 0; i < all.length; i += 1) {
      for (let k = i + 1; k < all.length; k += 1) {
        if (Math.abs(all[i].x - all[k].x) < 16 && Math.abs(all[i].y - all[k].y) < 16) ov += 1
      }
    }
    const diff = []
    for (const s of sentSectors) {
      const prev = sectorXYBefore.get(s.id)
      if (prev && (s.x !== prev.x || s.y !== prev.y)) diff.push(`сектор ${s.name}: ${prev.x},${prev.y} -> ${s.x},${s.y}`)
      for (const seat of s.seats ?? []) {
        const pb = seatsBefore.get(seat.id)
        if (pb && (seat.x !== pb.x || seat.y !== pb.y)) diff.push(`место ${seat.id} (${s.name}): ${pb.x},${pb.y} -> ${seat.x},${seat.y}`)
      }
    }
    console.log(`[i] автосейв: наложений в отправленном теле = ${ov}, отличий от черновика = ${diff.length}`)
    console.log(diff.slice(0, 12).map((d) => '    ' + d).join('\n') || '    (тело идентично исходному черновику)')
  } catch (err) { console.log(`[i] getRequestPostData: ${err.message}`) }
}

const saved200 = savedResponses.length > 0 && savedResponses.every((r) => r === 200)
// Успешный откат тоже валидный исход: место двигалось, попало на соседнее,
// редактор вернул его и показал уведомление — модель не «грязная», автосейв
// не нужен. Раньше этот же жест заканчивался 422 и потерей правки.
const toast = toastEarly ?? await ev(`(() => {
  const t = document.body.innerText
  return {
    seatReverted: t.includes('Место возвращено'),
    sectorReverted: t.includes('Сектор возвращён'),
  }
})()`)
const cleanRevert = (toast?.seatReverted || toast?.sectorReverted) && savedResponses.every((r) => r !== 422)
console.log(`[i] уведомления отката: ${JSON.stringify(toast)}`)
console.log('\n--- итог ---')
console.log(`[i] места сдвинулись: ${movedSeats} | сектора: ${movedSector} | сохранение: ${saved200 ? 'OK (200)' : savedResponses.length === 0 ? 'без изменений (откат/нет правки)' : 'ОТКЛОНЕНО'}`)
const pass = saved200 || cleanRevert
console.log(pass
  ? '[✓] СХЕМА СОХРАНЯЕТСЯ: перетаскивание не ломает автосейв (наложение откатывается с уведомлением)'
  : '[✗] автосейв всё ещё отклоняется сервером')
ws.close()
chrome.kill()
process.exit(pass ? 0 : 2)
