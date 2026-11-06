/**
 * Проверка покупки со ВТОРОЙ стоячей зоны (танцпол) на витрине.
 *
 * Раньше CoordSeatMap брал только первую standing-позицию (`find`), поэтому
 * вторая категория («Танцпол · 500») на карте отсутствовала и её билеты
 * нельзя было купить. Скрипт кликает по рамке второй зоны, выбирает 2 билета
 * и читает сводку заказа.
 *
 * Запуск: node .shots/standing-zone-click-check.mjs
 */
import { spawn } from 'node:child_process'
import { setTimeout as sleep } from 'node:timers/promises'
import { writeFileSync } from 'node:fs'

const URL = 'http://127.0.0.1:8000/#/event/test-proverka/seats?session=20'
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const CDP_PORT = 9367
const PROFILE = 'C:\\Users\\Professional\\AppData\\Local\\Temp\\nabilet-zone-click'

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${CDP_PORT}`, `--user-data-dir=${PROFILE}`,
  '--window-size=1440,1100', '--force-device-scale-factor=1', URL,
], { stdio: 'ignore' })

async function firstPage() {
  for (let i = 0; i < 80; i += 1) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json()).find((t) => t.type === 'page')
      if (page) return page
    } catch { /* старт */ }
    await sleep(250)
  }
  throw new Error('CDP не поднялся')
}

const target = await firstPage()
const ws = new WebSocket(target.webSocketDebuggerUrl)
const pending = new Map()
let seq = 0
const errors = []
function send(method, params = {}) {
  const id = ++seq
  ws.send(JSON.stringify({ id, method, params }))
  return new Promise((resolve) => pending.set(id, resolve))
}
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data)
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return }
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params?.exceptionDetails?.text)
  if (m.method === 'Runtime.consoleAPICalled' && m.params?.type === 'error') errors.push(String(m.params.args?.[0]?.value).slice(0, 160))
})
await new Promise((r) => ws.addEventListener('open', r, { once: true }))
await send('Runtime.enable')
await send('Page.enable')
const ev = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }))?.result?.value

await sleep(6000)
const zones = await ev(`[...document.querySelectorAll('svg text')].map(t => t.textContent).filter(t => /Танцпол/.test(t))`)
console.log('подписи зон на карте:', JSON.stringify(zones))

// Клик по рамке второй зоны.
const clicked = await ev(`(() => {
  const groups = [...document.querySelectorAll('svg g')]
  const g = groups.find((el) => [...el.querySelectorAll('text')].some((t) => /^Танцпол · /.test(t.textContent)))
  if (!g) return 'zone-not-found'
  const rect = g.querySelector('rect')
  rect.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  return 'clicked'
})()`)
console.log('клик по зоне:', clicked)
await sleep(700)

const picker = await ev(`(() => {
  const g = [...document.querySelectorAll('svg g')].find((el) => [...el.querySelectorAll('text')].some((t) => /^Танцпол · /.test(t.textContent)))
  return g ? g.querySelectorAll('circle').length : -1
})()`)
console.log('кружков в пикере:', picker)

// Второй кружок = 2 билета.
const picked = await ev(`(() => {
  const g = [...document.querySelectorAll('svg g')].find((el) => [...el.querySelectorAll('text')].some((t) => /^Танцпол · /.test(t.textContent)))
  const dots = g ? [...g.querySelectorAll('circle')] : []
  const two = dots[1]
  if (!two) return 'no-dot'
  two.dispatchEvent(new MouseEvent('click', { bubbles: true }))
  return 'picked-2'
})()`)
console.log('выбор количества:', picked)
await sleep(2500)

const summary = await ev(`(() => {
  const aside = document.body.innerText
  const m = aside.match(/Ваш выбор[\\s\\S]{0,300}/)
  return m ? m[0].replace(/\\n+/g, ' | ') : 'нет сводки'
})()`)
console.log('сводка:', summary)
console.log('ошибок консоли:', errors.length, errors.slice(0, 3))

const shot = await send('Page.captureScreenshot', { format: 'png' })
writeFileSync('outputs/storefront-zone-picked.png', Buffer.from(shot.data, 'base64'))
ws.close()
chrome.kill()
process.exit(0)
