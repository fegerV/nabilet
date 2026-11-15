/**
 * Перехват hallplan со страницы события afisha.yandex.ru.
 * Подключаемся к КАЖДОЙ вкладке напрямую (poll /json/list), слушаем Network.
 * Запуск: node .shots/afisha-event-hallplan.mjs <eventUrl> <outFile> [timeoutSec]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const URL_ = process.argv[2]
const OUT = process.argv[3]
const TIMEOUT = Number(process.argv[4] ?? 80) * 1000
const CHROME = 'C://Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9419

if (!URL_ || !OUT) { console.error('нужны аргументы: <eventUrl> <outFile>'); process.exit(1) }

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${PORT}`,
  `--user-data-dir=C://Users//Professional//AppData//Local//Temp//nabilet-af-${Date.now()}`,
  '--window-size=1440,1200', '--no-proxy-server', 'about:blank',
], { stdio: 'ignore' })

const halls = []      // {url, json}
const pendingUrls = new Map() // requestId -> url (per connection)

function wire(ws, connId) {
  const pending = new Map()
  let seq = 0
  const send = (method, params = {}) => {
    const id = ++seq
    ws.send(JSON.stringify({ id, method, params }))
    return new Promise((resolve) => pending.set(id, resolve))
  }
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result ?? m.error); pending.delete(m.id); return }
    if (m.method === 'Network.responseReceived') {
      const url = m.params?.response?.url ?? ''
      if (url.includes('hallplan') || url.includes('/api/tickets/')) pendingUrls.set(connId + ':' + m.params.requestId, { url, connId })
    }
    if (m.method === 'Network.loadingFinished') {
      const key = connId + ':' + m.params.requestId
      const hit = pendingUrls.get(key)
      if (hit && halls.length < 20) {
        send('Network.getResponseBody', { requestId: m.params.requestId })
          .then((r) => {
            const body = r?.body
            if (!body) return
            try { halls.push({ url: hit.url, json: JSON.parse(body) }) } catch { /* бинарный/чужой */ }
          })
      }
    }
  })
  return { send }
}

async function listPages() {
  for (let i = 0; i < 60; i += 1) {
    try { return (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()).filter((t) => t.type === 'page') } catch {}
    await sleep(250)
  }
  return []
}

const conns = new Map() // targetId -> {ws, send}
async function attachAll() {
  for (const t of await listPages()) {
    if (conns.has(t.id)) continue
    const ws = new WebSocket(t.webSocketDebuggerUrl)
    await new Promise((r) => ws.addEventListener('open', r, { once: true }))
    const c = wire(ws, t.id)
    await c.send('Network.enable')
    await c.send('Page.enable')
    await c.send('Runtime.enable')
    conns.set(t.id, { ws, send: c.send, url: t.url })
  }
}

await attachAll()
// открытие события: если главная вкладка about:blank — навигируем её
const blank = [...conns.values()].find((c) => c.url === 'about:blank')
if (blank) await blank.send('Page.navigate', { url: URL_ })
else await fetch(`http://127.0.0.1:${PORT}/json/new?${encodeURIComponent(URL_)}`)

const t0 = Date.now()
let clicked = false
while (Date.now() - t0 < TIMEOUT) {
  await sleep(2000)
  await attachAll()
  const done = halls.some((h) => h.json?.result?.hallplan
    || (h.json?.status === 'completed' && typeof h.json?.url === 'string'))
  if (done) break
  if (!clicked) {
    for (const c of conns.values()) {
      const r = await c.send('Runtime.evaluate', {
        expression: `(() => {
          const btn = document.querySelector('[data-test-id="eventPage.buyTicketsButton.button"]')
            ?? [...document.querySelectorAll('button, a')].find(b => /купить/i.test(b.textContent ?? '') && !b.disabled)
          if (btn) { btn.click(); return 'clicked' }
          return 'none'
        })()`,
        returnByValue: true,
      }).catch(() => null)
      if (r?.result?.value === 'clicked') clicked = true
    }
  }
}

const poll = halls.find((h) => h.json?.status === 'completed' && typeof h.json?.url === 'string')
const direct = halls.find((h) => h.json?.result?.hallplan)
let plan = direct?.json?.result?.hallplan ?? null
if (!plan && poll?.json?.url) {
  const res = await fetch(poll.json.url)
  plan = await res.json().catch(() => null)
}
if (plan && plan.levels) {
  writeFileSync(OUT, JSON.stringify({ status: 'success', result: { hallplan: plan } }))
  const seats = plan.levels.reduce((n, l) => n + (l.seats?.length ?? 0), 0)
  console.log(`[✓] hallplan пойман: уровней ${plan.levels.length}, свободных мест ${seats}`)
  console.log(`[✓] файл: ${OUT}`)
  chrome.kill()
  process.exit(0)
}
console.log('[!] hallplan не пойман. Пойманные ответы:')
for (const h of halls) console.log('   ', h.url.slice(0, 140))
chrome.kill()
process.exit(1)
