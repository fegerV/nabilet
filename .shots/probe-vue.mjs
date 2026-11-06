/** Диагностика: как добраться до setupState страницы редактора залов. */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { readFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9347
const BASE = 'http://127.0.0.1:8099'
const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const loginJson = await login.json()
const TOKEN = loginJson.token ?? loginJson?.data?.token

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${CDP_PORT}`,
  '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-rt-probe',
  '--window-size=1440,900', 'about:blank',
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 60; i += 1) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page')
      if (page) return page
    } catch { /* ждём */ }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}
const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map(); let seq = 0
function send(m, p = {}) { const id = ++seq; ws.send(JSON.stringify({ id, method: m, params: p })); return new Promise((r) => pending.set(id, r)) }
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id) } })
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable'); await send('Page.enable')

async function ev(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
  if (r?.exceptionDetails) return 'EXC: ' + (r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails)).slice(0, 300)
  return r?.result?.value
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(4000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/halls/${lab.banquetHall}/editor'`)
await sleep(400)
await send('Page.reload')
await sleep(7000)

console.log('app:', await ev(`!!document.querySelector('#app')?.__vue_app__`))
console.log('instance:', await ev(`!!document.querySelector('#app')?.__vue_app__?._instance`))
console.log('app keys:', await ev(`JSON.stringify(Object.keys(document.querySelector('#app').__vue_app__))`))
console.log('container vnode:', await ev(`(() => { const a = document.querySelector('#app').__vue_app__
  const c = a._container || a._context?.app?._container
  return JSON.stringify({ hasContainer: !!c, hasVNode: !!(c && c._vnode), hasComp: !!(c && c._vnode && c._vnode.component),
    rootName: c && c._vnode && c._vnode.component && (c._vnode.component.type.__name || c._vnode.component.type.name) }) })()`))
console.log('all vue roots:', await ev(`JSON.stringify([...document.querySelectorAll('*')].filter((e) => e.__vue_app__).map((e) => e.tagName + '#' + e.id))`))
console.log(await ev(`(() => {
  const app = document.querySelector('#app').__vue_app__
  const names = []
  const seen = new Set()
  function walkInst(inst, d) {
    if (!inst || d > 40 || seen.has(inst)) return
    seen.add(inst)
    const t = inst.type
    names.push((t && (t.__name || t.name)) || (typeof t === 'symbol' ? 'sym' : String(t).slice(0, 40)))
    walkV(inst.subTree, d + 1)
  }
  function walkV(v, d) {
    if (!v || typeof v !== 'object' || d > 40) return
    if (v.component) walkInst(v.component, d)
    if (Array.isArray(v.children)) for (const c of v.children) walkV(c, d + 1)
  }
  walkInst(app._container._vnode.component, 0)
  return JSON.stringify(names)
})()`))
console.log('by setupState key:', await ev(`(() => {
  const app = document.querySelector('#app').__vue_app__
  const seen = new Set(); let found = null; let visited = 0
  function walkInst(inst, d) { if (!inst || d > 60 || seen.has(inst) || found) return; seen.add(inst); visited++
    if (inst.setupState && typeof inst.setupState.buildSchemaPayload === 'function') { found = inst; return }
    walkV(inst.subTree, d + 1) }
  function walkV(v, d) { if (!v || typeof v !== 'object' || d > 60 || found) return
    if (v.component) walkInst(v.component, d)
    if (Array.isArray(v.children)) for (const c of v.children) walkV(c, d + 1) }
  walkInst(app._container._vnode.component, 0)
  if (!found) return 'NOT FOUND (visited ' + visited + ')'
  return JSON.stringify({ visited, keys: Object.keys(found.setupState).length,
    hasExport: typeof found.setupState.exportSchema, hasValidate: typeof found.setupState.validateSchema })
})()`))
console.log('setupState keys:', await ev(`(() => {
  const app = document.querySelector('#app').__vue_app__
  const seen = new Set(); let found = null
  function walkInst(inst, d) { if (!inst || d > 40 || seen.has(inst) || found) return; seen.add(inst)
    const t = inst.type; const nm = t && (t.__name || t.name)
    if (nm === 'HallEditorPage') { found = inst; return }
    walkV(inst.subTree, d + 1) }
  function walkV(v, d) { if (!v || typeof v !== 'object' || d > 40 || found) return
    if (v.component) walkInst(v.component, d)
    if (Array.isArray(v.children)) for (const c of v.children) walkV(c, d + 1) }
  walkInst(app._container._vnode.component, 0)
  if (!found) return 'NOT FOUND'
  return JSON.stringify(Object.keys(found.setupState).slice(0, 60))
})()`))
ws.close(); chrome.kill(); process.exit(0)
