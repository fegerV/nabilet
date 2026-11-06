// Отладка клика по месту: где на самом деле точка и что под ней.
import fs from 'node:fs'

const PORT = 9400
const BASE = 'http://127.0.0.1:8000'
const SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
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
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 400)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 40000) => {
    const dl = Date.now() + ms
    while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(400) }
    return false
  }

  await send('Page.enable'); await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })
  await send('Page.navigate', { url: `${BASE}/?dbg=${Date.now()}#/event/${SLUG}` })
  console.log('READY:', await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`))
  await sleep(2500)

  console.log('BEFORE:', await evaluate(`JSON.stringify({ scrollY, innerH: innerHeight })`))

  const info = await evaluate(`(() => {
    const live = [...document.querySelectorAll('circle.seat-dot--live')];
    const el = live[Math.floor(live.length * 0.55)];
    if (!el) return 'no el';
    el.scrollIntoView({ block: 'center', inline: 'center' });
    const r = el.getBoundingClientRect();
    const x = Math.round(r.left + r.width / 2), y = Math.round(r.top + r.height / 2);
    const top = document.elementFromPoint(x, y);
    return JSON.stringify({
      x, y, w: Math.round(r.width), h: Math.round(r.height),
      scrollY,
      topTag: top ? top.tagName + '.' + (top.getAttribute('class') || '') : 'none',
      topAria: top ? top.getAttribute('aria-label') : null,
      label: el.getAttribute('aria-label'),
    });
  })()`)
  console.log('SEAT:', info)

  const s = JSON.parse(info)
  await sleep(800)
  console.log('AFTER SCROLL:', await evaluate(`JSON.stringify({ scrollY })`))

  for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
    await send('Input.dispatchMouseEvent', { type, x: s.x, y: s.y, button: type === 'mouseMoved' ? 'none' : 'left', clickCount: 1 })
    await sleep(150)
  }
  await sleep(2500)
  console.log('DOCK:', await evaluate(`document.querySelector('[data-summary]')?.innerText.replace(/\\s+/g,' ') || 'no dock'`))

  const r = await send('Page.captureScreenshot', { format: 'png' })
  if (r.result?.data) fs.writeFileSync('C:/Project/nabilet/.shots/dbg-click.png', Buffer.from(r.result.data, 'base64'))
  console.log('SHOT: dbg-click.png')
  ws.close()
}

main().catch((e) => { console.error('FATAL', e); process.exit(1) })
