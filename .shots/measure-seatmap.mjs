/** Измеряет, помещается ли карта зала в контейнер (обрезка нижнего ряда). */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { readFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9366
const BASE = 'http://127.0.0.1:8099'
const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const TOKEN = (await login.json()).data.token

const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-first-run',
  `--remote-debugging-port=${PORT}`, '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-measure',
  '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const p = (await r.json()).find((t) => t.type === 'page')
      if (p) return p
    } catch { /* wait */ }
    await sleep(250)
  }
  throw new Error('no CDP')
}

const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0
const send = (method, params = {}) => {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((res) => pending.set(id, res))
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
await sleep(3500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

for (const [label, session] of [['концертный', lab.concertSession], ['банкетный', lab.banquetSession]]) {
  await ev(`location.hash = '#/event/${lab.eventSlug}/seats?session=${session}'`)
  await sleep(500)
  await send('Page.reload', {})
  for (let i = 0; i < 30; i += 1) {
    const n = await ev(`document.querySelectorAll('svg circle, svg rect').length`)
    if (n > 20) break
    await sleep(500)
  }
  await sleep(1500)

  const info = await ev(`(() => {
    const svg = document.querySelector('svg')
    if (!svg) return JSON.stringify({ error: 'no svg' })
    const clip = svg.closest('.overflow-hidden')
    const svgBox = svg.getBoundingClientRect()
    const clipBox = clip ? clip.getBoundingClientRect() : null
    let minTop = Infinity, maxBottom = -Infinity
    for (const el of svg.querySelectorAll('circle')) {
      const b = el.getBoundingClientRect()
      if (b.width === 0) continue
      minTop = Math.min(minTop, b.top); maxBottom = Math.max(maxBottom, b.bottom)
    }
    return JSON.stringify({
      svgHeight: Math.round(svgBox.height),
      svgWidth: Math.round(svgBox.width),
      clipHeight: clipBox ? Math.round(clipBox.height) : null,
      clipTop: clipBox ? Math.round(clipBox.top) : null,
      clipBottom: clipBox ? Math.round(clipBox.bottom) : null,
      seatsTop: Math.round(minTop),
      seatsBottom: Math.round(maxBottom),
      clippedBelow: clipBox ? Math.round(maxBottom - clipBox.bottom) : null,
      clippedAbove: clipBox ? Math.round(clipBox.top - minTop) : null,
    })
  })()`)
  console.log(label, '=>', info)
}

ws.close(); chrome.kill(); process.exit(0)
