/**
 * Перехват схемы зала из виджета Яндекс Билетов (Яндекс Афиша).
 *
 * Виджет отдаёт сам HTML страницы, а план зала подтягивается отдельным XHR
 * (`/v4/sessions/<key>/hallplan`) уже из браузера, поэтому статическим curl
 * его не достать: базовый хост API лежит внутри бандла и в SSR-конфиг не
 * попадает. Здесь мы просто открываем виджет настоящим Chrome и слушаем сеть.
 *
 * Запуск: node .shots/yandex-hallplan.mjs [eventId] [clientKey] [outSuffix]
 * Результат: .shots/yandex-hallplan-raw<suffix>.json (+ лог всех запросов)
 */
import { spawn } from 'node:child_process'
import { mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { setTimeout as sleep } from 'node:timers/promises'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9411

const EVENT = process.argv[2] ?? '348671'
const PROFILE = `C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-yandex-hall-${EVENT}`
const SESSION = `ticketsteam-669@${EVENT}`
const CLID = process.argv[3] ?? '12071c6f-75d0-42e7-9900-54a347efb482'
const SUFFIX = process.argv[4] ?? ''
const URL = `https://widget.afisha.yandex.ru/w/sessions/${encodeURIComponent(SESSION)}?clientKey=${CLID}&lang=ru&mode=inline`

rmSync(PROFILE, { recursive: true, force: true })
mkdirSync(PROFILE, { recursive: true })

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${PORT}`,
    `--user-data-dir=${PROFILE}`,
    '--window-size=1440,1200',
    '--no-proxy-server',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function targets() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const list = await res.json()
      const page = list.find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* chrome ещё поднимается */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await targets()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
const netLog = []
let seq = 0

function send(method, params = {}, sessionId) {
  const id = ++seq
  const payload = { id, method, params }
  if (sessionId) payload.sessionId = sessionId
  ws.send(JSON.stringify(payload))
  return new Promise((resolve) => pending.set(id, resolve))
}

async function evaluate(expr) {
  const res = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  return res?.result?.result?.value
}

const ready = new Promise((resolve) => {
  ws.onopen = () => resolve()
})

ws.onmessage = async (evt) => {
  const msg = JSON.parse(evt.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Network.responseReceived') {
    const { requestId, response, type } = msg.params
    netLog.push({ requestId, type, url: response.url, status: response.status, mime: response.mimeType })
  }
}

await ready
await send('Network.enable')
await send('Runtime.enable')
await send('Page.enable')

await send('Page.navigate', { url: URL })

// Ждём, пока виджет отрендерит план зала: ищем маркер загрузки в DOM.
let sawHall = false
for (let i = 0; i < 60; i += 1) {
  await sleep(1000)
  const state = await evaluate(
    `(() => {
      const t = document.body ? document.body.innerText : '';
      const svg = document.querySelectorAll('svg').length;
      const seats = document.querySelectorAll('[data-seat-id], [class*="seat"], [class*="place"]').length;
      return { len: t.length, svg, seats, hint: t.slice(0, 200) };
    })()`,
  )
  if (state && (state.seats > 5 || state.svg > 3)) {
    sawHall = true
    console.log(`[•] план зала отрисован на ${i + 1}с: svg=${state.svg} seats=${state.seats}`)
    break
  }
}
if (!sawHall) console.log('[!] маркеры плана зала не найдены, но сеть собрана')

await sleep(4000)

// Тянем тела всех ответов, похожих на схему зала.
const interesting = netLog.filter((r) => {
  const u = r.url.toLowerCase()
  return (
    r.mime === 'application/json' &&
    (u.includes('hallplan') || u.includes('hall') || u.includes('seat') || u.includes('place') || u.includes('session'))
  )
})

const bodies = []
for (const r of interesting) {
  try {
    const res = await send('Network.getResponseBody', { requestId: r.requestId })
    const text = res?.body ?? ''
    bodies.push({ url: r.url, status: r.status, length: text.length, body: text })
  } catch (err) {
    bodies.push({ url: r.url, status: r.status, error: String(err) })
  }
}

writeFileSync(`.shots/yandex-hallplan-net${SUFFIX}.json`, JSON.stringify(netLog, null, 2))
writeFileSync(`.shots/yandex-hallplan-raw${SUFFIX}.json`, JSON.stringify(bodies, null, 2))

const plan = bodies.find((b) => b.url.includes('hallplans/'))
if (plan) {
  writeFileSync(`.shots/yandex-hallplan${SUFFIX}.json`, plan.body)
  console.log(`[✓] план зала сохранён: .shots/yandex-hallplan${SUFFIX}.json (${plan.length} байт)`)
} else {
  console.log('[!] план зала с CDN не перехвачен')
}

console.log(`[✓] запросов: ${netLog.length}, из них json с hall/seat/place/session: ${bodies.length}`)
for (const b of bodies) console.log(`    ${b.status} ${b.length ?? '-'} ${b.url.slice(0, 140)}`)

ws.close()
chrome.kill()
process.exit(0)
