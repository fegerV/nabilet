/**
 * Проверка карточки мероприятия живым браузером: секция «Сеансы — где и когда»
 * и выбор площадки в форме сеанса.
 *
 * Повод — жалоба «на этой странице нет выбора площадки, назначение даты и
 * времени». Скриншот нужен именно живой: статический HTML ничего не доказывает,
 * потому что секция зависит от `GET /sessions?event_id=`, а список залов —
 * от выбранной площадки.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9411
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const EVENT_ID = process.argv[3] ?? '1'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-sessions',
    '--window-size=1440,1400',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function target() {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const list = await res.json()
      const page = list.find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* not up yet */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const t = await target()
const ws = new WebSocket(t.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0

function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}

ws.addEventListener('message', (e) => {
  const msg = JSON.parse(e.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    console.log('EXC:', msg.params?.exceptionDetails?.exception?.description ?? msg.params?.exceptionDetails?.text)
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
    console.log('CONSOLE.error:', (msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ').slice(0, 240))
  }
})

await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expression) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return r?.result?.value ?? r?.exceptionDetails?.text ?? ''
}

async function waitFor(expression, label, tries = 40) {
  for (let i = 0; i < tries; i++) {
    if ((await ev(expression)) === true) return true
    await sleep(500)
  }
  throw new Error(`не дождались: ${label}`)
}

async function shot(name) {
  const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  if (r?.data) {
    writeFileSync(`.shots/${name}`, Buffer.from(r.data, 'base64'))
    console.log(`скриншот: .shots/${name}`)
  }
}

// Помощники внутри страницы: ищем элементы по подписи поля, а не по классу.
const HELPERS = `
window.__byLabel = (text) => {
  const label = [...document.querySelectorAll('label')]
    .find((l) => l.textContent.trim().replace(/\\s*\\*$/, '') === text);
  return label ? document.getElementById(label.getAttribute('for')) : null;
};
window.__options = (sel) => sel ? [...sel.options].map((o) => o.value + ':' + o.textContent.trim()) : null;
window.__setSelect = (sel, value) => {
  sel.value = value;
  sel.dispatchEvent(new Event('change', { bubbles: true }));
  return sel.value;
};
true
`

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/events/${EVENT_ID}'`)
await sleep(1500)
await ev(`location.reload()`)

await waitFor(`!!document.querySelector('main form')`, 'карточка мероприятия')
await ev(HELPERS)

console.log('\n=== 1. Секция «Сеансы — где и когда» ===')
console.log(
  await ev(`(() => {
    const h = [...document.querySelectorAll('h2')].find((x) => /Сеансы — где и когда/i.test(x.textContent));
    if (!h) return 'СЕКЦИИ НЕТ';
    const section = h.closest('section');
    return JSON.stringify({
      heading: h.textContent.trim(),
      text: section.innerText.replace(/\\s+/g, ' ').slice(0, 420),
    }, null, 1);
  })()`),
)

await shot('admin-event-sessions.png')

console.log('\n=== 2. Открываем «Добавить сеанс» ===')
console.log(
  'нажали:',
  await ev(`(() => {
    const b = [...document.querySelectorAll('button')].find((x) => x.innerText.trim() === 'Добавить сеанс');
    if (!b) return false;
    b.click();
    return true;
  })()`),
)

await waitFor(`!!document.querySelector('[role="dialog"]')`, 'модалка сеанса')
await sleep(2500)
await ev(HELPERS)

console.log(
  await ev(`(() => {
    const dlg = document.querySelector('[role="dialog"]');
    const venue = window.__byLabel('Площадка');
    const hall = window.__byLabel('Зал');
    return JSON.stringify({
      title: dlg.querySelector('h2')?.textContent.trim(),
      eventFieldIsLocked: /Мероприятие/.test(dlg.innerText) && !window.__byLabel('Мероприятие'),
      venueOptions: window.__options(venue),
      hallDisabledBeforeVenue: hall ? hall.disabled : 'НЕТ СЕЛЕКТА ЗАЛА',
      hallOptionsBeforeVenue: window.__options(hall),
    }, null, 1);
  })()`),
)

await shot('admin-session-modal-venue.png')

console.log('\n=== 3. Выбираем площадку — список залов должен сузиться ===')
console.log(
  await ev(`(() => {
    const venue = window.__byLabel('Площадка');
    const first = [...venue.options].find((o) => o.value !== '');
    if (!first) return 'ПЛОЩАДОК НЕТ';
    window.__setSelect(venue, first.value);
    return 'выбрали площадку ' + first.textContent.trim() + ' (id=' + first.value + ')';
  })()`),
)

await sleep(1500)
await ev(HELPERS)

console.log(
  await ev(`(() => {
    const hall = window.__byLabel('Зал');
    return JSON.stringify({
      hallDisabledNow: hall.disabled,
      hallOptions: window.__options(hall),
    }, null, 1);
  })()`),
)

await shot('admin-session-modal-halls.png')

console.log('\n=== 4. Даты и время в форме ===')
console.log(
  await ev(`(() => {
    const dlg = document.querySelector('[role="dialog"]');
    const dates = [...dlg.querySelectorAll('input[type="date"]')].length;
    const labels = [...dlg.querySelectorAll('label')].map((l) => l.textContent.trim().replace(/\\s*\\*$/, ''));
    return JSON.stringify({ dateInputs: dates, labels }, null, 1);
  })()`),
)

ws.close()
chrome.kill()
process.exit(0)
