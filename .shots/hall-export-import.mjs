/**
 * Сквозная проверка экспорта/импорта схем залов через РЕАЛЬНЫЙ интерфейс.
 *
 * Никаких обращений к внутренностям Vue: скрипт жмёт те же кнопки, что человек,
 * и читает то, что реально скачалось/загрузилось.
 *
 *   1. «Экспорт JSON» → файл A;
 *   2. «Импорт JSON» (файл A через настоящий <input type=file>) → уведомление;
 *   3. «Экспорт JSON» → файл B;  A === B ⇒ цикл идемпотентен;
 *   4. payload, который автосохранение положило на сервер, === файл A
 *      ⇒ экспорт и автосохранение не расходятся;
 *   5. две подложки (загрузка двух PNG) → переживают автосохранение + F5;
 *   6. геометрия банкетных столов восстанавливается после импорта.
 *
 * Требует: сервер на :8099 (БД nabilet_hall_lab) и .shots/hall-lab-last.json.
 * Запуск: node .shots/hall-export-import.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync, readFileSync, existsSync, readdirSync, rmSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9348
const BASE = 'http://127.0.0.1:8099'
const DL = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-ei-dl'
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })
rmSync(DL, { recursive: true, force: true })
mkdirSync(DL, { recursive: true })

const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

/* Два разных 1×1 PNG — чтобы отличить подложки друг от друга. */
const PNG_A = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
const PNG_B = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAE/wH/2k2sLwAAAABJRU5ErkJggg=='

const loginJson = await (await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})).json()
const TOKEN = loginJson.token ?? loginJson?.data?.token
if (!TOKEN) throw new Error('login failed')

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-ei-profile',
  '--window-size=1440,1000', '--force-device-scale-factor=1', 'about:blank',
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
const pending = new Map(); let seq = 0
const consoleErrors = []
function send(m, p = {}) { const id = ++seq; ws.send(JSON.stringify({ id, method: m, params: p })); return new Promise((r) => pending.set(id, r)) }
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return }
  if (m.method === 'Runtime.consoleAPICalled' && m.params?.type === 'error') consoleErrors.push(m.params.args?.map((a) => a.value ?? a.description).join(' '))
  if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params?.exceptionDetails?.exception?.description ?? 'exception')
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable'); await send('Page.enable')
await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: DL })

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  if (r?.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails)).slice(0, 400))
  return r?.result?.value
}
async function waitFor(expr, timeoutMs = 20000) {
  const t0 = Date.now()
  while (Date.now() - t0 < timeoutMs) { if (await ev(expr)) return true; await sleep(300) }
  return false
}
async function api(path) {
  const res = await fetch(`${BASE}/api/v1${path}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}` } })
  return res.json()
}
function jsonFiles() { return existsSync(DL) ? readdirSync(DL).filter((f) => f.endsWith('.json')) : [] }

/**
 * Канонический JSON: сортировка ключей на всех уровнях. Колонка MySQL JSON
 * хранит объекты отсортированными по ключу, поэтому побайтовое сравнение
 * «файл vs сервер» давало ложное расхождение — сравнивать надо по значению.
 */
function canon(value) {
  if (Array.isArray(value)) return value.map(canon)
  if (value && typeof value === 'object') {
    return Object.keys(value).sort().reduce((acc, k) => { acc[k] = canon(value[k]); return acc }, {})
  }
  return value
}
const sameJson = (a, b) => JSON.stringify(canon(a)) === JSON.stringify(canon(b))
function clearDownloads() { rmSync(DL, { recursive: true, force: true }); mkdirSync(DL, { recursive: true }) }

const report = { checks: [] }
function check(name, ok, extra = '') {
  report.checks.push({ name, ok, extra })
  console.log(`[${ok ? 'PASS' : ok === false ? 'FAIL' : 'INFO'}] ${name}${extra ? ' — ' + extra : ''}`)
}

const clickByText = (text) => ev(`(() => {
  const el = [...document.querySelectorAll('button, a')].find((n) => n.innerText.trim() === ${JSON.stringify(text)})
  if (!el) return false
  el.click(); return true
})()`)

/** Дождаться файла после нажатия «Экспорт JSON» и вернуть его содержимое. */
async function exportToJson(label) {
  clearDownloads()
  const clicked = await clickByText('Экспорт JSON')
  if (!clicked) return null
  for (let i = 0; i < 40; i += 1) {
    await sleep(250)
    const files = jsonFiles()
    if (files.length > 0) {
      const raw = readFileSync(`${DL}/${files[0]}`, 'utf8')
      try { return { name: files[0], data: JSON.parse(raw) } } catch { /* файл ещё пишется */ }
    }
  }
  console.log(`  (${label}: файл не появился)`)
  return null
}

async function importJson(data) {
  const text = JSON.stringify(data)
  return ev(`(async () => {
    const input = document.querySelector('input[type=file][accept*=".json"]')
    if (!input) return 'нет input[type=file]'
    const dt = new DataTransfer()
    dt.items.add(new File([${JSON.stringify(text)}], 'hall-schema.json', { type: 'application/json' }))
    input.files = dt.files
    input.dispatchEvent(new Event('change'))
    return 'dispatched'
  })()`)
}

/* ── старт ──────────────────────────────────────────────────────────── */
await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(4000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

const HALL = lab.banquetHall
await ev(`location.hash = '#/admin/halls/${HALL}/editor'`)
await sleep(500)
await send('Page.reload')
const ready = await waitFor(`/\\b52\\b/.test(document.querySelector('main')?.innerText ?? '')`, 30000)
check('редактор банкетного зала открылся (52 места)', ready)
if (!ready) {
  console.log(await ev(`(document.querySelector('main') ?? document.body).innerText.slice(0, 400)`))
  process.exit(1)
}
await sleep(1500)

/* ── 1. экспорт ─────────────────────────────────────────────────────── */
const A = await exportToJson('A')
check('«Экспорт JSON» скачивает файл', !!A, A ? A.name : '')
if (!A) process.exit(1)
check('в файле есть все подложки (поле backgrounds)', Array.isArray(A.data.backgrounds),
  `background=${A.data.background ? 'есть' : 'null'}, backgrounds=${Array.isArray(A.data.backgrounds) ? A.data.backgrounds.length : 'нет поля'}`)
const tableSectors = (A.data.sectors ?? []).filter((s) => s.shape === 'table')
check('в экспорте 6 банкетных столов с местами', tableSectors.length === 6,
  `столов: ${tableSectors.length}, мест: ${tableSectors.reduce((a, s) => a + (s.seats?.length ?? 0), 0)}`)

/* ── 2. импорт того же файла ────────────────────────────────────────── */
await importJson(A.data)
const notified = await waitFor(
  `document.body.innerText.includes('Схема импортирована') || document.body.innerText.includes('Импорт отклонён')`,
  15000,
)
const notice = await ev(`(() => { const t = document.body.innerText
  const m = t.match(/Схема импортирована[^\\n]*/) || t.match(/Импорт отклонён[^\\n]*/)
  return m ? m[0] : 'нет уведомления' })()`)
check('импорт файла принят редактором', notified && /импортирована/.test(notice), notice)
await sleep(1500)

/* ── 3. повторный экспорт: идемпотентность ──────────────────────────── */
const B = await exportToJson('B')
check('повторный экспорт после импорта совпадает с исходным файлом',
  !!B && sameJson(A.data, B.data),
  B ? `A.sectors=${A.data.sectors.length}, B.sectors=${B.data.sectors.length}` : 'файл не получен')
if (B && !sameJson(A.data, B.data)) {
  report.diffHint = { aKeys: Object.keys(A.data), bKeys: Object.keys(B.data) }
}

/* ── 4. файл === payload автосохранения на сервере ──────────────────── */
const versions = await api(`/halls/${HALL}/schema-versions`)
const list = Array.isArray(versions.data) ? versions.data : (versions.data?.data ?? [])
const latest = list.find((v) => v.status === 'draft') ?? list[0]
let serverSchema = latest?.schema
if (typeof serverSchema === 'string') { try { serverSchema = JSON.parse(serverSchema) } catch { /* raw */ } }
const serverMatches = sameJson(serverSchema, A.data)
check('черновик на сервере совпадает с экспортированным файлом (структурно)', serverMatches,
  serverMatches ? '' : `версия v${latest?.version} (${latest?.status})`)
if (!serverMatches) {
  report.serverSchema = { keys: serverSchema ? Object.keys(serverSchema) : null, version: latest?.version, status: latest?.status }
  writeFileSync('.shots/ei-A.json', JSON.stringify(A.data, null, 2))
  writeFileSync('.shots/ei-server.json', JSON.stringify(serverSchema, null, 2))
}

/* ── 5. две подложки: автосохранение + F5 ───────────────────────────── */
// Считаем ДО загрузки: зал мог уже иметь подложки с прошлого прогона,
// поэтому проверяем прирост, а не абсолютное число.
const bgBefore = Array.isArray(A.data.backgrounds) ? A.data.backgrounds.length : 0
const uploaded = await ev(`(async () => {
  const input = document.querySelector('input[type=file][accept*="image"]')
  if (!input) return 'нет input[type=file][accept*=image]'
  for (const b64 of [${JSON.stringify(PNG_A)}, ${JSON.stringify(PNG_B)}]) {
    const bin = atob(b64)
    const bytes = new Uint8Array(bin.length)
    for (let i = 0; i < bin.length; i += 1) bytes[i] = bin.charCodeAt(i)
    const dt = new DataTransfer()
    dt.items.add(new File([bytes], 'bg.png', { type: 'image/png' }))
    input.files = dt.files
    input.dispatchEvent(new Event('change'))
    await new Promise((r) => setTimeout(r, 900))
  }
  return 'uploaded'
})()`)
check('две картинки загружены как подложки', uploaded === 'uploaded', String(uploaded))
await sleep(2500) // автосохранение (debounce 500 мс + сеть)

const C = await exportToJson('C')
const bgAfter = Array.isArray(C?.data?.backgrounds) ? C.data.backgrounds.length : -1
check('обе подложки попадают в payload (раньше вторая терялась)',
  bgAfter === bgBefore + 2,
  `было ${bgBefore} → стало ${bgAfter} (ожидалось ${bgBefore + 2})`)

// F5 и снова экспорт — проверяем, что подложки пережили сохранение на сервере.
await send('Page.reload')
await waitFor(`/\\b52\\b/.test(document.querySelector('main')?.innerText ?? '')`, 30000)
await sleep(2000)
const D = await exportToJson('D')
const bgAfterReload = Array.isArray(D?.data?.backgrounds) ? D.data.backgrounds.length : -1
check('после F5 все подложки восстановлены',
  bgAfterReload === bgBefore + 2,
  `после перезагрузки ${bgAfterReload} (ожидалось ${bgBefore + 2})`)

/* ── 6. геометрия столов после цикла ───────────────────────────────── */
const E = await exportToJson('E')
const tablesE = (E?.data.sectors ?? []).filter((s) => s.shape === 'table')
const countsE = tablesE.map((s) => s.seats.length).sort((a, b) => a - b)
check('места за столами не потерялись после цикла импорта',
  JSON.stringify(countsE) === JSON.stringify([6, 8, 8, 8, 10, 12]),
  `мест по столам: ${countsE.join(',')}`)

/* ── 7. битый файл не затирает зал ─────────────────────────────────── */
await importJson({ sectors: [{ name: 'Без мест' }] })
const rejected = await waitFor(`document.body.innerText.includes('Импорт отклонён')`, 10000)
const stillThere = await ev(`/\\b52\\b/.test(document.querySelector('main')?.innerText ?? '')`)
check('битый файл отклонён и зал не затёрт', rejected && stillThere,
  `отклонён=${rejected}, зал на месте=${stillThere}`)

report.consoleErrors = consoleErrors
writeFileSync('.shots/hall-export-import-report.json', JSON.stringify(report, null, 2))
console.log('\nконсольных ошибок:', consoleErrors.length)
console.log('отчёт: .shots/hall-export-import-report.json')

ws.close(); chrome.kill(); process.exit(0)
