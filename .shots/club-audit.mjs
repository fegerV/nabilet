// Полный аудит страницы «Клуб зрителей» (#/club): данные, кнопки, темы.
// Отдельно проверяются два класса, которые Tailwind молча выбрасывает:
//   `bg-overlay/*`  — цвет `overlay` не объявлен в tailwind.config;
//   `bg-mint-500/12` — модификатор прозрачности вне шага 5 (12 не кратно 5).
import fs from 'node:fs'

const PORT = Number(process.env.CDP_PORT || 9400)
const BASE = process.env.BASE || 'http://127.0.0.1:8001'
const TOKEN = process.env.CLUB_TOKEN || fs.readFileSync('.shots/club-token.env', 'utf8').split('=')[1].trim()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function main() {
  const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()
  const page = list.find((t) => t.type === 'page')
  const ws = new WebSocket(page.webSocketDebuggerUrl)
  await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }) })

  let id = 0
  const pending = new Map()
  const errors = []
  const api = []
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data)
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map((a) => String(a.value ?? '')).join(' ').slice(0, 200))
    if (m.method === 'Runtime.exceptionThrown') errors.push('EXC ' + String(m.params?.exceptionDetails?.text ?? '').slice(0, 200))
    if (m.method === 'Network.responseReceived') {
      const u = m.params.response.url
      if (/\/api\/v1\/me\//.test(u)) api.push(`${m.params.response.status} ${u.replace(BASE, '').split('?')[0]}`)
    }
  })
  const send = (method, params = {}) => new Promise((res) => { const myId = ++id; pending.set(myId, res); ws.send(JSON.stringify({ id: myId, method, params })) })
  const evaluate = async (e) => {
    const r = await send('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })
    if (r.result?.exceptionDetails) return 'ERR ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)
    return r.result?.result?.value
  }
  const waitFor = async (expr, ms = 20000) => { const dl = Date.now() + ms; while (Date.now() < dl) { if (await evaluate(expr)) return true; await sleep(250) } return false }
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
    if (r.result?.data) fs.writeFileSync(`.shots/${name}.png`, Buffer.from(r.result.data, 'base64'))
  }
  const hash = () => evaluate(`location.hash`)

  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable')
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, mobile: false, deviceScaleFactor: 1 })

  // ── Токен и профиль покупателя ────────────────────────────────────────────
  await send('Page.navigate', { url: `${BASE}/?boot=${Date.now()}#/` })
  await sleep(1500)
  await evaluate(`(() => {
    localStorage.setItem('nabilet_customer_token', ${JSON.stringify(TOKEN)});
    localStorage.setItem('nabilet_customer_user', JSON.stringify({ id: 9, email: 'seat-buyer@example.test', first_name: 'Покупатель', last_name: 'Мест' }));
    localStorage.setItem('nabilet.theme', 'light');
    sessionStorage.removeItem('nabilet_club_celebrated');
    return 1
  })()`)
  api.length = 0
  await send('Page.navigate', { url: `${BASE}/?v=${Date.now()}#/club` })
  await waitFor(`!!document.querySelector('section[aria-label="Клуб зрителей"]')`)
  await sleep(2500)

  // ── 1. Данные на экране ───────────────────────────────────────────────────
  console.log('=== ДАННЫЕ ===')
  console.log('API:', JSON.stringify(api))
  console.log('ШАПКА:', await evaluate(`(() => {
    const s = document.querySelector('section[aria-label="Клуб зрителей"]');
    return s ? s.innerText.replace(/\\s+/g, ' ').trim() : 'нет секции';
  })()`))
  console.log('ПЛИТКИ:', await evaluate(`(() => {
    const secs = [...document.querySelectorAll('main section, section')];
    const grid = secs.find(s => s.className.includes('sm:grid-cols-3'));
    return grid ? grid.innerText.replace(/\\s+/g, ' ').trim() : 'нет';
  })()`))
  console.log('МЕДАЛИ:', await evaluate(`(() => {
    const cards = [...document.querySelectorAll('article[role="button"]')];
    const st = cards.map(c => (c.innerText.match(/Получено|Скрытая ачивка|из \\d+/) || ['?'])[0]);
    const tally = st.reduce((a, x) => (a[x] = (a[x] || 0) + 1, a), {});
    return JSON.stringify({ всего: cards.length, статусы: tally, первая: cards[0] && cards[0].innerText.replace(/\\s+/g,' ').slice(0,80) });
  })()`))
  console.log('СЕКЦИИ:', await evaluate(`JSON.stringify([...document.querySelectorAll('section[aria-label]')].map(s => s.getAttribute('aria-label')))`))
  console.log('ССЫЛКА:', await evaluate(`(() => { const i = document.querySelector('#referral-link'); return i ? i.value : 'нет поля' })()`))
  console.log('СТАТЫ:', await evaluate(`(() => {
    const s = document.querySelector('section[aria-label="Пригласите друга"]');
    if (!s) return 'нет блока';
    const dl = s.querySelector('dl');
    return dl ? dl.innerText.replace(/\\s+/g, ' ').trim() : 'нет dl';
  })()`))

  // ── 2. Копирование ссылки ─────────────────────────────────────────────────
  console.log('=== КОПИРОВАНИЕ ===')
  console.log('клик:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => x.innerText.includes('Скопировать'));
    if (!b) return 'нет кнопки';
    b.click(); return 'ok';
  })()`))
  await sleep(700)
  console.log('тост:', await evaluate(`(() => {
    const t = [...document.querySelectorAll('[role="region"][aria-live],[role="status"],[role="alert"]')].map(e => e.innerText.replace(/\\s+/g,' ').trim()).filter(Boolean);
    return JSON.stringify(t.slice(-2));
  })()`))
  console.log('подпись кнопки:', await evaluate(`[...document.querySelectorAll('button')].map(b => b.innerText.replace(/\\s+/g,' ').trim()).filter(t => /Скопирова/.test(t)).join(' | ')`))
  console.log('в буфере:', await evaluate(`navigator.clipboard.readText().then(t => t).catch(e => 'нет доступа: ' + e.name)`))

  // ── 3. Модалка медали + подложка ──────────────────────────────────────────
  console.log('=== МОДАЛКА ===')
  console.log('праздничная (открывается сама):', await evaluate(`(() => {
    const cel = [...document.querySelectorAll('[role="dialog"]')].find(d => /Новая медаль/.test(d.getAttribute('aria-label') || ''));
    if (!cel) return 'не открыта';
    const pill = [...cel.querySelectorAll('p')].find(x => x.innerText.includes('🎁'));
    const scrim = cel.parentElement.querySelector('div.absolute');
    return JSON.stringify({
      подложка: scrim ? getComputedStyle(scrim).backgroundColor : 'нет',
      плашка: pill ? getComputedStyle(pill).backgroundColor : 'нет',
      кнопки: [...cel.querySelectorAll('button')].map(b => b.innerText.replace(/\\s+/g, ' ').trim()).filter(Boolean),
    });
  })()`))
  await evaluate(`(() => { const c = document.querySelector('article[role="button"]'); if (c) c.click(); return 1 })()`)
  await sleep(700)
  console.log('подложка:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    if (!d) return 'нет модалки';
    const scrim = d.parentElement.querySelector('div[aria-hidden="true"], div.absolute');
    if (!scrim) return 'нет подложки';
    const cs = getComputedStyle(scrim);
    return JSON.stringify({ класс: scrim.className, bg: cs.backgroundColor, opacity: cs.opacity });
  })()`))
  console.log('награда-плашка:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    const p = d && [...d.querySelectorAll('p')].find(x => x.innerText.includes('🎁'));
    return p ? getComputedStyle(p).backgroundColor : 'нет';
  })()`))
  await shot('club-modal')
  console.log('кнопки:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    return d ? JSON.stringify([...d.querySelectorAll('button')].map(b => b.innerText.replace(/\\s+/g,' ').trim()).filter(Boolean)) : 'нет';
  })()`))
  console.log('→ История бонусов:', await evaluate(`(() => {
    const d = document.querySelector('[role="dialog"]');
    const b = d && [...d.querySelectorAll('button')].find(x => x.innerText.includes('История бонусов'));
    if (!b) return 'нет кнопки'; b.click(); return 'ok';
  })()`))
  await sleep(1500)
  console.log('hash:', await hash(), '| заголовок:', await evaluate(`document.querySelector('h1')?.innerText.replace(/\\s+/g,' ').slice(0,60)`))

  // ── 4. CTA «Найти событие» и «Мои розыгрыши» ──────────────────────────────
  console.log('=== ПЕРЕХОДЫ ===')
  await evaluate(`location.hash = '#/club'`)
  await waitFor(`!!document.querySelector('section[aria-label="Клуб зрителей"]')`)
  await sleep(1200)
  console.log('кнопок «Найти событие»:', await evaluate(`[...document.querySelectorAll('button')].filter(b => b.innerText.includes('Найти событие')).length`))
  console.log('клик «Найти событие»:', await evaluate(`(() => {
    const bs = [...document.querySelectorAll('button')].filter(b => b.innerText.includes('Найти событие'));
    if (!bs.length) return 'нет кнопки';
    bs[bs.length - 1].click(); return 'ok (последняя в DOM)';
  })()`))
  await sleep(1600)
  console.log('hash:', await hash(), '| h1:', await evaluate(`document.querySelector('h1')?.innerText.replace(/\\s+/g,' ').slice(0,60)`))

  await evaluate(`location.hash = '#/club'`)
  await waitFor(`!!document.querySelector('section[aria-label="Клуб зрителей"]')`)
  await sleep(1200)
  console.log('клик «Мои розыгрыши»:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => x.innerText.includes('Мои розыгрыши'));
    if (!b) return 'нет кнопки'; b.click(); return 'ok';
  })()`))
  await sleep(1600)
  console.log('hash:', await hash(), '| h1:', await evaluate(`document.querySelector('h1')?.innerText.replace(/\\s+/g,' ').slice(0,80)`))

  // ── 5. Темы ───────────────────────────────────────────────────────────────
  console.log('=== ТЕМЫ ===')
  await evaluate(`location.hash = '#/club'`)
  await waitFor(`!!document.querySelector('section[aria-label="Клуб зрителей"]')`)
  await sleep(1200)
  const themeState = `(() => {
    const s = document.querySelector('section[aria-label="Клуб зрителей"]');
    const h1 = s && s.querySelector('h1');
    const cs = s ? getComputedStyle(s) : null;
    return JSON.stringify({
      html: document.documentElement.className,
      ls: localStorage.getItem('nabilet.theme'),
      scheme: document.documentElement.style.colorScheme,
      bodyBg: getComputedStyle(document.body).backgroundColor,
      headerBgImage: cs ? cs.backgroundImage.slice(0, 46) : null,
      h1Color: h1 ? getComputedStyle(h1).color : null,
      toggle: [...document.querySelectorAll('button')].map(b => b.getAttribute('aria-label')).find(l => l && /тему/.test(l)) || 'нет переключателя',
    })
  })()`
  console.log('светлая:', await evaluate(themeState))
  await shot('club-light')
  console.log('клик по переключателю:', await evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /Включить (светлую|тёмную) тему/.test(x.getAttribute('aria-label') || ''));
    if (!b) return 'нет кнопки'; b.click(); return 'ok';
  })()`))
  await sleep(900)
  console.log('тёмная:', await evaluate(themeState))
  await shot('club-dark')
  await send('Page.reload'); await sleep(2600)
  console.log('после перезагрузки:', await evaluate(`JSON.stringify({ html: document.documentElement.className, ls: localStorage.getItem('nabilet.theme'), scheme: document.documentElement.style.colorScheme })`))

  // ── 6. Просроченная сессия ────────────────────────────────────────────────
  console.log('=== ПРОСРОЧЕННЫЙ ТОКЕН ===')
  await evaluate(`localStorage.setItem('nabilet_customer_token', 'deadbeef'.repeat(16))`)
  api.length = 0
  await send('Page.navigate', { url: `${BASE}/?s=${Date.now()}#/club` })
  await waitFor(`!!document.querySelector('section[aria-label="Клуб зрителей"]')`, 20000)
  await sleep(2500)
  console.log('API:', JSON.stringify(api))
  console.log('hash:', await hash())
  console.log('шапка:', await evaluate(`document.querySelector('section[aria-label="Клуб зрителей"]')?.innerText.replace(/\\s+/g,' ').slice(0, 260) ?? 'нет'`))
  console.log('токен остался:', await evaluate(`localStorage.getItem('nabilet_customer_token') ? 'да' : 'нет (стёрт)'`))

  console.log('ОШИБКИ КОНСОЛИ:', JSON.stringify(errors.slice(0, 6)))
  ws.close()
}

main().catch((e) => { console.error('FATAL', e); process.exit(1) })
