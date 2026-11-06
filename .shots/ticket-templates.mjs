/**
 * Скриншоты раздела «Шаблоны билетов»: список карточек и конструктор.
 *
 * Проверяем живьём две вещи, которые статикой не проверить:
 *   1. миниатюра карточки показывает РЕАЛЬНУЮ раскладку макета, а не пятна;
 *   2. конструктор рисует стандартный билет так же, как PDF (перенос названия
 *      по ширине, скругление панели QR, цена из переменной).
 *
 * Запуск: node .shots/ticket-templates.mjs <token> [templateId]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9419
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const TEMPLATE_ID = process.argv[3] ?? '6'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-tpl',
    '--window-size=1440,1000',
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

async function shot(name, full = false) {
  const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: full })
  if (r?.data) {
    writeFileSync(`.shots/${name}`, Buffer.from(r.data, 'base64'))
    console.log(`скриншот: .shots/${name}`)
  }
}

await send('Page.navigate', { url: `${BASE}/#/` })
await sleep(2000)
await ev(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)
await ev(`localStorage.setItem('nabilet_admin_user', JSON.stringify({ id: 'seed-admin', email: 'admin@nabilet.local', first_name: 'Админ', roles: ['admin'] }))`)
await ev(`location.hash = '#/admin/ticket-templates'`)
await sleep(700)
await ev(`location.reload()`)

await waitFor(`!!document.querySelector('article')`, 'карточки шаблонов')
await sleep(800)

console.log(
  await ev(`(() => {
    const card = document.querySelector('article');
    return JSON.stringify({
      title: card?.querySelector('h2')?.textContent?.trim(),
      meta: card?.querySelector('p')?.textContent?.replace(/\\s+/g, ' ').trim(),
      miniText: [...card.querySelectorAll('button span span span')].map((s) => s.textContent.trim()).filter(Boolean).slice(0, 8),
    }, null, 1);
  })()`),
)
await shot('ticket-templates-list.png')

await ev(`location.hash = '#/admin/ticket-templates?template=${TEMPLATE_ID}'`)
await sleep(2500)
await waitFor(`!!document.querySelector('.canvas-element, canvas, [class*=canvas]')`, 'холст конструктора')
// Уводим курсор за пределы холста: у `.canvas-element:hover` есть синяя
// обводка, и в headless она иначе остаётся на элементе под курсором.
await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 700, y: 950 })
await sleep(1500)

console.log(
  await ev(`(() => {
    const canvas = document.querySelector('[class*=canvas-stage], .canvas, .canvas-area') || document.body;
    const texts = [...document.querySelectorAll('.element-text')].map((n) => n.textContent.trim());
    const rects = [...document.querySelectorAll('.element-rectangle')].map((n) => getComputedStyle(n).borderRadius);
    return JSON.stringify({ texts, rects, canvasCount: document.querySelectorAll('.canvas-element').length }, null, 1);
  })()`),
)
await shot('ticket-builder-default.png', true)

// Предпросмотр: та же вёрстка без рамок редактора — ровно то, что увидит покупатель.
await ev(`(() => {
  const btn = [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Предпросмотр'));
  if (btn) btn.click();
  return !!btn;
})()`)
await sleep(1200)
await shot('ticket-builder-preview.png')

ws.close()
chrome.kill()
process.exit(0)
