/**
 * Скриншот страницы «Мои билеты» глазами покупателя (витрина, без логина).
 *
 * Нужен, чтобы показать билет по умолчанию: гостевой витрины без аккаунта
 * билеты отдаются по гостевому токену корзины (`X-Cart-Token`), поэтому
 * достаточно подставить его в localStorage — сеанс не требуется.
 *
 * Запуск: node .shots/my-tickets.mjs <cartToken> [outName]
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9425
const BASE = 'http://127.0.0.1:8000'
const CART_TOKEN = process.argv[2] ?? ''
const OUT = process.argv[3] ?? 'storefront-my-tickets.png'

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-mytickets',
    '--window-size=900,1000',
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
})

await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')

async function ev(expression) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return r?.result?.value ?? r?.exceptionDetails?.text ?? ''
}

// Токен корзины сеем ДО скриптов страницы: иначе на уже поднятыйся роутер
// хэш-навигация откатывается начальной навигацией на `#/` (проверено).
await send(
  'Page.addScriptToEvaluateOnNewDocument',
  {
    source: `try { if (!localStorage.getItem('nabilet_cart_token')) localStorage.setItem('nabilet_cart_token', ${JSON.stringify(CART_TOKEN)}) } catch (e) {}`,
  },
)
await send('Page.navigate', { url: `${BASE}/#/tickets` })
// Первая загрузка в dev-режиме долгая: Vite компилирует модули на лету
// (проверено — заголовок появлялся только на 10-й секунде), поэтому ждём
// именно появление страницы, а не фиксированную паузу.
for (let i = 0; i < 60; i++) {
  if ((await ev(`/Мои билеты/.test(document.body.innerText)`)) === true) break
  await sleep(500)
}
await sleep(3000)

console.log(
  await ev(`(() => {
    const canvas = document.querySelector('main canvas, main .canvas-inner');
    const card = document.querySelector('main article');
    return JSON.stringify({
      title: document.querySelector('main h1')?.textContent?.trim() ?? null,
      hasTicketCanvas: !!canvas,
      ticketText: card?.innerText?.replace(/\\s+/g, ' ').trim().slice(0, 300) ?? null,
    }, null, 1);
  })()`),
)

const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
if (r?.data) {
  writeFileSync(`.shots/${OUT}`, Buffer.from(r.data, 'base64'))
  console.log(`скриншот: .shots/${OUT}`)
}

ws.close()
chrome.kill()
process.exit(0)
