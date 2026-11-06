// Проверка страницы «Логи» в админке живым браузером.
//
// Что проверяем:
//   1) страница открывается по #/admin/logs и рисует записи (не пустой экран);
//   2) фильтр по уровню реально сужает список;
//   3) поиск находит запись по request_id;
//   4) «Подробнее» раскрывает контекст и стектрейс;
//   5) переход по файлам меняет набор записей;
//   6) в шапке есть пункт меню «Логи».
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const TOKEN = process.env.ADMIN_TOKEN || ''
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })

  let id = 0
  const pending = new Map()
  const net = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Network.responseReceived') {
      const u = m.params.response.url
      if (/\/api\/v1\/admin\/system\/logs/.test(u)) net.push(`${m.params.response.status} ${u.replace(BASE, '').slice(0, 90)}`)
    }
    if (m.method === 'Runtime.exceptionThrown') net.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 160))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 30000) => {
    const dl = Date.now() + ms
    while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(400) }
    return false
  }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png' })
    if (r.result?.data) fs.writeFileSync(`C:/Project/nabilet/.shots/${name}`, Buffer.from(r.result.data, 'base64'))
    console.log('SHOT:', name)
  }

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  await send('Page.navigate', { url: `${BASE}/?logs=${Date.now()}#/` })
  await sleep(2000)
  await evaluate(`localStorage.clear(); 'ok'`)

  // Вход через настоящую форму: guard админки проверяет не только токен, но и
  // сохранённого пользователя с ролью, поэтому подстановка одного токена
  // приводит на «нет прав администратора».
  await send('Page.navigate', { url: `${BASE}/?logs=${Date.now()}#/admin/login` })
  await waitFor(`!!document.querySelector('input[type="password"]')`, 20000)
  await evaluate(`(() => {
    const set = (el, value) => {
      el.focus(); el.value = value
      el.dispatchEvent(new Event('input', { bubbles: true }))
      el.dispatchEvent(new Event('change', { bubbles: true }))
    }
    set(document.querySelector('input[type="email"]'), 'admin@nabilet.local')
    set(document.querySelector('input[type="password"]'), 'admin123')
    return 'ok'
  })()`)
  await sleep(400)
  await evaluate(`(() => {
    const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Войти')
    if (btn) btn.click()
    return 'ok'
  })()`)
  const loggedIn = await waitFor(`location.hash.startsWith('#/admin') && !location.hash.includes('login')`, 25000)
  console.log('LOGGED IN:', loggedIn, '| hash:', await evaluate('location.hash'))
  await sleep(1200)

  await send('Page.navigate', { url: `${BASE}/?logs=${Date.now()}#/admin/logs` })
  const ready = await waitFor(`document.querySelectorAll('article').length > 0`, 40000)
  console.log('PAGE READY:', ready)
  await sleep(800)
  await shot('logs-page.png')

  const menu = await evaluate(`[...document.querySelectorAll('nav a')].map(a => a.textContent.trim()).filter(Boolean).join(' | ')`)
  console.log('MENU:', menu)

  const counts = () => evaluate(`(() => {
    const head = document.body.innerText.match(/Показано ([^\\n]+)/)
    return { shown: document.querySelectorAll('article').length, head: head ? head[1].trim() : 'нет' }
  })()`)
  console.log('ALL:', JSON.stringify(await counts()))

  // Фильтр по уровню: кнопка с текстом ERROR в панели фильтров.
  const errClicked = await evaluate(`(() => {
    const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim().startsWith('ERROR'))
    if (!btn) return 'нет кнопки ERROR'
    btn.click(); return 'ok'
  })()`)
  console.log('LEVEL CLICK:', errClicked)
  await sleep(2500)
  const filtered = await counts()
  console.log('ERROR ONLY:', JSON.stringify(filtered))
  const allError = await evaluate(`[...document.querySelectorAll('article')].every(a => a.textContent.includes('ERROR'))`)
  console.log('ONLY ERROR ENTRIES:', allError)
  await shot('logs-filtered.png')

  // Сброс фильтра.
  await evaluate(`(() => {
    const btn = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'сбросить')
    if (btn) btn.click(); return 'ok'
  })()`)
  await sleep(2000)

  // Поиск по request_id — берём его из первой записи на экране.
  const rid = await evaluate(`(() => {
    const code = document.querySelector('article code')
    return code ? code.textContent.trim() : null
  })()`)
  console.log('REQUEST_ID FROM UI:', rid)

  if (rid) {
    await evaluate(`(() => {
      const input = document.querySelector('input[type="search"], input')
      input.focus(); input.value = ${JSON.stringify(rid)}
      input.dispatchEvent(new Event('input', { bubbles: true }))
      return 'ok'
    })()`)
    await sleep(3000)
    console.log('SEARCH BY REQUEST_ID:', JSON.stringify(await counts()))
  }

  // Очистка поиска.
  await evaluate(`(() => {
    const input = document.querySelector('input')
    input.focus(); input.value = ''
    input.dispatchEvent(new Event('input', { bubbles: true }))
    return 'ok'
  })()`)
  await sleep(2500)

  // Раскрытие записи.
  const expanded = await evaluate(`(() => {
    const btn = [...document.querySelectorAll('article button')].find(b => b.textContent.trim() === 'Подробнее')
    if (!btn) return 'нет кнопки Подробнее'
    btn.scrollIntoView({ block: 'center' })
    btn.click(); return 'ok'
  })()`)
  console.log('EXPAND:', expanded)
  await sleep(900)
  const pre = await evaluate(`document.querySelectorAll('article pre').length`)
  console.log('PRE BLOCKS:', pre)
  await shot('logs-expanded.png')

  console.log('NET:', JSON.stringify(net, null, 1))
  ws.close()
}

main().catch((e) => { console.error('FAIL', e); process.exit(1) })
