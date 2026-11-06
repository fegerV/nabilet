// Точечная диагностика: почему клик по «Оплатить» не уходит в сеть.
// Логируем КАЖДЫЙ вызов fetch/XHR и состояние кнопки после клика.
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
  const calls = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Runtime.consoleAPICalled') {
      const text = (m.params.args || []).map((a) => a.value ?? a.description ?? '').join(' ')
      if (text.startsWith('FETCH') || text.startsWith('BODY')) calls.push(text)
    }
    if (m.method === 'Network.responseReceived') {
      const u = m.params.response.url
      if (/\/api\/v1\/(cart|payments)/.test(u)) calls.push('RESP ' + m.params.response.status + ' ' + u.replace(BASE, '').slice(0, 60))
    }
    if (m.method === 'Network.loadingFailed') calls.push('FAILED ' + String(m.params.errorText))
    if (m.method === 'Runtime.exceptionThrown') calls.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 200))
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
  const clickAt = async (x, y) => {
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
      await send('Input.dispatchMouseEvent', { type, x, y, button: type === 'mouseMoved' ? 'none' : 'left', clickCount: 1 })
      await sleep(120)
    }
  }

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })
  // Логгер сетевых вызовов — до любой навигации на страницу.
  await send('Page.addScriptToEvaluateOnNewDocument', {
    source: `(() => {
      const orig = window.fetch;
      window.fetch = function (...args) {
        const url = String(args[0]);
        const method = String((args[1] && args[1].method) || 'GET');
        console.log('FETCH ' + url + ' ' + method);
        if (url.includes('/api/v1/payments') && !url.includes('demo-pay')) {
          return orig.apply(this, args).then((r) => {
            r.clone().text().then((t) => console.log('BODY ' + url.slice(-24) + ' ' + t.slice(0, 500)));
            return r;
          });
        }
        return orig.apply(this, args);
      };
      const open = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function (m, u, ...rest) {
        console.log('XHR ' + m + ' ' + String(u));
        return open.call(this, m, u, ...rest);
      };
    })()`,
  })

  await send('Page.navigate', { url: `${BASE}/?dbg=${Date.now()}#/` })
  await sleep(2000)
  await evaluate('localStorage.clear(); sessionStorage.clear(); "ok"')
  await send('Page.navigate', { url: `${BASE}/?dbg2=${Date.now()}#/event/${SLUG}` })
  console.log('MAP:', await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`, 45000))
  await sleep(2000)

  const seat = await evaluate(`(() => {
    const live = [...document.querySelectorAll('circle.seat-dot--live')];
    const el = live[Math.floor(live.length * 0.55)];
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return JSON.stringify({ x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) });
  })()`)
  const s = JSON.parse(seat)
  await clickAt(s.x, s.y)
  await sleep(3000)

  await evaluate(`(() => { const b = [...document.querySelectorAll('button')].find(x => x.innerText.trim() === 'Далее'); if (b) b.click(); return Boolean(b) })()`)
  await waitFor(`Boolean(document.querySelector('input[autocomplete="name"]'))`, 20000)
  await evaluate(`(() => {
    const set = (el, v) => { if (!el) return false; el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true };
    const byLabel = (text) => {
      const norm = (t) => (t || '').split('*').join('').split(' ').filter(Boolean).join(' ');
      const label = [...document.querySelectorAll('label')].find(l => norm(l.textContent) === text);
      return label && label.control ? label.control : null;
    };
    set(byLabel('Имя и фамилия'), 'Покупатель Мест');
    set(byLabel('Телефон'), '+79001234567');
    set(byLabel('E-mail'), 'dbg@example.test');
    set(byLabel('E-mail ещё раз'), 'dbg@example.test');
    [...document.querySelectorAll('input[type="checkbox"]')].forEach(c => { if (!c.checked) c.click() });
    return 'ok';
  })()`)
  await sleep(1000)

  console.log('BUTTON BEFORE:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /^Оплатить/.test(x.innerText.trim()));
    return b ? b.outerHTML.slice(0, 300) : 'no button';
  })()`))

  console.log('CLICK:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /^Оплатить/.test(x.innerText.trim()));
    if (!b) return 'no button';
    if (b.disabled) return 'disabled';
    b.click();
    return 'clicked';
  })()`))
  await sleep(6000)
  console.log('BUTTON AFTER:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /^Оплатить/.test(x.innerText.trim()));
    return b ? b.outerHTML.slice(0, 300) : 'no button';
  })()`))
  console.log('BODY SNIPPET:', await evaluate(`document.body.innerText.replace(/\\s+/g,' ').includes('Оплата прошла')`))
  console.log('ASIDE AFTER PAY:', await evaluate(`(document.querySelector('aside')?.innerText || 'no aside').replace(/\\s+/g,' ').slice(0, 400)`))
  console.log('CALLS:', JSON.stringify(calls, null, 1))
  const shot = await send('Page.captureScreenshot', { format: 'png' })
  if (shot.result?.data) fs.writeFileSync('C:/Project/nabilet/.shots/dbg-pay.png', Buffer.from(shot.result.data, 'base64'))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
