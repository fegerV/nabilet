// Проверка интерактива клуба: кнопки, копирование ссылки, переходы.
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8001'
const TOKEN = process.env.CLUB_TOKEN || ''
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
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map((a) => String(a.value ?? '')).join(' ').slice(0, 160))
    if (m.method === 'Runtime.exceptionThrown') errors.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 160))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 25000) => { const dl = Date.now() + ms; while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(300) } return false }

  await send('Page.enable'); await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })
  await send('Page.navigate', { url: `${BASE}/?x=${Date.now()}#/` })
  await sleep(2000)
  await evaluate(`(() => { localStorage.setItem('nabilet_customer_token', ${JSON.stringify(TOKEN)}); localStorage.setItem('nabilet_customer_user', JSON.stringify({ id: 9, email: 'seat-buyer@example.test', first_name: 'Покупатель', last_name: 'Мест' })); return 1 })()`)
  await send('Page.navigate', { url: `${BASE}/?y=${Date.now()}#/club` })
  await waitFor(`/Мой NABILET/i.test(document.body.innerText)`)
  await sleep(1800)

  // Копирование реферальной ссылки.
  console.log('COPY:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Скопировать/.test(x.innerText));
    if (!b) return 'нет кнопки';
    b.click(); return 'clicked';
  })()`))
  await sleep(1200)
  console.log('TOAST:', await evaluate(`(() => {
    const t = [...document.querySelectorAll('[role="status"],[role="alert"]')].map(e => e.innerText.replace(/\\s+/g,' ').trim()).filter(Boolean);
    return JSON.stringify(t.slice(-3));
  })()`))
  console.log('COPY LABEL NOW:', await evaluate(`[...document.querySelectorAll('button')].map(b => b.innerText.trim()).filter(t => /Скопирова/.test(t)).join(' | ')`))

  // Модалка медали → «История бонусов».
  await evaluate(`(() => { const c = document.querySelector('article[role="button"]'); if (c) c.click(); return 1 })()`)
  await sleep(800)
  console.log('MODAL BUTTONS:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    return d ? JSON.stringify([...d.querySelectorAll('button,a')].map(b => b.innerText.trim()).filter(Boolean)) : 'нет модалки';
  })()`))
  console.log('MODAL → bonus:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    const b = d && [...d.querySelectorAll('button,a')].find(x => /История бонусов/.test(x.innerText));
    if (!b) return 'нет кнопки';
    b.click(); return 'clicked';
  })()`))
  await sleep(1500)
  console.log('ROUTE AFTER BONUS:', await evaluate(`location.hash`))
  console.log('BONUS PAGE:', await evaluate(`document.body.innerText.replace(/\\s+/g,' ').slice(0, 140)`))

  // Назад в клуб, кнопка «Найти событие».
  await send('Page.navigate', { url: `${BASE}/?z=${Date.now()}#/club` })
  await waitFor(`/Мой NABILET/i.test(document.body.innerText)`)
  await sleep(1200)
  console.log('CTA CATALOG:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Найти событие/.test(x.innerText));
    if (!b) return 'нет кнопки';
    b.click(); return 'clicked';
  })()`))
  await sleep(1500)
  console.log('ROUTE AFTER CATALOG:', await evaluate(`location.hash`))

  await send('Page.navigate', { url: `${BASE}/?w=${Date.now()}#/club` })
  await waitFor(`/Мой NABILET/i.test(document.body.innerText)`)
  await sleep(1200)
  console.log('CTA RAFFLES:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Мои розыгрыши/.test(x.innerText));
    if (!b) return 'нет кнопки';
    b.click(); return 'clicked';
  })()`))
  await sleep(1500)
  console.log('ROUTE AFTER RAFFLES:', await evaluate(`location.hash`))
  console.log('RAFFLES PAGE:', await evaluate(`document.body.innerText.replace(/\\s+/g,' ').slice(0, 160)`))

  console.log('ERRORS:', JSON.stringify(errors.slice(0, 5)))
  ws.close()
}

main().catch((e) => { console.error('FATAL', e); process.exit(1) })
