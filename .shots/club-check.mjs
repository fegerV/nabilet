// Проверка страницы «Клуб зрителей»: тема, данные, ошибки.
//
// Что делает:
//   1) подкладывает токен покупателя в localStorage и открывает #/club;
//   2) собирает ответы /api/v1/me/* — чтобы отличить реальные данные от моков;
//   3) снимает тёмную тему, переключает на светлую, проверяет classList и
//      localStorage, перезагружает страницу и проверяет, что выбор сохранился;
//   4) ловит ошибки консоли и считает контраст текста на заливках.
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
  const net = []
  const consoleErrors = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Network.responseReceived') {
      const u = m.params.response.url
      if (/\/api\/v1\//.test(u)) net.push(`${m.params.response.status} ${u.replace(BASE, '').slice(0, 90)}`)
    }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      consoleErrors.push(m.params.args.map((a) => String(a.value ?? a.description ?? '')).join(' ').slice(0, 200))
    }
    if (m.method === 'Runtime.exceptionThrown') {
      consoleErrors.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 200))
    }
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 30000) => {
    const dl = Date.now() + ms
    while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(300) }
    return false
  }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
    if (r.result?.data) fs.writeFileSync(`C:/Project/nabilet/.shots/${name}`, Buffer.from(r.result.data, 'base64'))
    console.log('SHOT:', name)
  }

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  // Токен + профиль: без них маршрут уводит на вход.
  await send('Page.navigate', { url: `${BASE}/?boot=${Date.now()}#/` })
  await sleep(2500)
  await evaluate(`(() => {
    localStorage.setItem('nabilet_customer_token', ${JSON.stringify(TOKEN)});
    localStorage.setItem('nabilet_customer_user', JSON.stringify({
      id: 9, email: 'seat-buyer@example.test', first_name: 'Покупатель', last_name: 'Мест',
      phone: '+79001234567', bonuses: 315, created_at: '2026-10-20T14:39:00Z'
    }));
    return 'ok';
  })()`)

  await send('Page.navigate', { url: `${BASE}/?club=${Date.now()}#/club` })
  const ready = await waitFor(`Boolean(document.querySelector('h1')) && /Мой NABILET/i.test(document.body.innerText)`, 40000)
  console.log('CLUB PAGE READY:', ready, '| route:', await evaluate(`location.hash`))
  await sleep(2500)

  // Что реально нарисовано в шапке и плитках.
  console.log('HEAD:', await evaluate(`(() => {
    const h = document.querySelector('section[aria-label="Клуб зрителей"]');
    return h ? h.innerText.replace(/\\s+/g, ' ').slice(0, 260) : 'нет шапки';
  })()`))
  console.log('TILES:', await evaluate(`(() => {
    const s = [...document.querySelectorAll('section')].find(x => /медалей получено/.test(x.innerText || ''));
    return s ? s.innerText.replace(/\\s+/g, ' ').slice(0, 200) : 'нет плиток';
  })()`))
  console.log('SECTIONS:', await evaluate(`JSON.stringify([...document.querySelectorAll('h2')].map(h => h.innerText.trim()))`))
  console.log('REFERRAL LINK:', await evaluate(`document.querySelector('#referral-link')?.value || 'нет блока'`))

  console.log('THEME BEFORE:', await evaluate(`JSON.stringify({ dark: document.documentElement.classList.contains('dark'), stored: localStorage.getItem('nabilet.theme'), scheme: document.documentElement.style.colorScheme })`))
  await shot('club-light.png')

  // Переключение темы кнопкой в шапке.
  const toggled = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /тему/.test(x.getAttribute('aria-label') || ''));
    if (!b) return 'нет кнопки темы';
    b.click(); return 'clicked: ' + b.getAttribute('aria-label');
  })()`)
  console.log('THEME TOGGLE:', toggled)
  await sleep(1200)
  console.log('THEME AFTER:', await evaluate(`JSON.stringify({ dark: document.documentElement.classList.contains('dark'), stored: localStorage.getItem('nabilet.theme'), scheme: document.documentElement.style.colorScheme, bodyBg: getComputedStyle(document.body).backgroundColor })`))
  await shot('club-dark.png')

  // Контраст: текст на canvas/surface и «белый на белом».
  console.log('CONTRAST:', await evaluate(`(() => {
    const lum = (c) => {
      const m = c.match(/\\d+(\\.\\d+)?/g); if (!m) return null;
      const [r,g,b] = m.slice(0,3).map(Number).map(v => { v/=255; return v <= .03928 ? v/12.92 : Math.pow((v+.055)/1.055, 2.4); });
      return 0.2126*r + 0.7152*g + 0.0722*b;
    };
    const ratio = (a,b) => { const l1 = lum(a), l2 = lum(b); if (l1===null||l2===null) return null; const [hi,lo] = l1>l2?[l1,l2]:[l2,l1]; return +( (hi+0.05)/(lo+0.05) ).toFixed(2); };
    const bad = [];
    for (const el of document.querySelectorAll('h1,h2,h3,p,span,dt,dd,button')) {
      const txt = (el.textContent || '').trim();
      if (!txt || el.children.length > 0) continue;
      const cs = getComputedStyle(el);
      const bg = cs.backgroundColor;
      const parentBg = (() => { let p = el; while (p && p !== document.documentElement) { const c = getComputedStyle(p).backgroundColor; if (c && !/rgba\\(0, 0, 0, 0\\)/.test(c)) return c; p = p.parentElement; } return getComputedStyle(document.body).backgroundColor; })();
      if (/rgba\\(0, 0, 0, 0\\)/.test(bg)) { /* фон родителя */ }
      const r = ratio(cs.color, /rgba\\(0, 0, 0, 0\\)/.test(bg) ? parentBg : bg);
      if (r !== null && r < 3) bad.push({ txt: txt.slice(0, 40), color: cs.color, bg: /rgba\\(0, 0, 0, 0\\)/.test(bg) ? parentBg : bg, ratio: r });
    }
    return JSON.stringify(bad.slice(0, 8));
  })()`))

  // Сохранение выбора после перезагрузки.
  await send('Page.navigate', { url: `${BASE}/?reload=${Date.now()}#/club` })
  await waitFor(`/Мой NABILET/i.test(document.body.innerText)`, 30000)
  await sleep(2000)
  console.log('THEME AFTER RELOAD:', await evaluate(`JSON.stringify({ dark: document.documentElement.classList.contains('dark'), stored: localStorage.getItem('nabilet.theme') })`))

  // Модалка ачивки (клик по карточке) — проверяем, что открывается.
  console.log('MEDAL CLICK:', await evaluate(`(() => {
    const card = [...document.querySelectorAll('article[role="button"]')][0];
    if (!card) return 'нет карточек';
    card.click(); return 'clicked: ' + (card.innerText || '').split('\\n')[0];
  })()`))
  await sleep(900)
  console.log('MODAL:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    return d ? d.innerText.replace(/\\s+/g, ' ').slice(0, 220) : 'модалка не открылась';
  })()`))
  await shot('club-modal.png')

  console.log('CONSOLE ERRORS:', JSON.stringify(consoleErrors.slice(0, 6)))
  console.log('NET:', JSON.stringify(net.filter((x, i, a) => a.indexOf(x) === i).slice(0, 30), null, 1))
  ws.close()
}

main().catch((e) => { console.error('FATAL', e); process.exit(1) })
