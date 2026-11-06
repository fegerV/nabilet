// Проверка формы мероприятия: статус как бейдж, блок «Готова к продаже»,
// список макетов билета из API. Скриншот + текст + ошибки консоли.
//
//   node .shots/event-form-publish.mjs <ADMIN_TOKEN> [EVENT_ID]
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9344
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const EVENT_ID = process.argv[3] ?? '2'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-form-check',
    '--window-size=1440,1200',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function targets() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const list = await res.json()
      const page = list.find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* chrome ещё поднимается */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await targets()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0
const consoleErrors = []

function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}

ws.addEventListener('message', (event) => {
  const msg = JSON.parse(event.data)
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg.result)
    pending.delete(msg.id)
    return
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    const details = msg.params?.exceptionDetails
    consoleErrors.push(`EXCEPTION: ${details?.exception?.description ?? details?.text ?? 'unknown'}`)
  }
  if (msg.method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(msg.params?.type)) {
    const text = (msg.params.args ?? []).map((a) => a.value ?? a.description ?? a.type).join(' ')
    consoleErrors.push(`CONSOLE.${msg.params.type}: ${text}`)
  }
})

await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
await send('Log.enable')

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return res?.result?.value ?? res?.exceptionDetails?.text ?? ''
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

// Переход по hash без перезагрузки НЕ доезжает до vue-router: `Page.navigate`
// на тот же документ с другой строкой фрагмента не пересоздаёт роутер, и
// страница остаётся витриной. Поэтому: сменить hash, затем reload.
await evaluate(`location.hash = '#/admin/events/${EVENT_ID}'`)
await sleep(1500)
await evaluate(`location.reload()`)
// Ждём появления самой формы, а не фиксированную паузу: на «холодном» профиле
// Chrome бутстрап приложения занимает больше времени, и фиксированные 8 секунд
// давали скриншот состояния «Загрузка…» — то есть ложный вывод о поломке.
for (let i = 0; i < 40; i += 1) {
  const ready = await evaluate(`!!document.querySelector('main form')`)
  if (ready === true) break
  await sleep(500)
}
await sleep(1200)

console.log('URL:', await evaluate('location.href'))
console.log('TITLE:', await evaluate('document.title'))

// Сетевые факты: что SPA реально запросил и что ему ответили.
console.log('\n=== RESOURCES ===')
console.log(
  await evaluate(
    `JSON.stringify(performance.getEntriesByType('resource')
       .filter(e => e.name.includes('/api/'))
       .map(e => e.name.replace(location.origin, '') + ' → ' + e.responseStatus + ' ' + Math.round(e.duration) + 'ms'), null, 1)`,
  ),
)

console.log('\n=== MANUAL FETCH FROM THE PAGE ===')
console.log(
  await evaluate(
    `(async () => {
       try {
         const r = await fetch('/api/v1/events/${EVENT_ID}', {
           headers: { Accept: 'application/json', Authorization: 'Bearer ' + (localStorage.getItem('nabilet_admin_token') ?? '') },
         });
         return 'status=' + r.status + ' body=' + (await r.text()).slice(0, 200);
       } catch (e) { return 'THREW: ' + e; }
     })()`,
  ),
)

console.log('\n=== PENDING STATE ===')
console.log(
  await evaluate(
    `JSON.stringify({
       loadingBlock: !!document.querySelector('main')?.innerText.includes('Загрузка'),
       hasToken: !!localStorage.getItem('nabilet_admin_token'),
       forms: document.querySelectorAll('main form').length,
     })`,
  ),
)

// Что реально отрисовалось: заголовки секций, бейдж статуса, опции макета.
const facts = await evaluate(
  `(() => {
     const main = document.querySelector('main') ?? document.body;
     const text = main.innerText.replace(/\\s+/g, ' ');
     const sections = [...main.querySelectorAll('h2')].map(h => h.innerText.trim());
     const statusSelect = [...main.querySelectorAll('select')].length;
     const selects = [...main.querySelectorAll('select')].map(s => ({
       value: s.value,
       options: [...s.options].map(o => o.text.trim()),
     }));
     const badges = [...main.querySelectorAll('[class*="ring-1"]')].map(b => b.innerText.trim());
     const buttons = [...main.querySelectorAll('button')].map(b => b.innerText.trim()).filter(Boolean);
     return JSON.stringify({ sections, statusSelect, selects, badges, buttons, text }, null, 1);
   })()`,
)

console.log('=== ERRORS ===')
console.log(consoleErrors.length ? [...new Set(consoleErrors)].join('\n') : '(нет)')
console.log('=== FORM ===')

const parsed = JSON.parse(facts || '{}')
console.log('Секции:', (parsed.sections ?? []).join(' | '))
console.log('Select-ов на странице:', parsed.statusSelect)
console.log('Бейджи статуса:', (parsed.badges ?? []).join(' / ') || '(нет)')
console.log('Кнопки:', (parsed.buttons ?? []).join(' | '))
console.log('Опции выпадающих списков:')
for (const sel of parsed.selects ?? []) {
  console.log(`  value="${sel.value}" → ${sel.options.join(' / ')}`)
}
console.log('\n=== TEXT ===')
console.log((parsed.text ?? '').slice(0, 4000))

const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
if (shot?.data) {
  writeFileSync('.shots/admin-event-form-publish.png', Buffer.from(shot.data, 'base64'))
  console.log('\nскриншот: .shots/admin-event-form-publish.png')
}

ws.close()
chrome.kill()
process.exit(0)
