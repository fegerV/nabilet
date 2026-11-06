// Замер раскладки страницы события: сколько ширины получила схема зала.
//
// Нужен как быстрый индикатор после правок вёрстки: до переделки карта жила в
// колонке 340 px рядом со сводкой и получала ~треть ширины. Печатает ширину
// карты, ширину полотна SVG и наличие нижней полосы с итогом.
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
const WIDTH = Number(process.env.W || 1440)
const HEIGHT = Number(process.env.H || 1000)
const TAG = process.env.TAG || 'desktop'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })

  let id = 0
  const pending = new Map()
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id) }
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 40000) => {
    const dl = Date.now() + ms
    while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(400) }
    return false
  }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
    if (r.result?.data) fs.writeFileSync(`C:/Project/nabilet/.shots/${name}`, Buffer.from(r.result.data, 'base64'))
    console.log('SHOT:', name)
  }

  await send('Page.enable'); await send('Runtime.enable')
  await send('Network.enable'); await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: WIDTH, height: HEIGHT, mobile: false, deviceScaleFactor: 1 })

  await send('Page.navigate', { url: `${BASE}/?layout=${Date.now()}#/event/${SLUG}` })
  const ready = await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`)
  console.log('MAP READY:', ready)
  await sleep(2500)

  const metrics = await evaluate(`(() => {
    const map = document.querySelector('svg[aria-label="Схема зала"]')
    const dock = document.querySelector('[data-summary]')
    const poster = document.querySelector('img[alt^="Афиша"]')
    const section = document.querySelector('section[aria-label="Покупка билетов"]')
    const m = map?.getBoundingClientRect()
    const p = poster?.getBoundingClientRect()
    const s = section?.getBoundingClientRect()
    const d = dock?.getBoundingClientRect()
    return JSON.stringify({
      viewport: innerWidth + 'x' + innerHeight,
      mapW: m && Math.round(m.width), mapH: m && Math.round(m.height),
      mapTop: m && Math.round(m.top),
      buySectionTop: s && Math.round(s.top),
      buySectionW: s && Math.round(s.width),
      posterW: p && Math.round(p.width), posterH: p && Math.round(p.height),
      dock: Boolean(dock),
      dockTop: d && Math.round(d.top),
      dockText: dock ? dock.innerText.replace(/\\s+/g, ' ').slice(0, 120) : null,
      caption: [...document.querySelectorAll('p')].some(e => /Основной зал · вт/.test(e.innerText || '')),
    })
  })()`)
  console.log('METRICS:', metrics)
  await shot(`layout-${TAG}.png`)

  ws.close()
}

main().catch((e) => { console.error('FATAL', e); process.exit(1) })
