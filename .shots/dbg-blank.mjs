import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9366
const BASE = 'http://127.0.0.1:8099'

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-dbg',
  '--window-size=1440,900', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)
      const p = (await res.json()).find((t) => t.type === 'page')
      if (p) return p
    } catch {}
    await sleep(250)
  }
  throw new Error('no cdp')
}
const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map(); let seq = 0
function send(m, p = {}) { const id = ++seq; ws.send(JSON.stringify({ id, method: m, params: p })); return new Promise((r) => pending.set(id, r)) }
const logs = []
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return }
  if (m.method === 'Runtime.consoleAPICalled') logs.push('[' + m.params.type + '] ' + (m.params.args?.map((a) => a.value ?? a.description).join(' ')))
  if (m.method === 'Runtime.exceptionThrown') logs.push('[exc] ' + (m.params?.exceptionDetails?.exception?.description ?? ''))
  if (m.method === 'Log.entryAdded') logs.push('[log:' + m.params.entry.level + '] ' + m.params.entry.text)
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable'); await send('Page.enable'); await send('Log.enable'); await send('Network.enable')
const ev = async (x) => (await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true }))?.result?.value ?? ''

await send('Page.navigate', { url: BASE + '/' })
await sleep(6000)
console.log('URL:', await ev('location.href'))
console.log('title:', await ev('document.title'))
console.log('bodyText len:', await ev('document.body.innerText.length'))
console.log('bodyText:', JSON.stringify(await ev('document.body.innerText.slice(0,400)')))
console.log('app html len:', await ev("document.querySelector('#app')?.innerHTML.length ?? 'no #app'"))
console.log('scripts:', await ev('[...document.querySelectorAll("script")].map(s=>s.src).join("|")'))
console.log('--- logs ---')
console.log(logs.slice(0, 25).join('\n'))
ws.close(); chrome.kill(); process.exit(0)
