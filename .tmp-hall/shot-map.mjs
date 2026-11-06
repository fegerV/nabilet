import fs from 'node:fs'
const PORT = 9400, BASE = 'http://127.0.0.1:8000', SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
const page = list.find((t) => t.type === 'page')
const ws = new WebSocket(page.webSocketDebuggerUrl)
await new Promise((res) => ws.addEventListener('open', res, { once: true }))
let id = 0; const pending = new Map()
ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id) } })
const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
const evaluate = async (e) => (await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })).result?.result?.value
await send('Page.enable'); await send('Runtime.enable')
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, mobile: false, deviceScaleFactor: 2 })
await send('Page.navigate', { url: `${BASE}/?m=${Date.now()}#/event/${SLUG}` })
for (let i = 0; i < 60; i++) { if (await evaluate(`document.querySelectorAll('circle.seat-hit').length>100`)) break; await sleep(500) }
await sleep(2000)
const box = JSON.parse(await evaluate(`(() => { const s=document.querySelector('svg[aria-label="Схема зала"]'); const r=s.getBoundingClientRect(); return JSON.stringify({x:r.x,y:r.y,w:r.width,h:r.height}) })()`))
const r = await send('Page.captureScreenshot', { format: 'png', clip: { x: box.x, y: box.y, width: box.w, height: box.h, scale: 2 } })
fs.writeFileSync('C:/Project/nabilet/.shots/hall-map-only.png', Buffer.from(r.result.data, 'base64'))
console.log('SHOT hall-map-only.png', JSON.stringify(box))
ws.close()
