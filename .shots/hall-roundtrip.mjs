/**
 * Проверка импорта/экспорта схем залов «в деле» (CDP + headless Chrome).
 *
 * Скрипт НЕ переписывает логику редактора, а вызывает её настоящие функции
 * из смонтированного компонента HallEditorPage (через Vue-интернал
 * `#app.__vue_app__._instance` → setupState), поэтому проверяет именно тот код,
 * который работает у пользователя:
 *
 *   1. drift:  buildSchemaPayload()  vs  файл, который отдаёт exportSchema();
 *   2. round-trip: payload → applyServerSchema() → payload (что теряется);
 *   3. реальный цикл экспорт → очистка → импорт через <input type=file>
 *      (DataTransfer + change) → сравнение payload до/после;
 *   4. импорт файла в БД-формате (sectors[].rows[].seats[]) — не отвергается ли
 *      клиентской валидацией и что из него получается;
 *   5. множественные фоны: сохраняется ли больше одного;
 *   6. есть ли в экспорте геометрия банкетного стола (tableCx/tableCy/tableRing).
 *
 * Требует: сервер на :8099 (БД nabilet_hall_lab) и .shots/hall-lab-last.json.
 * Запуск:  node .shots/hall-roundtrip.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync, readFileSync, existsSync, readdirSync, rmSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9346
const BASE = 'http://127.0.0.1:8099'
const DOWNLOAD_DIR = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-rt-dl'
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })
rmSync(DOWNLOAD_DIR, { recursive: true, force: true })
mkdirSync(DOWNLOAD_DIR, { recursive: true })

const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

/* ── вход в админку ─────────────────────────────────────────────────── */
const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson.token ?? loginJson?.data?.token
if (!TOKEN) throw new Error('login failed: ' + JSON.stringify(loginJson).slice(0, 300))
console.log('token ok')

/* ── headless Chrome ────────────────────────────────────────────────── */
const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-rt-profile',
  '--window-size=1440,900', '--force-device-scale-factor=1',
  'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 60; i += 1) {
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
const consoleErrors = []

function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}
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
await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: DOWNLOAD_DIR })

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  if (res?.exceptionDetails) {
    throw new Error('page eval: ' + (res.exceptionDetails.exception?.description ?? JSON.stringify(res.exceptionDetails)))
  }
  return res?.result?.value
}
async function waitFor(expression, timeoutMs) {
  const started = Date.now()
  while (Date.now() - started < timeoutMs) {
    if (await evaluate(expression)) return true
    await sleep(400)
  }
  return false
}
async function goto(hash, readyExpr, waitMs = 25000) {
  const t = hash.startsWith('#') ? hash : '#' + hash
  await evaluate(`location.hash = ${JSON.stringify(t)}`)
  await sleep(500)
  await send('Page.reload', { ignoreCache: false })
  const ok = await waitFor(readyExpr, waitMs)
  console.log(`nav ${t} → ${ok}`)
  return ok
}

/* ── хелперы, исполняемые в странице ────────────────────────────────── */
const PAGE_HELPERS = `
function __walkInst(inst, name, seen, depth) {
  if (!inst || depth > 80 || seen.has(inst)) return null
  seen.add(inst)
  const t = inst.type
  const nm = t && (t.__name || t.name)
  if (nm === name) return inst
  return __walkVNode(inst.subTree, name, seen, depth + 1)
}
function __walkVNode(vnode, name, seen, depth) {
  if (!vnode || typeof vnode !== 'object' || depth > 80) return null
  if (vnode.component) { const r = __walkInst(vnode.component, name, seen, depth); if (r) return r }
  const ch = vnode.children
  if (Array.isArray(ch)) { for (const c of ch) { const r = __walkVNode(c, name, seen, depth + 1); if (r) return r } }
  return null
}
function __editorState() {
  const app = document.querySelector('#app') && document.querySelector('#app').__vue_app__
  if (!app || !app._instance) return null
  const inst = __walkInst(app._instance, 'HallEditorPage', new Set(), 0)
  return inst ? inst.setupState : null
}
`

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(4000)
await waitFor(`document.readyState === 'complete' && !!document.querySelector('#app')`, 15000)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
console.log('token stored:', await evaluate(`localStorage.getItem('nabilet_admin_token')?.length ?? 0`))

// Открываем БАНКЕТНЫЙ зал (там есть столы — проверим и их геометрию).
const ready = await goto(
  `/admin/halls/${lab.banquetHall}/editor`,
  `(() => { ${PAGE_HELPERS}
     const st = __editorState()
     return !!st && Array.isArray(st.sectors) && st.sectors.reduce((a, s) => a + s.seats.length, 0) >= 52 })()`,
)
if (!ready) {
  console.error('редактор не прогрузился')
  await evaluate(`JSON.stringify({ text: (document.querySelector('main') ?? document.body).innerText.slice(0, 400) })`)
    .then((t) => console.error(t))
  process.exit(1)
}

const report = { hall: 'banquet', checks: [] }
function check(name, ok, extra = '') {
  report.checks.push({ name, ok, extra })
  console.log(`[${ok ? 'PASS' : ok === null ? 'INFO' : 'FAIL'}] ${name}${extra ? ' — ' + extra : ''}`)
}

/* ── 1. Что реально отдаёт exportSchema (через настоящий клик) ───────── */
const exportFile = await evaluate(`(() => { ${PAGE_HELPERS}
  const st = __editorState()
  st.exportSchema()
  return st.sectors.length + ':' + st.sectors.reduce((a, s) => a + s.seats.length, 0)
})()`)
console.log('exportSchema вызван, состояние:', exportFile)
let exported = null
for (let i = 0; i < 30; i += 1) {
  await sleep(300)
  const files = existsSync(DOWNLOAD_DIR) ? readdirSync(DOWNLOAD_DIR).filter((f) => f.endsWith('.json')) : []
  if (files.length > 0) { exported = JSON.parse(readFileSync(`${DOWNLOAD_DIR}/${files[0]}`, 'utf8')); break }
}
check('exportSchema() скачивает JSON-файл', !!exported, exported ? `${Object.keys(exported).join(',')}` : 'файл не появился')

/* ── 2. Drift: exportSchema vs buildSchemaPayload ───────────────────── */
const payloadFromPage = await evaluate(`(() => { ${PAGE_HELPERS}
  const st = __editorState()
  return JSON.stringify(st.buildSchemaPayload())
})()`)
const P1 = JSON.parse(payloadFromPage)
if (exported) {
  const same = JSON.stringify(exported) === JSON.stringify(P1)
  check('exportSchema() и buildSchemaPayload() не расходятся', same,
    same ? '' : 'РАСХОЖДЕНИЕ: экспорт и автосохранение пишут разное')
  report.exportVsPayload = { equal: same }
}

/* ── 3. Round-trip: payload → applyServerSchema → payload ──────────── */
const rt = await evaluate(`(() => { ${PAGE_HELPERS}
  const st = __editorState()
  const before = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
  st.applyServerSchema(JSON.parse(JSON.stringify(before)))
  const after = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
  return JSON.stringify({ before, after })
})()`)
const { before: RT1, after: RT2 } = JSON.parse(rt)

function diff(a, b, path = '', out = []) {
  if (out.length > 40) return out
  if (a === b) return out
  const ta = a === null ? 'null' : Array.isArray(a) ? 'array' : typeof a
  const tb = b === null ? 'null' : Array.isArray(b) ? 'array' : typeof b
  if (ta !== tb) { out.push(`${path}: ${ta} → ${tb}`); return out }
  if (ta === 'array') {
    if (a.length !== b.length) out.push(`${path}: длина ${a.length} → ${b.length}`)
    const n = Math.min(a.length, b.length)
    for (let i = 0; i < n; i += 1) diff(a[i], b[i], `${path}[${i}]`, out)
    return out
  }
  if (ta === 'object') {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)])
    for (const k of keys) {
      if (!(k in a)) { out.push(`${path}.${k}: появилось (${JSON.stringify(b[k]).slice(0, 40)})`); continue }
      if (!(k in b)) { out.push(`${path}.${k}: ПОТЕРЯНО (${JSON.stringify(a[k]).slice(0, 40)})`); continue }
      diff(a[k], b[k], `${path}.${k}`, out)
    }
    return out
  }
  out.push(`${path}: ${JSON.stringify(a)} → ${JSON.stringify(b)}`)
  return out
}

const rtDiff = diff(RT1, RT2)
check('round-trip applyServerSchema(buildSchemaPayload()) без потерь', rtDiff.length === 0,
  rtDiff.slice(0, 8).join(' | '))
report.roundTripDiff = rtDiff

/* ── 4. Реальный цикл: экспорт → очистка → импорт файла ────────────── */
if (exported) {
  const fileText = JSON.stringify(exported)
  const cycle = await evaluate(`(async () => { ${PAGE_HELPERS}
    const st = __editorState()
    const before = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
    // Очищаем зал — если импорт не сработает, это будет видно по результату.
    st.sectors = []
    st.statics = []
    st.backgrounds = []
    const input = st.jsonInput
    if (!input) return JSON.stringify({ error: 'jsonInput не найден' })
    const dt = new DataTransfer()
    dt.items.add(new File([${JSON.stringify(fileText)}], 'hall-schema-rt.json', { type: 'application/json' }))
    input.files = dt.files
    input.dispatchEvent(new Event('change'))
    await new Promise((r) => setTimeout(r, 1200))
    const after = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
    return JSON.stringify({ before, after, sectors: st.sectors.length,
      seats: st.sectors.reduce((a, s) => a + s.seats.length, 0),
      statics: st.statics.length })
  })()`)
  const cy = JSON.parse(cycle)
  if (cy.error) {
    check('импорт файла через <input type=file>', false, cy.error)
  } else {
    check('импорт файла восстанавливает зал', cy.sectors > 0 && cy.seats > 0,
      `${cy.sectors} секторов, ${cy.seats} мест, ${cy.statics} объектов`)
    const cyDiff = diff(cy.before, cy.after)
    check('цикл экспорт→импорт идемпотентен', cyDiff.length === 0, cyDiff.slice(0, 8).join(' | '))
    report.cycleDiff = cyDiff
  }
}

/* ── 5. Импорт файла в БД-формате (sectors[].rows[].seats[]) ───────── */
const dbFormat = {
  version: '1.0',
  canvas: { width: 900, height: 520 },
  sectors: [
    {
      name: 'Партер', code: 'PARTER', type: 'seated', width: 60, height: 40, x: 0, y: 0,
      rows: [
        {
          number: '1', label: 'Ряд 1', price_amount: 120000,
          seats: [
            { id: 1, number: '1', label: 'Ряд 1 Место 1', type: 'standard', x: 5, y: 6 },
            { id: 2, number: '2', label: 'Ряд 1 Место 2', type: 'vip', x: 7, y: 6 },
            { id: 3, number: '3', label: 'Ряд 1 Место 3', type: 'wheelchair', x: 9, y: 6 },
          ],
        },
        {
          number: '2', label: 'Ряд 2', price_amount: 90000,
          seats: [
            { id: 4, number: '1', label: 'Ряд 2 Место 1', type: 'standard', x: 5, y: 9 },
            { id: 5, number: '2', label: 'Ряд 2 Место 2', type: 'standard', x: 7, y: 9 },
            { id: 6, number: '3', label: 'Ряд 2 Место 3', type: 'standard', x: 9, y: 9 },
          ],
        },
      ],
    },
    {
      name: 'Танцпол', code: '', type: 'standing', width: 60, height: 40, x: 0, y: 0,
      rows: [{ number: '1', label: 'Танцпол', price_amount: 50000, seats: [{ id: 7, number: '', label: '', type: 'standing', x: 0, y: 0 }] }],
    },
  ],
  staticObjects: [{ id: 'stage-1', kind: 'stage', x: 300, y: 20, width: 300, height: 60, text: 'Сцена' }],
}
const dbRes = await evaluate(`(async () => { ${PAGE_HELPERS}
  const st = __editorState()
  const data = ${JSON.stringify(dbFormat)}
  const errs = st.validateSchema ? st.validateSchema(data) : 'validateSchema недоступна'
  const before = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
  st.sectors = []; st.statics = []; st.backgrounds = []
  const input = st.jsonInput
  const dt = new DataTransfer()
  dt.items.add(new File([JSON.stringify(data)], 'db-format.json', { type: 'application/json' }))
  input.files = dt.files
  input.dispatchEvent(new Event('change'))
  await new Promise((r) => setTimeout(r, 1200))
  const after = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
  // вернуть состояние обратно
  st.applyServerSchema(before)
  return JSON.stringify({ errs, sectors: st.sectors.length,
    seats: st.sectors.reduce((a, s) => a + s.seats.length, 0), after })
})()`)
const db = JSON.parse(dbRes)
check('файл в БД-формате (rows[].seats[]) проходит клиентскую валидацию', db.errs === null,
  db.errs === null ? '' : JSON.stringify(db.errs).slice(0, 300))
check('файл в БД-формате импортируется и даёт места', db.seats > 0,
  `${db.sectors} секторов, ${db.seats} мест`)
report.dbFormatImport = { validationErrors: db.errs, sectors: db.sectors, seats: db.seats }

/* ── 6. Множественные фоны: сколько выживает ───────────────────────── */
const bgRes = await evaluate(`(() => { ${PAGE_HELPERS}
  const st = __editorState()
  st.backgrounds = [
    { id: 'bg-1', src: '/bg1.png', x: 0, y: 0, width: 900, height: 520, rotation: 0, locked: false, opacity: 1 },
    { id: 'bg-2', src: '/bg2.png', x: 10, y: 10, width: 400, height: 300, rotation: 0, locked: false, opacity: 0.5 },
  ]
  const payload = JSON.parse(JSON.stringify(st.buildSchemaPayload()))
  st.applyServerSchema(JSON.parse(JSON.stringify(payload)))
  const restored = st.backgrounds.length
  return JSON.stringify({ inEditorBefore: 2, inPayload: payload.background ? 1 : 0, afterRoundTrip: restored })
})()`)
const bg = JSON.parse(bgRes)
check('несколько фонов переживают сохранение/импорт', bg.afterRoundTrip === 2,
  `в редакторе было 2 → в payload ${bg.inPayload} → после round-trip ${bg.afterRoundTrip}`)
report.backgrounds = bg

/* ── 7. Геометрия стола в экспорте ─────────────────────────────────── */
if (exported) {
  const tableSec = (exported.sectors ?? []).find((s) => s.shape === 'table')
  const keys = tableSec ? Object.keys(tableSec) : []
  check('в экспорте есть сектор-стол', !!tableSec)
  check('геометрия стола (tableCx/tableCy/tableRing) сохраняется в экспорте',
    tableSec ? keys.includes('tableCx') : false,
    tableSec ? `ключи: ${keys.join(',')}` : '')
  report.tableGeometry = { found: !!tableSec, keys }
}

/* ── 8. Валидация «плохих» файлов ──────────────────────────────────── */
const badRes = await evaluate(`(() => { ${PAGE_HELPERS}
  const st = __editorState()
  const cases = {
    'пустой массив': [],
    'сектор без имени': { sectors: [{ seats: [{ row: 1, number: 1 }] }] },
    'сектор без мест': { sectors: [{ name: 'Пусто' }] },
    'плохая форма': { sectors: [{ name: 'A', shape: 'triangle', seats: [{ row: 1, number: 1 }] }] },
    'угол дуги 400': { sectors: [{ name: 'A', shape: 'arc', arcSpread: 400, seats: [{ row: 1, number: 1 }] }] },
    'место row=0': { sectors: [{ name: 'A', seats: [{ row: 0, number: 1 }] }] },
  }
  const out = {}
  for (const [k, v] of Object.entries(cases)) out[k] = st.validateSchema(v)
  return JSON.stringify(out)
})()`)
const bad = JSON.parse(badRes)
const badOk = Object.values(bad).every((v) => Array.isArray(v) && v.length > 0)
check('клиентская валидация отвергает все 6 битых файлов', badOk, JSON.stringify(bad).slice(0, 400))
report.validation = bad

report.consoleErrors = consoleErrors
writeFileSync('.shots/hall-roundtrip-report.json', JSON.stringify(report, null, 2))
console.log('\nконсольных ошибок:', consoleErrors.length)
console.log('отчёт: .shots/hall-roundtrip-report.json')

ws.close()
chrome.kill()
process.exit(0)
