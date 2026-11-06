/**
 * Проверка реальными данными: раздел «Шаблоны писем» (NCard / NPageHeader +
 * редактор с курсорной вставкой переменных). Бэкенд живой на :8000 и отдаёт
 * SPA + /api/v1 с одного origin, поэтому ставим настоящий Bearer-токен — и
 * список из 10 шаблонов подгрузится с сервера, а не из каркаса.
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP = 9432
const BASE = 'http://127.0.0.1:8000'
const TOKEN = '17804beceae6e51407730dbc33afa896596a675e044ef5502d65dcc63d102d79c506f2e770b0dfc482d6d82ccbd70ab62c996839c9160e26a95cc479d8e6e3d1'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${CDP}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-tpl-check',
    '--window-size=1440,1500',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function target() {
  for (let i = 0; i < 40; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${CDP}/json/list`)
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
const consoleErrors = []
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
    consoleErrors.push('EXC: ' + (msg.params?.exceptionDetails?.exception?.description ?? msg.params?.exceptionDetails?.text))
  }
  if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
    consoleErrors.push('CONSOLE.error: ' + (msg.params.args ?? []).map((a) => a.value ?? a.description).join(' ').slice(0, 240))
  }
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expression) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return r?.result?.value ?? r?.exceptionDetails?.text ?? ''
}
async function waitFor(expression, label, tries = 50) {
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

// 0) Загрузить SPA (origin :8000), поставить токен, уйти на раздел.
await send('Page.navigate', { url: `${BASE}/` })
await sleep(1500)
await waitFor(`!!document.querySelector('#app')`, 'SPA смонтирован')
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`location.hash = '#/admin/notification-templates'`)
await sleep(1500)

// 1) Дождаться 10 кнопок в списке событий (nav в карточке «События»).
await waitFor(`document.querySelectorAll('#app nav button').length >= 10`, '10 шаблонов в списке')
await sleep(800)
await shot('admin-templates-list.png')

// 2) Отчёт: сколько групп, активных точек, кнопок.
const report = await ev(`(function(){
  const nav = document.querySelector('#app nav');
  const groups = nav ? nav.querySelectorAll(':scope > div').length : 0;
  const btns = nav ? nav.querySelectorAll('button').length : 0;
  const dots = nav ? nav.querySelectorAll('span.rounded-full').length : 0;
  const editorTitle = (document.querySelector('#app h1, #app .font-display') || {}).textContent || '';
  const chips = document.querySelectorAll('#app button.font-mono').length;
  const hasPreviewBtn = !!Array.from(document.querySelectorAll('#app button')).find(b => /Предпросмотр/.test(b.textContent));
  const hasSaveBtn = !!Array.from(document.querySelectorAll('#app button')).find(b => /Сохранить/.test(b.textContent));
  return JSON.stringify({groups, btns, dots, chips, hasPreviewBtn, hasSaveBtn});
})()`)
console.log('REPORT:', report)

// 3) Выбрать «Заказ оплачен» (order.paid) и проверить, что редактор показал тему.
await ev(`(function(){
  const btns = Array.from(document.querySelectorAll('#app nav button'));
  const t = btns.find(b => /Заказ оплачен/.test(b.textContent));
  if (t) t.click();
})()`)
await sleep(900)
await shot('admin-templates-editor.png')

// 4) Открыть предпросмотр, дождаться iframe с HTML письма.
await ev(`(function(){
  const b = Array.from(document.querySelectorAll('#app button')).find(x => /Предпросмотр/.test(x.textContent));
  if (b) b.click();
})()`)
await waitFor(`!!document.querySelector('#app iframe[srcdoc]')`, 'iframe предпросмотра', 30)
await sleep(600)
await shot('admin-templates-preview.png')

console.log('\n=== Ошибки консоли ===')
console.log(consoleErrors.length ? consoleErrors.join('\n') : 'нет')

ws.close()
chrome.kill()
