/** Быстрая диагностика: почему маршрут конструктора зала не открывается. */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { readFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9355
const BASE = 'http://127.0.0.1:8099'
const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const TOKEN = (await login.json()).data?.token
if (!TOKEN) throw new Error('login failed: no token')

const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-first-run',
  `--remote-debugging-port=${PORT}`, '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-probe',
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
const logs = []
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return }
  if (m.method === 'Runtime.consoleAPICalled') logs.push(`${m.params.type}: ${m.params.args?.map((a) => a.value ?? a.description).join(' ')}`)
  if (m.method === 'Runtime.exceptionThrown') logs.push('EXC: ' + (m.params?.exceptionDetails?.exception?.description ?? ''))
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
await send('Network.enable')

const failed = []
const responses = []
const reqHeaders = []
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.method === 'Network.loadingFailed') failed.push(m.params?.errorText + ' ' + (m.params?.type ?? ''))
  if (m.method === 'Network.requestWillBeSent') {
    const r = m.params?.request
    if (r?.url?.includes('/api/')) {
      const auth = r.headers?.Authorization ?? r.headers?.authorization ?? '(нет)'
      reqHeaders.push(`${r.method} ${r.url.replace('http://127.0.0.1:8099', '')} auth=${String(auth).slice(0, 24)}…`)
    }
  }
  if (m.method === 'Network.responseReceived') {
    const r = m.params?.response
    if (r?.url?.includes('/api/')) responses.push(`${r.status} ${r.url.replace('http://127.0.0.1:8099', '')}`)
  }
})

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  return r?.result?.value
}

const go = async (hash, ms) => { await send('Page.navigate', { url: `${BASE}/${hash}` }); await sleep(ms) }

await go('#/', 4000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
console.log('token set:', await ev(`localStorage.getItem('nabilet_admin_token') ? 'yes' : 'no'`))

// Меняем хэш напрямую — Page.navigate на same-document URL ведёт себя иначе.
await ev(`location.hash = '#/admin/halls/${lab.banquetHall}/editor'`)
for (const ms of [500, 1500, 3000, 5000, 8000]) {
  await sleep(ms === 500 ? 500 : 1000)
  console.log(`t≈${ms}ms href:`, await ev('location.href'), '| token:', await ev(`localStorage.getItem('nabilet_admin_token') ? 'yes' : 'no'`))
}
console.log('main head:', (await ev(`(document.querySelector('main') ?? document.body).innerText.slice(0,160)`)))
console.log('has konva canvas:', await ev(`document.querySelectorAll('canvas').length`))
console.log('admin shell present:', await ev(`document.body.innerText.includes('Витрина') || document.body.innerText.includes('Обзор')`))
console.log('#app len:', await ev(`document.querySelector('#app')?.innerHTML.length ?? -1`))
console.log('#app head:', (await ev(`(document.querySelector('#app')?.innerHTML ?? '').slice(0,300)`)))
console.log('body text len:', await ev(`document.body.innerText.length`))
console.log('scripts:', await ev(`[...document.querySelectorAll('script[src]')].map(s=>s.getAttribute('src')).join(' | ')`))
for (const route of ['#/admin', '#/admin/halls', `#/admin/halls/${lab.banquetHall}/editor`]) {
  await ev(`location.hash = ${JSON.stringify(route)}`)
  await sleep(6000)
  const info = await ev(`(() => ({
    href: location.href,
    appLen: document.querySelector('#app')?.innerHTML.length ?? -1,
    text: (document.querySelector('main') ?? document.body).innerText.slice(0,120),
    canvas: document.querySelectorAll('canvas').length,
  }))()`)
  console.log(route, '=>', JSON.stringify(info))
}
console.log('--- api responses ---')
console.log(responses.slice(-30).join('\n'))
console.log('--- api request headers ---')
console.log(reqHeaders.slice(-30).join('\n'))
console.log('--- console log tail ---')
console.log(logs.slice(-25).join('\n'))
console.log('--- failed requests ---')
console.log(failed.slice(-25).join('\n'))

ws.close(); chrome.kill(); process.exit(0)
