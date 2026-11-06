// Почему при недействительном токене клуб не делает ни одного /me/* запроса.
// Смотрим журнал ресурсов (он включает и упавшие запросы) и состояние стора.
import fs from 'node:fs'

const PORT = 9400
const BASE = 'http://127.0.0.1:8001'
const TOKEN = fs.readFileSync('.shots/club-token.env', 'utf8').split('=')[1].trim()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })
  let id = 0
  const pending = new Map()
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id) } })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }

  await send('Page.enable'); await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  // Сначала валидный токен — чтобы понять, что запросы вообще бывают.
  await send('Page.navigate', { url: `${BASE}/?p1=${Date.now()}#/` })
  await sleep(1500)
  await evaluate(`(() => { localStorage.setItem('nabilet_customer_token', ${JSON.stringify(TOKEN)}); return 1 })()`)
  await send('Page.navigate', { url: `${BASE}/?p2=${Date.now()}#/club` })
  await sleep(7000)
  console.log('ВАЛИДНЫЙ токен, ресурсы /me/:', await evaluate(`JSON.stringify(performance.getEntriesByType('resource').filter(e => e.name.includes('/api/v1/me/')).map(e => e.name.split('/api/v1')[1] + ' ' + Math.round(e.duration) + 'ms'))`))
  console.log('текст:', await evaluate(`document.body.innerText.replace(/\\s+/g,' ').match(/КЛУБ ЗРИТЕЛЕЙ[^А-Я]{0,120}/)?.[0] ?? 'нет клуба'`))

  // Теперь битый токен.
  await evaluate(`(() => { localStorage.setItem('nabilet_customer_token', 'deadbeef'.repeat(16)); return 1 })()`)
  await send('Page.navigate', { url: `${BASE}/?p3=${Date.now()}#/club` })
  await sleep(7000)
  console.log('БИТЫЙ токен, ресурсы /me/:', await evaluate(`JSON.stringify(performance.getEntriesByType('resource').filter(e => e.name.includes('/api/v1/me/')).map(e => e.name.split('/api/v1')[1]))`))
  console.log('все /api ресурсы:', await evaluate(`JSON.stringify(performance.getEntriesByType('resource').filter(e => e.name.includes('/api/')).map(e => e.name.split('/api/v1')[1] ?? e.name))`))
  console.log('токен в LS:', await evaluate(`(localStorage.getItem('nabilet_customer_token') || 'нет').slice(0, 12)`))
  console.log('текст:', await evaluate(`document.body.innerText.replace(/\\s+/g,' ').slice(0, 260)`))
  const r = await send('Page.captureScreenshot', { format: 'png' })
  if (r.result?.data) fs.writeFileSync('.shots/club-badtoken.png', Buffer.from(r.result.data, 'base64'))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
