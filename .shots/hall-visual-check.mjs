// Скриншот схемы зала на витрине + проверка геометрии отрисованного SVG.
//
// Сравниваем ОТРИСОВАННЫЕ позиции мест с координатами из schema_json: если
// компонент масштабирует/сдвигает зал неоднородно, это видно по расхождению
// отношения расстояний по X и Y.
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

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

await send('Page.enable'); await send('Runtime.enable')
await send('Network.setCacheDisabled', { cacheDisabled: true })
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, mobile: false, deviceScaleFactor: 2 })

await send('Page.navigate', { url: `${BASE}/?map=${Date.now()}#/event/${SLUG}` })
for (let i = 0; i < 60; i++) {
  if (await evaluate(`document.querySelectorAll('circle.seat-hit').length > 100`)) break
  await sleep(500)
}
await sleep(2500)

// Габариты отрисованной схемы.
console.log('MAP BOX:', await evaluate(`(() => {
  const svg = document.querySelector('svg[aria-label="Схема зала"]')
  if (!svg) return 'no svg'
  const r = svg.getBoundingClientRect()
  const vb = svg.getAttribute('viewBox')
  return JSON.stringify({ w: Math.round(r.width), h: Math.round(r.height), viewBox: vb })
})()`))

// Отношение ширины к высоте зала: у Яндекса ряды идут дугой, поэтому
// «сплющенность» видна именно по этому числу.
console.log('ASPECT:', await evaluate(`(() => {
  const svg = document.querySelector('svg[aria-label="Схема зала"]')
  if (!svg) return null
  const dots = [...svg.querySelectorAll('circle.seat-dot')]
  if (dots.length < 50) return 'too few dots: ' + dots.length
  let minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9
  for (const d of dots) {
    const x = Number(d.getAttribute('cx')), y = Number(d.getAttribute('cy'))
    minX = Math.min(minX, x); maxX = Math.max(maxX, x)
    minY = Math.min(minY, y); maxY = Math.max(maxY, y)
  }
  return JSON.stringify({ dots: dots.length, w: +(maxX - minX).toFixed(1), h: +(maxY - minY).toFixed(1), aspect: +((maxX - minX) / (maxY - minY)).toFixed(3) })
})()`))

// Шаг между соседними местами в отрисованном SVG (в единицах viewBox).
console.log('DOT STEP:', await evaluate(`(() => {
  const svg = document.querySelector('svg[aria-label="Схема зала"]')
  if (!svg) return null
  const dots = [...svg.querySelectorAll('circle.seat-dot')].map((d) => ({
    x: Number(d.getAttribute('cx')), y: Number(d.getAttribute('cy')),
    id: d.getAttribute('data-seat-id') || d.parentElement?.getAttribute('data-seat-id') || '',
  }))
  const rows = new Map()
  for (const d of dots) {
    const m = /r(\\d+)-p(\\d+)/.exec(d.id)
    if (!m) continue
    const key = m[1]
    if (!rows.has(key)) rows.set(key, [])
    rows.get(key).push({ ...d, n: Number(m[2]) })
  }
  const steps = []
  for (const [, list] of rows) {
    list.sort((a, b) => a.n - b.n)
    for (let i = 1; i < list.length; i++) {
      if (list[i].n - list[i - 1].n !== 1) continue
      steps.push(Math.hypot(list[i].x - list[i - 1].x, list[i].y - list[i - 1].y))
    }
  }
  if (!steps.length) return 'no row ids'
  const mean = steps.reduce((a, b) => a + b, 0) / steps.length
  return JSON.stringify({ pairs: steps.length, min: +Math.min(...steps).toFixed(2), max: +Math.max(...steps).toFixed(2), mean: +mean.toFixed(2) })
})()`))

const r = await send('Page.captureScreenshot', { format: 'png' })
if (r.result?.data) fs.writeFileSync('C:/Project/nabilet/.shots/hall-live.png', Buffer.from(r.result.data, 'base64'))
console.log('SHOT: hall-live.png')
ws.close()
