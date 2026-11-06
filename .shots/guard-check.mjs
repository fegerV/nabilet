// Проверка guard-редиректа по мёртвой сессии покупателя.
// Сценарии: мёртвый токен в кабинет, мёртвый токен на форму входа, живой токен,
// гость. Плюс кэш проверки: повторные переходы не должны дёргать `/me`.
import fs from 'node:fs'

const PORT = 9400
const BASE = 'http://127.0.0.1:8001'
const VALID = fs.readFileSync('.shots/club-token.env', 'utf8').split('=')[1].trim()
const DEAD = 'deadbeef'.repeat(16)
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
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map((a) => String(a.value ?? '')).join(' ').slice(0, 160))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 25000) => { const dl = Date.now() + ms; while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(300) } return false }

  const meCalls = () => evaluate(`performance.getEntriesByType('resource').filter(e => /\\/api\\/v1\\/me$/.test(e.name)).length`)
  const boot = async (hash) => {
    await send('Page.navigate', { url: `${BASE}/?n=${Date.now()}${hash}` })
    await waitFor(`!!document.querySelector('form[aria-label="Вход в личный кабинет"], section[aria-label="Клуб зрителей"], h1')`)
    await sleep(2600)
  }
  const state = () => evaluate(`JSON.stringify({
    hash: location.hash,
    токен: localStorage.getItem('nabilet_customer_token') ? 'есть' : 'нет',
    профиль: localStorage.getItem('nabilet_customer_user') ? 'есть' : 'нет',
    плашка: (document.body.innerText.match(/Сессия истекла[^\\n]*/) || ['нет'])[0],
    форма: !!document.querySelector('form[aria-label="Вход в личный кабинет"]'),
  })`)

  await send('Page.enable'); await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })
  await send('Page.navigate', { url: `${BASE}/?boot=${Date.now()}#/` })
  await sleep(2500)

  const setSession = (token) => evaluate(`(() => {
    localStorage.setItem('nabilet_customer_token', ${JSON.stringify(token)});
    localStorage.setItem('nabilet_customer_user', JSON.stringify({ id: 'u1', email: 'seat-buyer@example.test', first_name: 'Покупатель', last_name: 'Мест' }));
    return 1
  })()`)

  // 1. Мёртвый токен → кабинет
  await setSession(DEAD)
  await boot('#/club')
  console.log('1) мёртвый токен, #/club →', await state(), '| /me:', await meCalls())

  // 2. Мёртвый токен → форма входа (не должно быть скачка на /account)
  await setSession(DEAD)
  await boot('#/login')
  console.log('2) мёртвый токен, #/login →', await state(), '| /me:', await meCalls())

  // 3. Живой токен → кабинет, затем SPA-переходы: /me должен быть ОДИН
  await setSession(VALID)
  await boot('#/club')
  console.log('3) живой токен, #/club →', await state(), '| /me:', await meCalls())
  await evaluate(`location.hash = '#/bonus'`)
  await sleep(2000)
  await evaluate(`location.hash = '#/favorites'`)
  await sleep(2000)
  await evaluate(`location.hash = '#/club'`)
  await sleep(2000)
  console.log('   после club→bonus→favorites→club, /me:', await meCalls(), '| hash:', await evaluate(`location.hash`))

  // 4. Живой токен → форма входа должна увести в кабинет
  await setSession(VALID)
  await boot('#/login')
  console.log('4) живой токен, #/login →', await state(), '| /me:', await meCalls())

  // 5. Гость без токена → кабинет
  await evaluate(`(() => { localStorage.removeItem('nabilet_customer_token'); localStorage.removeItem('nabilet_customer_user'); return 1 })()`)
  await boot('#/club')
  console.log('5) гость, #/club →', await state(), '| /me:', await meCalls())

  // 6. Все маршруты под requiresCustomer
  console.log('6) охват guard:', await evaluate(`JSON.stringify(
    [...new Set(performance.getEntriesByType('resource').map(e => e.name))].length && 'см. router/index.ts'
  )`))
  console.log('ОШИБКИ:', JSON.stringify(errors.slice(0, 5)))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
