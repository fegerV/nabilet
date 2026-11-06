// Полная проверка покупки В ОДИН ЭКРАН (после редизайна страницы события).
//
// Что проверяем:
//   1) страница события сама рисует схему зала — отдельного экрана выбора нет;
//   2) старый адрес `/event/<slug>/seats?session=N` перебрасывает на страницу
//      события и открывает тот же сеанс;
//   3) подсказка при наведении (ряд, место, цена);
//   4) клик по месту = ВЫБОР: сводка появляется на этой же странице, адрес не
//      меняется, в корзину ничего не «уходит» вторым экраном;
//   5) «Далее» открывает форму контактов В ТОМ ЖЕ фрейме (без навигации);
//   6) оплата подтверждается на месте и показывает результат.
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
      if (/\/api\/v1\/(cart|orders|payments|checkout|tickets|inventory)/.test(u)) net.push(`${m.params.response.status} ${u.replace(BASE, '').slice(0, 70)}`)
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
  const clickAt = async (x, y) => {
    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
      await send('Input.dispatchMouseEvent', { type, x, y, button: type === 'mouseMoved' ? 'none' : 'left', clickCount: 1 })
      await sleep(120)
    }
  }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png' })
    if (r.result?.data) fs.writeFileSync(`C:/Project/nabilet/.shots/${name}`, Buffer.from(r.result.data, 'base64'))
    console.log('SHOT:', name)
  }
  const route = () => evaluate(`(() => {
    const app = document.querySelector('#app')
    const r = app && app.__vue_app__ ? app.__vue_app__.config.globalProperties.$route : null
    return r ? r.fullPath : 'no-route'
  })()`)

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Network.setCacheDisabled', { cacheDisabled: true })
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  // 0. Чистый старт: без старых холдов и корзин в localStorage.
  await send('Page.navigate', { url: `${BASE}/?wipe=${Date.now()}#/` })
  await sleep(2500)
  await evaluate('localStorage.clear(); sessionStorage.clear(); "ok"')

  // 1. Старая ссылка на отдельный экран выбора мест.
  await send('Page.navigate', { url: `${BASE}/?old=${Date.now()}#/event/${SLUG}/seats?session=23` })
  const redirected = await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`, 45000)
  console.log('OLD URL → SEATS ON EVENT PAGE:', redirected, '| route:', await route())

  // 2. Страница события: афиша слева, схема справа.
  await send('Page.navigate', { url: `${BASE}/?shot=${Date.now()}#/event/${SLUG}` })
  const ready = await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`, 45000)
  console.log('HALL MAP READY:', ready)
  await sleep(2500)
  await shot('v-event.png')

  const layout = await evaluate(`(() => {
    const poster = document.querySelector('img[alt^="Афиша"]')
    const map = document.querySelector('svg[aria-label="Схема зала"]')
    if (!poster || !map) return 'missing ' + Boolean(poster) + '/' + Boolean(map)
    const p = poster.getBoundingClientRect(), m = map.getBoundingClientRect()
    return JSON.stringify({ posterLeft: Math.round(p.left), posterW: Math.round(p.width), mapLeft: Math.round(m.left), mapW: Math.round(m.width), posterAbove: p.top < m.top })
  })()`)
  console.log('LAYOUT:', layout)

  // 3. Подсказка при наведении.
  //    Карта теперь во всю ширину и высотой ~660 px, поэтому место из середины
  //    зала на экране 1000 px оказывается НИЖЕ сгиба — без прокрутки клик по
  //    его координатам уходил бы мимо (в пустоту под окном). Живой покупатель
  //    прокручивает так же.
  //
  //    Прокрутка и замер — ДВУМЯ вызовами: в приложении включён
  //    `scroll-behavior: smooth`, и `scrollIntoView` внутри того же вызова
  //    возвращает координаты ДО анимации (проверено: rect.y = 1108 при
  //    scrollY = 0, а через мгновение scrollY = 608). Замер после паузы.
  const SEAT_PICK = `[...document.querySelectorAll('circle.seat-dot--live')][Math.floor(document.querySelectorAll('circle.seat-dot--live').length * 0.55)]`
  await evaluate(`(() => { const el = ${SEAT_PICK}; if (el) el.scrollIntoView({ block: 'center', inline: 'center' }); return true })()`)
  await sleep(1200)
  const seat = await evaluate(`(() => {
    const el = ${SEAT_PICK};
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return JSON.stringify({ x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2), label: el.getAttribute('aria-label') });
  })()`)
  if (!seat) { console.log('NO FREE SEAT'); ws.close(); return }
  const s = JSON.parse(seat)
  console.log('TARGET SEAT:', s.label)
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: s.x, y: s.y })
  await sleep(1200)
  console.log('TOOLTIP:', await evaluate(`(() => {
    const t = [...document.querySelectorAll('div')].find(d => d.getAttribute('role') === 'status' && /ряд/i.test(d.innerText || ''));
    return t ? t.innerText.replace(/\\s+/g, ' | ') : null;
  })()`))

  // 4. Клик = выбор. Адрес не должен измениться.
  const beforeRoute = await route()
  await clickAt(s.x, s.y)
  await sleep(3500)
  console.log('ROUTE UNCHANGED:', beforeRoute === (await route()), beforeRoute)
  console.log('PANEL:', await evaluate(`(() => {
    // Итог выбора теперь живёт НИЖНЕЙ ПОЛОСОЙ В РАМКЕ СХЕМЫ (data-summary),
    // а не отдельной колонкой aside — колонка отнимала у зала треть ширины.
    const dock = document.querySelector('[data-summary]')
    return dock ? dock.innerText.replace(/\\s+/g, ' ').slice(0, 220) : 'no summary dock'
  })()`))
  await shot('v-seat-selected.png')

  // 5. «Далее» → форма контактов в том же фрейме.
  const toContacts = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => x.innerText.trim() === 'Далее');
    if (!b) return 'no button';
    if (b.disabled) return 'disabled';
    b.click(); return 'clicked';
  })()`)
  console.log('DALEE BTN:', toContacts)
  const formOpen = await waitFor(`Boolean(document.querySelector('input[autocomplete="name"]'))`, 25000)
  console.log('CONTACT FORM INLINE:', formOpen, '| route:', await route())
  await sleep(800)
  await shot('v-contacts.png')

  // 6. Контакты и оплата.
  const filled = await evaluate(`(() => {
    // Заполняем ПО ПОДПИСИ, а не по порядку: на экране два e-mail (вход в
    // кабинет и адрес для билета), и «первый email в документе» — это как раз
    // не тот, что нужен заказу.
    const set = (el, v) => { if (!el) return false; el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true };
    const byLabel = (text) => {
      // ТОЧНОЕ совпадение подписи: на экране есть и «E-mail кабинета» (вход), и
      // «E-mail» (адрес для билета). Поиск по началу строки попадал в первое.
      const norm = (t) => (t || '').split('*').join('').split(' ').filter(Boolean).join(' ');
      const label = [...document.querySelectorAll('label')].find(l => norm(l.textContent) === text);
      if (!label || !label.control) return null;
      return label.control;
    };
    const out = [];
    out.push(set(byLabel('Имя и фамилия'), 'Покупатель Мест'));
    out.push(set(byLabel('Телефон'), '+79001234567'));
    out.push(set(byLabel('E-mail'), 'one-screen@example.test'));
    out.push(set(byLabel('E-mail ещё раз'), 'one-screen@example.test'));
    const checkboxes = [...document.querySelectorAll('input[type="checkbox"]')];
    checkboxes.forEach(c => { if (!c.checked) c.click() });
    return JSON.stringify({ set: out, checkboxes: checkboxes.length });
  })()`)
  console.log('FILLED:', filled)
  await sleep(1200)

  const pay = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /^Оплатить/.test(x.innerText.trim()));
    if (!b) return 'no pay button';
    if (b.disabled) return 'pay disabled';
    b.click(); return 'pay clicked: ' + b.innerText.trim();
  })()`)
  console.log('PAY:', pay)
  const paid = await waitFor(`document.body.innerText.includes('Оплата прошла')`, 60000)
  console.log('PAID:', paid)
  if (!paid) {
    console.log('ASIDE:', await evaluate(`(document.querySelector('aside')?.innerText || 'no aside').replace(/\\s+/g, ' ').slice(0, 700)`))
    console.log('TOASTS:', await evaluate(`(() => {
      const t = [...document.querySelectorAll('div')].filter(d => (d.getAttribute('role') === 'status' || d.getAttribute('role') === 'alert') && (d.innerText || '').length > 0 && (d.innerText || '').length < 200).map(d => d.innerText.replace(/\\s+/g, ' '));
      return JSON.stringify(t.slice(-6));
    })()`))
  }
  await sleep(1500)
  console.log('RESULT:', await evaluate(`document.body.innerText.replace(/\\s+/g, ' ').slice(0, 260)`))
  console.log('ROUTE AFTER PAY:', await route())
  await shot('v-purchase-ok.png')

  // 7. Билет действительно выпущен.
  const tickets = await evaluate(`fetch('/api/v1/me/orders').then(r => r.status).catch(e => String(e))`)
  console.log('ORDERS STATUS (ожидаем 401 — гость без аккаунта):', tickets)

  console.log('NET:', JSON.stringify(net, null, 1))
  ws.close()
}
main().catch((e) => { console.error('FATAL', e); process.exit(1) })
