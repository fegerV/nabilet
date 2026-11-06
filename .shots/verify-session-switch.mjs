// Проверка смены сеанса на странице события:
//  1) выбрали место в сеансе А;
//  2) переключились на сеанс Б;
//  3) холд сеанса А снят на сервере (DELETE /cart/items), сводка пуста,
//     места сеанса Б доступны для выбора.
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
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
      if (/\/api\/v1\/(cart|inventory)/.test(u)) net.push(`${m.params.response.status} ${u.replace(BASE, '').slice(0, 60)}`)
    }
    if (m.method === 'Runtime.exceptionThrown') net.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 160))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 240)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 30000) => {
    const dl = Date.now() + ms
    while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(400) }
    return false
  }
  const clickAt = async (x, y) => {
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
      await send('Input.dispatchMouseEvent', { type, x, y, button: type === 'mouseMoved' ? 'none' : 'left', clickCount: 1 })
      await sleep(120)
    }
  }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png' })
    if (r.result?.data) fs.writeFileSync(`C:/Project/nabilet/.shots/${name}`, Buffer.from(r.result.data, 'base64'))
  }

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  await send('Page.navigate', { url: `${BASE}/?wipe=${Date.now()}#/` })
  await sleep(2000)
  await evaluate('localStorage.clear(); sessionStorage.clear(); "ok"')

  // Каталог → ищем событие с ДВУМЯ сеансами: у него переключатель виден.
  await send('Page.navigate', { url: `${BASE}/?sw=${Date.now()}#/` })
  const multi = await evaluate(`(async () => {
    const r = await fetch('/api/v1/events?status=published&per_page=100');
    const j = await r.json();
    const events = Array.isArray(j.data) ? j.data : (j.data && j.data.data) || [];
    const found = events.find(e => Array.isArray(e.sessions) && e.sessions.length > 1);
    return found ? found.slug : null;
  })()`)
  console.log('MULTI-SESSION EVENT:', multi)
  if (!multi) { console.log('нет события с двумя сеансами — проверка прервана'); ws.close(); return }

  await send('Page.navigate', { url: `${BASE}/?sw2=${Date.now()}#/event/${multi}` })
  console.log('MAP:', await waitFor(`document.querySelectorAll('circle.seat-hit').length > 50`, 45000))
  await sleep(2000)

  const sessions = await evaluate(`JSON.stringify([...document.querySelectorAll('[data-sessions] button[aria-pressed]')].map(b => ({ t: b.innerText.replace(/\\s+/g,' ').trim(), on: b.getAttribute('aria-pressed') })))`)
  console.log('SESSION BUTTONS:', sessions)
  const btnCount = JSON.parse(sessions).length

  const seat = await evaluate(`(() => {
    const live = [...document.querySelectorAll('circle.seat-dot--live')];
    const el = live[Math.floor(live.length * 0.6)];
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return JSON.stringify({ x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2), label: el.getAttribute('aria-label') });
  })()`)
  const s = JSON.parse(seat)
  await clickAt(s.x, s.y)
  await sleep(3000)
  console.log('SELECTED:', await evaluate(`(() => {
    const a = document.querySelector('aside');
    return (a ? a.innerText : '').replace(/\\s+/g,' ').slice(0, 120);
  })()`))

  // Переключаем сеанс.
  console.log('SWITCH CLICK:', await evaluate(`(() => {
    const btns = [...document.querySelectorAll('[data-sessions] button[aria-pressed]')];
    const next = btns.find(b => b.getAttribute('aria-pressed') !== 'true' && !b.disabled);
    if (!next) return 'no other enabled session';
    next.click(); return 'clicked: ' + next.innerText.replace(/\\s+/g,' ').trim();
  })()`))
  await sleep(5000)
  console.log('AFTER SWITCH SUMMARY:', await evaluate(`(() => {
    const a = document.querySelector('aside');
    return (a ? a.innerText : '').replace(/\\s+/g,' ').slice(0, 160);
  })()`))
  console.log('NEW MAP READY:', await waitFor(`document.querySelectorAll('circle.seat-hit').length > 50`, 30000))
  await shot('v-session-switched.png')

  // Место в новом сеансе снова выбирается.
  const seat2 = await evaluate(`(() => {
    const live = [...document.querySelectorAll('circle.seat-dot--live')];
    const el = live[Math.floor(live.length * 0.4)];
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return JSON.stringify({ x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2), label: el.getAttribute('aria-label') });
  })()`)
  const s2 = JSON.parse(seat2)
  await clickAt(s2.x, s2.y)
  await sleep(3000)
  console.log('RESELECTED:', await evaluate(`(() => {
    const a = document.querySelector('aside');
    return (a ? a.innerText : '').replace(/\\s+/g,' ').slice(0, 160);
  })()`))

  console.log('SESSIONS VISIBLE:', btnCount)
  console.log('NET:', JSON.stringify(net, null, 1))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
