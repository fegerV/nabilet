// Скриншоты витрины и конструктора: те же страницы, что видит пользователь.
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync, mkdirSync } from 'node:fs'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const PORT = 9334
const BASE = 'http://127.0.0.1:8000'
const TOKEN = process.argv[2] ?? ''
const OUT = 'outputs'
mkdirSync(OUT, { recursive: true })

const chrome = spawn(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${PORT}`,
    '--user-data-dir=C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-shots',
    '--window-size=1440,900',
    '--force-device-scale-factor=1',
    'about:blank',
  ],
  { stdio: 'ignore' },
)

async function firstPage() {
  for (let i = 0; i < 40; i += 1) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json/list`)
      const page = (await res.json()).find((t) => t.type === 'page')
      if (page) return page
    } catch {
      /* chrome стартует */
    }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
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

async function evaluate(expression) {
  const res = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })
  return res?.result?.value ?? ''
}

async function setViewport(width, height, mobile = false) {
  await send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile,
  })
}

async function go(hash, waitMs = 4500) {
  await send('Page.navigate', { url: `${BASE}/${hash}` })
  await sleep(waitMs)
}

async function shoot(name) {
  const res = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  writeFileSync(`${OUT}/${name}.png`, Buffer.from(res.data, 'base64'))
  console.log(`saved ${OUT}/${name}.png`)
}

async function clickText(text) {
  const ok = await evaluate(
    `(() => { const el = [...document.querySelectorAll('button, a')]
        .find((n) => n.innerText.trim() === ${JSON.stringify(text)});
      if (!el) return 'not found'; el.click(); return 'clicked'; })()`,
  )
  await sleep(1200)
  return ok
}

await go('#/')
await evaluate(`localStorage.setItem('nabilet_admin_token', ${JSON.stringify(TOKEN)})`)

// Витрина — десктоп
await go('#/')
await shoot('storefront-desktop')

// Витрина — мобильный
await setViewport(390, 844, true)
await go('#/')
await shoot('storefront-mobile')
await setViewport(1440, 900)

// Конструктор: секции
await go('#/admin/storefront', 5000)
await shoot('admin-storefront-sections')

// Конструктор: цвета
console.log(await clickText('Цвета'))
await shoot('admin-storefront-theme')

// Конструктор: предпросмотр
console.log(await clickText('Предпросмотр'))
await sleep(1500)
await shoot('admin-storefront-preview')

// Обзор
await go('#/admin', 5000)
await shoot('admin-dashboard')

// Проверка горизонтального переполнения: обрезанная кнопка «выход» — реальный
// дефект, а не артефакт скриншота, и лучше поймать его здесь.
for (const [hash, name] of [
  ['#/', 'витрина'],
  ['#/admin', 'обзор'],
  ['#/admin/storefront', 'конструктор'],
]) {
  await go(hash, 4000)
  const probe = await evaluate(
    `(() => { const de = document.documentElement;
       return JSON.stringify({
         viewport: window.innerWidth,
         scrollWidth: de.scrollWidth,
         overflow: de.scrollWidth > window.innerWidth + 1,
       }); })()`,
  )
  console.log(`OVERFLOW ${name}: ${probe}`)
}

ws.close()
chrome.kill()
process.exit(0)
