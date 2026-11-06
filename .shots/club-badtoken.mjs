// Точечная проба: что показывает #/club с недействительным токеном.
// В общем прогоне страница оказалась пустой — надо понять, это пустое
// состояние SPA, ошибка сервера или гонка с загрузкой документа.
import fs from 'node:fs'

const PORT = 9400
const BASE = 'http://127.0.0.1:8001'
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })

  let id = 0
  const pending = new Map()
  const net = []
  const logs = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Network.responseReceived') {
      const u = m.params.response.url
      if (u.includes('/api/v1/')) net.push(`${m.params.response.status} ${u.replace(BASE, '').split('?')[0]}`)
      if (u.replace(BASE, '').startsWith('/?') || u === BASE + '/') net.push(`DOC ${m.params.response.status}`)
    }
    if (m.method === 'Runtime.consoleAPICalled') logs.push(`${m.params.type}: ${m.params.args.map((a) => String(a.value ?? '')).join(' ').slice(0, 150)}`)
    if (m.method === 'Runtime.exceptionThrown') logs.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 200))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  await send('Page.navigate', { url: `${BASE}/?boot2=${Date.now()}#/` })
  await sleep(1800)
  await evaluate(`(() => {
    localStorage.setItem('nabilet_customer_token', 'deadbeef'.repeat(16));
    localStorage.setItem('nabilet_customer_user', JSON.stringify({ id: 9, email: 'seat-buyer@example.test', first_name: 'Покупатель', last_name: 'Мест' }));
    return 1
  })()`)

  net.length = 0
  await send('Page.navigate', { url: `${BASE}/?bad=${Date.now()}#/club` })
  for (const t of [1000, 2000, 4000, 6000]) {
    await sleep(t === 1000 ? 1000 : 1000)
    console.log(`t≈${t}ms`, JSON.stringify({
      hash: await evaluate(`location.hash`),
      textLen: await evaluate(`document.body.innerText.length`),
      appLen: await evaluate(`document.getElementById('app')?.innerHTML.length ?? -1`),
      first: await evaluate(`document.body.innerText.replace(/\\s+/g,' ').slice(0, 120)`),
    }))
  }
  console.log('NET:', JSON.stringify(net))
  console.log('LOGS:', JSON.stringify(logs.slice(0, 8)))
  const r = await send('Page.captureScreenshot', { format: 'png' })
  if (r.result?.data) fs.writeFileSync('.shots/club-badtoken.png', Buffer.from(r.result.data, 'base64'))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
