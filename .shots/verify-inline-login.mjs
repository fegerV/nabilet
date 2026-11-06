// Проверка ВХОДА В КАБИНЕТ ВНУТРИ виджета покупки (после рефактора profileEmail).
//
// Что проверяем:
//   1) форма контактов открывается в том же фрейме;
//   2) блок «Войти» доступен на том же экране;
//   3) вход с верным паролем показывает e-mail кабинета вместо формы;
//   4) e-mail заказа подставляется из кабинета и попадает в оплату;
//   5) оплата проходит без ошибок.
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8000'
const SLUG = 'simfoniya-vedmak-pri-svechakh-cagmo'
const MAIL = 'widget-login@example.test'
const PASS = 'Verysecret1!'
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
      if (/\/api\/v1\//.test(u)) net.push(`${m.params.response.status} ${u.replace(BASE, '').slice(0, 60)}`)
    }
    if (m.method === 'Runtime.exceptionThrown') net.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 200))
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 400)
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
  // Текст метки поля — ровно совпадает с меткой формы (без совпадения по префиксу,
  // иначе в форме входа и форме заказа получаются одинаковые «E-mail»).
  const fillField = async (label, value) => evaluate(`(async () => {
    const norm = (s) => s.split(' ').filter(Boolean).join(' ').replace('*', '').trim()
    const els = [...document.querySelectorAll('input')]
    const el = els.find((i) => norm(i.closest('label')?.innerText || i.labels?.[0]?.innerText || '') === '${label}')
    if (!el) return 'no-field:' + els.map((i) => norm(i.closest('label')?.innerText || '')).join('|')
    const proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')
    proto.set.call(el, ${JSON.stringify(value)})
    el.dispatchEvent(new Event('input', { bubbles: true }))
    await new Promise((r) => setTimeout(r, 250))
    return el.value
  })()`)
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

  await send('Page.navigate', { url: `${BASE}/?login=${Date.now()}#/event/${SLUG}` })
  console.log('MAP READY:', await waitFor(`document.querySelectorAll('circle.seat-hit').length > 100`, 45000))
  await sleep(2500)

  // 1. Клик по месту = выбор. Берём доступное место (класс `--live`), как в
  //    основном сценарии: у проданных своя разметка.
  const seat = await evaluate(`(() => {
    const live = [...document.querySelectorAll('circle.seat-dot--live')]
    const el = live[Math.floor(live.length * 0.55)]
    if (!el) return null
    const r = el.getBoundingClientRect()
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
  })()`)
  if (!seat) { console.log('NO SEAT'); return }
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: seat.x, y: seat.y })
  await sleep(800)
  await clickAt(seat.x, seat.y)
  await sleep(2000)

  const dalee = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === 'Далее')
    if (!b) return null
    const r = b.getBoundingClientRect()
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
  })()`)
  if (!dalee) { console.log('NO DALEE'); return }
  console.log('SELECTED BEFORE DALEE:', await evaluate(`(() => {
    const aside = document.querySelector('aside')
    return aside ? aside.innerText.replace(/\\s+/g, ' ').slice(0, 120) : 'no aside'
  })()`))
  await clickAt(dalee.x, dalee.y)
  await sleep(1500)
  await shot('v-dbg-after-dalee.png')
  console.log('INPUTS:', await evaluate(`document.querySelectorAll('input').length`))

  const loginField = await evaluate(`(() => {
    const els = [...document.querySelectorAll('input')]
    return els.map((i) => (i.closest('label')?.innerText || i.labels?.[0]?.innerText || '').replace('*', '').split(' ').filter(Boolean).join(' ')).join('|')
  })()`)
  console.log('FIELDS ON CONTACT SCREEN:', loginField)
  console.log('ROUTE:', await route())

  // 2. Заполняем контакты.
  // Метки берём из самой формы: имя и подтверждение e-mail отличаются от
  // страховочных вариантов, поэтому сверяемся с тем, что реально отрисовано.
  console.log('NAME:', await fillField('Имя и фамилия', 'Проверка'))
  console.log('PHONE:', await fillField('Телефон', '+79990000011'))
  console.log('EMAIL:', await fillField('E-mail', MAIL))
  console.log('EMAIL CONFIRM:', await fillField('E-mail ещё раз', MAIL))
  await evaluate(`(() => {
    const boxes = [...document.querySelectorAll('input[type=checkbox]')]
    boxes.forEach((b) => { if (!b.checked) b.click() })
    return boxes.length
  })()`)
  await sleep(400)

  // 3. Вход в кабинет прямо в виджете.
  console.log('LOGIN MAIL:', await fillField('E-mail кабинета', MAIL))
  console.log('LOGIN PASS:', await fillField('Пароль', PASS))
  const loginBtn = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === 'Войти')
    if (!b) return null
    const r = b.getBoundingClientRect()
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
  })()`)
  if (!loginBtn) { console.log('NO LOGIN BUTTON'); return }
  await clickAt(loginBtn.x, loginBtn.y)
  await sleep(2500)

  console.log('LOGGED IN PANEL:', await evaluate(`(() => {
    const t = document.body.innerText
    const i = t.indexOf('Вы вошли как')
    return i < 0 ? t.slice(0, 0) : t.slice(i, i + 80).split('\\n').slice(0, 2).join(' ')
  })()`))
  console.log('SHOWS CABINET MAIL:', await evaluate(`document.body.innerText.includes(${JSON.stringify(MAIL)})`))
  await shot('v-login.png')

  // 4. Оплата.
  // Кнопку сначала доводим до видимой области: форма длинная, и `rect` может
  // оказаться ниже вьюпорта — тогда клик уходит «в никуда» и запроса нет.
  await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => /^Оплатить/.test(x.textContent.trim()))
    if (b) b.scrollIntoView({ block: 'center' })
    return Boolean(b)
  })()`)
  await sleep(600)
  const payBtn = await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => /^Оплатить/.test(x.textContent.trim()))
    if (!b) return null
    const r = b.getBoundingClientRect()
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 }
  })()`)
  if (!payBtn) { console.log('NO PAY BUTTON'); console.log('BODY:', await evaluate('document.body.innerText.slice(-900)')); return }
  console.log('PAY BTN STATE:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => /^Оплатить/.test(x.textContent.trim()))
    return JSON.stringify({ disabled: b.disabled, text: b.textContent.trim().slice(0, 40) })
  })()`))
  console.log('HINTS:', await evaluate(`document.body.innerText.slice(-1400)`))
  await clickAt(payBtn.x, payBtn.y)
  await sleep(4000)
  console.log('PAID:', await evaluate(`document.body.innerText.includes('Оплата прошла')`))
  console.log('ORDER MAIL MATCHES:', await evaluate(`document.body.innerText.includes(${JSON.stringify(MAIL)})`))
  await shot('v-login-pay.png')
  console.log('ROUTE AFTER PAY:', await route())
  console.log('NET:', net.filter((n) => /payments|checkout|auth/.test(n)))
}

main().catch((e) => { console.error('FAIL', e); process.exit(1) })
