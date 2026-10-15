import { spawn } from 'node:child_process'
import fs from 'node:fs'

const chromePath = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
const port = 9336
const url = 'http://127.0.0.1:5173/outputs/hall-renderer-preview.html'
const profile = 'C:/Users/Professional/AppData/Local/Temp/nabilet-render-preview'
const chrome = spawn(chromePath, [
  '--headless=new', '--disable-gpu', '--no-sandbox',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  'about:blank',
], { stdio: 'ignore' })

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const exceptions = []
let ws

try {
  let target
  for (let attempt = 0; attempt < 40 && !target; attempt += 1) {
    await sleep(250)
    try {
      const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()
      target = pages.find((page) => page.type === 'page')
    } catch {}
  }
  if (!target) throw new Error('Chrome CDP page did not start')

  ws = new WebSocket(target.webSocketDebuggerUrl)
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true })
    ws.addEventListener('error', reject, { once: true })
  })

  let nextId = 0
  const pending = new Map()
  ws.addEventListener('message', (event) => {
    const message = JSON.parse(event.data)
    if (message.id && pending.has(message.id)) {
      pending.get(message.id)(message)
      pending.delete(message.id)
    } else if (message.method === 'Runtime.exceptionThrown') {
      exceptions.push(message.params?.exceptionDetails?.exception?.description ?? 'runtime exception')
    }
  })
  const send = (method, params = {}) => new Promise((resolve) => {
    const id = ++nextId
    pending.set(id, resolve)
    ws.send(JSON.stringify({ id, method, params }))
  })
  const evaluate = async (expression) => {
    const response = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })
    if (response.result?.exceptionDetails) throw new Error(response.result.exceptionDetails.exception?.description ?? 'Runtime evaluation failed')
    return response.result?.result?.value
  }

  await send('Page.enable')
  await send('Runtime.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false })
  await send('Page.navigate', { url })

  let ready = false
  for (let attempt = 0; attempt < 50 && !ready; attempt += 1) {
    await sleep(400)
    ready = (await evaluate(`document.querySelectorAll('svg circle.seat-dot').length === 359`)) === true
  }
  if (!ready) {
    const state = await evaluate(`({ title: document.title, text: document.body.innerText.slice(0, 500), circles: document.querySelectorAll('svg circle.seat-dot').length })`)
    throw new Error(`Renderer did not become ready: ${JSON.stringify(state)}; exceptions=${JSON.stringify(exceptions)}`)
  }

  const metrics = await evaluate(`(() => {
    const dots = [...document.querySelectorAll('svg circle.seat-dot')];
    return {
      seatDots: dots.length,
      sourceTables: window.__previewInventory?.filter((item) => (item.seat?.sector_name ?? '').toLowerCase().includes('стол')).length ?? -1,
      sourceTableNames: [...new Set(window.__previewInventory?.map((item) => item.seat?.sector_name) ?? [])].slice(0, 4),
      firstSeat: window.__previewInventory?.[0]?.seat ?? null,
      priceColors: [...new Set(dots.map((node) => node.getAttribute('fill')))],
      tableGroups: document.querySelectorAll('svg g[aria-hidden="true"] circle').length,
      tableLabels: [...document.querySelectorAll('svg text')].map((node) => node.textContent).filter((text) => /^\\d+$/.test(text ?? '')).length,
      sampleSector: document.querySelector('svg g[aria-hidden="true"]')?.textContent ?? null,
      horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth,
      legend: [...document.querySelectorAll('[aria-label="Цены мест"] span')].map((node) => node.textContent.trim()),
    };
  })()`)
  const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true })
  if (!screenshot.result?.data) throw new Error(`Screenshot failed: ${JSON.stringify(screenshot)}`)
  fs.writeFileSync('C:/Project/nabilet/outputs/hall-renderer-preview.png', Buffer.from(screenshot.result.data, 'base64'))
  const interaction = await evaluate(`(() => {
    const seat = document.querySelector('svg circle.seat-dot[aria-label*="свободно"]');
    seat?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    return { seatFound: Boolean(seat), emitted: window.__previewToggles?.length ?? 0 };
  })()`)
  if (interaction.emitted !== 1) throw new Error(`Seat selection did not emit: ${JSON.stringify(interaction)}`)
  console.log(JSON.stringify({ ...metrics, interaction, exceptions }, null, 2))
} finally {
  ws?.close()
  chrome.kill()
}
