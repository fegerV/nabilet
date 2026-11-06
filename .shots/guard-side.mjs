// Побочные эффекты нового guard'а: витрина с мёртвым токеном не должна
// редиректить, админский guard — работать по-прежнему.
import fs from 'node:fs'

const PORT = 9400
const BASE = 'http://127.0.0.1:8001'
const VALID = fs.readFileSync('.shots/club-token.env', 'utf8').split('=')[1].trim()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })
  let id = 0
  const pending = new Map()
  const errors = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Runtime.exceptionThrown') errors.push(String(m.params?.exceptionDetails?.text ?? '').slice(0, 160))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 25000) => { const dl = Date.now() + ms; while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(300) } return false }
  const me = () => evaluate(`performance.getEntriesByType('resource').filter(e => /\\/api\\/v1\\/me$/.test(e.name)).length`)

  await send('Page.enable'); await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })
  await send('Page.navigate', { url: `${BASE}/?b=${Date.now()}#/` })
  await sleep(2500)

  // Витрина с мёртвым токеном: остаёмся на месте, кабинет не трогаем.
  await evaluate(`(() => { localStorage.setItem('nabilet_customer_token', 'deadbeef'.repeat(16)); return 1 })()`)
  await send('Page.navigate', { url: `${BASE}/?c=${Date.now()}#/` })
  await waitFor(`document.body.innerText.length > 200`)
  await sleep(2600)
  console.log('витрина + мёртвый токен →', await evaluate(`location.hash`), '| /me:', await me())

  // Админка гостем — прежнее поведение (редирект на админский вход).
  await evaluate(`localStorage.removeItem('nabilet_customer_token')`)
  await send('Page.navigate', { url: `${BASE}/?d=${Date.now()}#/admin` })
  await waitFor(`location.hash.includes('admin')`)
  await sleep(2000)
  console.log('гость → #/admin →', await evaluate(`location.hash`), '| /me:', await me())

  // Админка с админским токеном не должна ломаться асинхронным guard'ом.
  await evaluate(`localStorage.setItem('nabilet_admin_token', 'x')`)
  await send('Page.navigate', { url: `${BASE}/?e=${Date.now()}#/admin` })
  await sleep(3000)
  console.log('битый админ-токен → #/admin →', await evaluate(`location.hash`))
  await evaluate(`localStorage.removeItem('nabilet_admin_token')`)

  // Кабинет с живым токеном после всех перестановок.
  await evaluate(`localStorage.setItem('nabilet_customer_token', ${JSON.stringify(VALID)})`)
  await send('Page.navigate', { url: `${BASE}/?f=${Date.now()}#/account` })
  await waitFor(`!!document.querySelector('h1')`)
  await sleep(2600)
  console.log('живой токен → #/account →', await evaluate(`location.hash`), '| h1:', await evaluate(`document.querySelector('h1')?.innerText.trim()`), '| /me:', await me())
  console.log('ОШИБКИ:', JSON.stringify(errors.slice(0, 5)))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
