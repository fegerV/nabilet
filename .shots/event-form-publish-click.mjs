// Нажатие «Готова к продаже» и «Отменить мероприятие» в реальном браузере.
//
// Проверяет то, что нельзя проверить curl-ом: кнопка есть, она нажимается,
// ответ политики доезжает до администратора текстом, а бейдж статуса меняется.
// `window.confirm` в headless блокирует страницу, поэтому диалог перехватывается
// через CDP и подтверждается.
//
//   node .shots/event-form-publish-click.mjs <ADMIN_TOKEN> [EVENT_ID]
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9388
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
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-click',
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
const dialogs = []

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
  // window.confirm блокирует страницу до ответа — отвечаем «ОК».
  if (msg.method === 'Page.javascriptDialogOpening') {
    dialogs.push(msg.params.message)
    void send('Page.handleJavaScriptDialog', { accept: true })
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    const d = msg.params?.exceptionDetails
    consoleErrors.push(`EXCEPTION: ${d?.exception?.description ?? d?.text}`)
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
    consoleErrors.push(`CONSOLE.error: ${(msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ')}`)
  }
})

await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return res?.result?.value ?? res?.exceptionDetails?.text ?? ''
}

/** Нажать кнопку по её подписи и дождаться, пока текст перестанет содержать `pendingText`. */
async function clickByText(label) {
  const found = await evaluate(
    `(() => {
       const b = [...document.querySelectorAll('button')].find(x => x.innerText.trim() === ${JSON.stringify(label)});
       if (!b) return false;
       b.click();
       return true;
     })()`,
  )
  return found === true
}

async function readState() {
  const raw = await evaluate(
    `(() => {
       const main = document.querySelector('main');
       const badge = main.querySelector('[class*="ring-1"]');
       const notice = main.querySelector('[class*="text-mint-400"], [class*="text-sun-400"], [class*="text-rose-400"]');
       return JSON.stringify({
         badge: badge ? badge.innerText.trim() : null,
         notice: notice ? notice.innerText.trim() : null,
         buttons: [...main.querySelectorAll('button')].map(b => b.innerText.trim()).filter(Boolean),
         text: main.innerText.replace(/\\s+/g, ' '),
       });
     })()`,
  )
  return JSON.parse(raw || '{}')
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2500)
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await evaluate(`location.hash = '#/admin/events/${EVENT_ID}'`)
await sleep(1500)
await evaluate(`location.reload()`)
for (let i = 0; i < 40; i += 1) {
  if ((await evaluate(`!!document.querySelector('main form')`)) === true) break
  await sleep(500)
}
await sleep(1000)

const before = await readState()
console.log('=== ДО ===')
console.log('бейдж:', before.badge)
console.log('кнопки:', (before.buttons ?? []).join(' | '))
console.log('блок:', (before.text ?? '').match(/ГОТОВА К ПРОДАЖЕ(.{0,200})/)?.[1]?.trim())

console.log('\n=== НАЖИМАЕМ «Готова к продаже» ===')
console.log('кнопка найдена:', await clickByText('Готова к продаже'))
await sleep(5000)

const after = await readState()
console.log('бейдж:', after.badge)
console.log('сообщение политики:', after.notice)
console.log('кнопки:', (after.buttons ?? []).join(' | '))

const shot1 = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
if (shot1?.data) {
  writeFileSync('.shots/admin-event-form-published.png', Buffer.from(shot1.data, 'base64'))
  console.log('скриншот: .shots/admin-event-form-published.png')
}

console.log('\n=== НАЖИМАЕМ «Отменить мероприятие» ===')
console.log('кнопка найдена:', await clickByText('Отменить мероприятие'))
await sleep(5000)

const cancelled = await readState()
console.log('бейдж:', cancelled.badge)
console.log('сообщение политики:', cancelled.notice)
console.log('подтверждений в диалоге:', dialogs.length, dialogs[0] ? `«${dialogs[0].slice(0, 80)}»` : '')
console.log('кнопки:', (cancelled.buttons ?? []).join(' | '))

console.log('\n=== ERRORS ===')
console.log(consoleErrors.length ? [...new Set(consoleErrors)].join('\n') : '(нет)')

ws.close()
chrome.kill()
process.exit(0)
