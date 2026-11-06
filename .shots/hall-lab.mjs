/**
 * Прогон конструктора схем залов по реальному API (тестовая БД nabilet_test_suite).
 *
 * Проверяем, что можно собрать:
 *   1) концертный зал произвольной конфигурации — партер-сетка + амфитеатр-дуга
 *      + балкон + стоячая фан-зона (staticObjects: stage/entrance/label/standing);
 *   2) площадку с местами за столами (банкет) — сектора shape='table' с кольцом
 *      мест, по цене за стол;
 *   3) зал «только декорации» (stage/table-объекты, ни одного места) — должен
 *      быть отвергнут на публикации как пустой (SCHEMA_EMPTY_PAYLOAD).
 *
 * Каждый вариант шлётся в ДВУХ формах координат, потому что это разные
 * конвенции одного и того же payload:
 *   • «редакторская» — места в ЛОКАЛЬНЫХ координатах сектора + sector.x/y
 *     (именно так их формирует HallEditorPage.vue::buildSchemaPayload);
 *   • «абсолютная» — места уже в координатах холста.
 *
 * Запуск: node .shots/hall-lab.mjs
 */
const BASE = 'http://127.0.0.1:8099/api/v1'
const SEAT = 16
const GAP = 6
const ROW_GAP = 12

import { writeFileSync } from 'node:fs'

let token = null
const results = []

function log(step, ok, extra = '') {
  const mark = ok === null ? '•' : ok ? 'PASS' : 'FAIL'
  results.push({ step, ok, extra })
  console.log(`[${mark}] ${step}${extra ? ' — ' + extra : ''}`)
}

async function api(method, path, body) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const text = await res.text()
  let json = null
  try { json = text ? JSON.parse(text) : null } catch { /* keep raw */ }
  return { status: res.status, json, text }
}

/* ── геометрия: те же формулы, что в resources/js/pages/hall-editor ── */

function gridSeats(rows, perRow, offX, offY) {
  const seats = []
  for (let r = 0; r < rows; r++) {
    for (let n = 0; n < perRow; n++) {
      seats.push({
        id: `seat-${offX}-${offY}-${r}-${n}`,
        row: r + 1,
        number: n + 1,
        kind: r < 1 ? 'vip' : 'standard',
        x: offX + n * (SEAT + GAP),
        y: offY + r * (SEAT + ROW_GAP),
      })
    }
  }
  return seats
}

function arcLayoutParams(rows, perRow, spreadDeg) {
  const theta = (spreadDeg * Math.PI) / 180
  const rowGap = SEAT + ROW_GAP
  const R0 = perRow > 1 ? ((perRow - 1) * (SEAT + GAP)) / theta : 60
  const maxR = R0 + (rows - 1) * rowGap
  return {
    arcBaseR: R0,
    arcRowGap: rowGap,
    arcOffsetX: maxR * Math.sin(theta / 2),
    arcOffsetY: -R0 * Math.cos(theta / 2),
  }
}

function arcSeats(rows, perRow, spreadDeg, offX, offY) {
  const p = arcLayoutParams(rows, perRow, spreadDeg)
  const theta = (spreadDeg * Math.PI) / 180
  const seats = []
  for (let r = 0; r < rows; r++) {
    for (let n = 0; n < perRow; n++) {
      const R = p.arcBaseR + r * p.arcRowGap
      const a = perRow > 1 ? -theta / 2 + (n * theta) / (perRow - 1) : 0
      seats.push({
        id: `arc-${offX}-${offY}-${r}-${n}`,
        row: r + 1,
        number: n + 1,
        kind: n === 0 || n === perRow - 1 ? 'accessible' : 'standard',
        x: Math.round(offX + R * Math.sin(a) + p.arcOffsetX),
        y: Math.round(offY + R * Math.cos(a) + p.arcOffsetY),
      })
    }
  }
  return seats
}

/** Банкетный стол: кольцо мест (seatGeometry.buildTableSeats). */
function tableSeats(count, offX, offY) {
  const r = Math.max(48, (count * (SEAT + GAP)) / (2 * Math.PI))
  const cx = r + SEAT + 10
  const cy = r + SEAT + 10
  const seats = []
  for (let i = 0; i < count; i++) {
    const a = -Math.PI / 2 + (i * 2 * Math.PI) / count
    seats.push({
      id: `tbl-${offX}-${offY}-${i}`,
      row: 1,
      number: i + 1,
      kind: 'standard',
      x: Math.round(offX + cx + r * Math.cos(a) - SEAT / 2),
      y: Math.round(offY + cy + r * Math.sin(a) - SEAT / 2),
    })
  }
  return seats
}

function sector(o) {
  return {
    id: o.id,
    name: o.name,
    x: o.x,
    y: o.y,
    priceMinor: o.priceMinor,
    type: o.type ?? 'seated',
    rowPrices: o.rowPrices ?? {},
    shape: o.shape ?? 'grid',
    arcSpread: o.arcSpread ?? 0,
    arcBaseR: o.arcBaseR ?? 0,
    arcRowGap: o.arcRowGap ?? 0,
    arcOffsetX: o.arcOffsetX ?? 0,
    arcOffsetY: o.arcOffsetY ?? 0,
    seats: o.seats,
  }
}

/** Превратить абсолютные координаты мест в локальные (конвенция редактора). */
function toEditorLocal(sectors) {
  return sectors.map((s) => ({
    ...s,
    seats: s.seats.map((seat) => ({ ...seat, x: seat.x - s.x, y: seat.y - s.y })),
  }))
}

function firstError(res) {
  const j = res.json
  if (!j) return res.text.slice(0, 200)
  const fields = j?.error?.details?.fields ?? j?.details?.fields ?? null
  if (fields) {
    const [k, v] = Object.entries(fields)[0] ?? []
    return `${j?.error?.code ?? j?.code ?? ''} ${k}: ${Array.isArray(v) ? v[0] : v}`
  }
  return `${j?.error?.code ?? j?.code ?? ''} ${j?.error?.message ?? j?.message ?? ''}`.trim()
}

/** Создать зал, сохранить черновик и опубликовать. */
async function createHallWithSchema(venueId, hallName, payload) {
  const hallRes = await api('POST', '/halls', { venue_id: venueId, name: hallName, capacity: 0 })
  const hallPublicId = hallRes.json?.data?.public_id
  if (!hallPublicId) return { ok: false, err: `hall not created: ${hallRes.text.slice(0, 200)}` }

  const d = await api('POST', `/halls/${hallPublicId}/schema-versions/draft`, { payload })
  if (d.status >= 400) return { ok: false, hallPublicId, err: `draft HTTP ${d.status} ${firstError(d)}` }
  const versionId = d.json?.data?.id ?? d.json?.id

  const p = await api('POST', `/halls/${hallPublicId}/schema-versions/${versionId}/publish`)
  if (p.status >= 400) return { ok: false, hallPublicId, versionId, err: `publish HTTP ${p.status} ${firstError(p)}` }

  return { ok: true, hallPublicId, versionId }
}

/* ────────────────────────────── сценарий ────────────────────────────── */

const run = async () => {
  console.log('=== 0. Логин и площадка (тестовая БД nabilet_test_suite) ===')
  let res = await api('POST', '/auth/login', { email: 'admin@nabilet.local', password: 'admin123' })
  if (res.status !== 200) throw new Error('login failed: ' + res.status + ' ' + res.text.slice(0, 300))
  token = res.json.token ?? res.json.access_token ?? res.json?.data?.token
  log('POST /auth/login', !!token, token ? 'токен получен' : JSON.stringify(res.json).slice(0, 200))

  const stamp = Date.now().toString().slice(-6)
  res = await api('POST', '/venues', {
    name: `Тестовая площадка «Конструктор залов ${stamp}»`,
    city: 'Москва',
    address: 'ул. Тестовая, 1',
  })
  const venueId = res.json?.data?.id
  log('POST /venues', res.status === 201 && !!venueId, `venue_id=${venueId}`)
  if (!venueId) throw new Error('venue not created: ' + res.text.slice(0, 300))

  /* ── Сценарий 1: концертный зал произвольной конфигурации ── */

  const stage = { id: 'st-stage', kind: 'stage', x: 120, y: 30, width: 400, height: 70, opacity: 1 }
  const entrance = { id: 'st-entrance', kind: 'entrance', x: 20, y: 300, width: 40, height: 60, opacity: 1 }
  const label = { id: 'st-label', kind: 'label', x: 300, y: 110, text: 'СЦЕНА', opacity: 1 }
  const fanZone = { id: 'st-fan', kind: 'standing', x: 420, y: 300, width: 260, height: 160, text: 'Танцпол', capacity: 150, priceMinor: 200000, opacity: 1 }

  const concertAbs = [
    sector({
      id: 'sec-parter', name: 'Партер', x: 80, y: 140, priceMinor: 350000, shape: 'grid',
      seats: gridSeats(10, 12, 80, 140), rowPrices: { 1: 500000, 2: 450000 },
    }),
    sector({
      id: 'sec-amphi', name: 'Амфитеатр', x: 520, y: 150, priceMinor: 280000, shape: 'arc',
      arcSpread: 140, ...arcLayoutParams(8, 16, 140),
      seats: arcSeats(8, 16, 140, 520, 150),
    }),
    sector({
      id: 'sec-balkon', name: 'Балкон', x: 80, y: 480, priceMinor: 180000, shape: 'grid',
      seats: gridSeats(4, 8, 80, 480),
    }),
  ]
  const concertBase = {
    version: '1.0',
    canvas: { width: 900, height: 620 },
    background: null,
    staticObjects: [stage, entrance, label, fanZone],
  }

  console.log('\n=== 1. Концертный зал: партер + амфитеатр-дуга + балкон + танцпол ===')
  const concertEditor = await createHallWithSchema(venueId, 'Большой зал (формат редактора)', {
    ...concertBase, sectors: toEditorLocal(concertAbs),
  })
  log(
    'Концертный зал — payload в формате редактора (локальные координаты мест)',
    concertEditor.ok,
    concertEditor.ok ? `hall=${concertEditor.hallPublicId}` : concertEditor.err,
  )

  const concertAbsRes = await createHallWithSchema(venueId, 'Большой зал (абсолютные координаты)', {
    ...concertBase, sectors: concertAbs,
  })
  log(
    'Концертный зал — payload с абсолютными координатами мест',
    concertAbsRes.ok,
    concertAbsRes.ok ? `hall=${concertAbsRes.hallPublicId}` : concertAbsRes.err,
  )

  /* ── Сценарий 2: банкет — места за столами ── */

  console.log('\n=== 2. Банкетный зал: 6 столов, места по кольцу ===')
  const tableCounts = [8, 8, 10, 6, 12, 8]
  const tables = tableCounts.map((n, i) => {
    const col = i % 3
    const row = Math.floor(i / 3)
    const sx = 120 + col * 240
    const sy = 160 + row * 260
    return sector({
      id: `sec-table-${i}`, name: `Стол ${i + 1}`, x: sx, y: sy, priceMinor: 900000,
      shape: 'table', seats: tableSeats(n, sx, sy),
    })
  })
  const banquetBase = {
    version: '1.0',
    canvas: { width: 900, height: 700 },
    background: null,
    staticObjects: [stage, { id: 'st-b1', kind: 'entrance', x: 420, y: 640, width: 60, height: 40, opacity: 1 }],
  }

  const banquetEditor = await createHallWithSchema(venueId, 'Банкетный зал (формат редактора)', {
    ...banquetBase, sectors: toEditorLocal(tables),
  })
  log(
    'Банкет — payload в формате редактора (локальные координаты мест)',
    banquetEditor.ok,
    banquetEditor.ok ? `hall=${banquetEditor.hallPublicId}` : banquetEditor.err,
  )

  const banquetAbsRes = await createHallWithSchema(venueId, 'Банкетный зал (абсолютные координаты)', {
    ...banquetBase, sectors: tables,
  })
  log(
    'Банкет — payload с абсолютными координатами мест',
    banquetAbsRes.ok,
    banquetAbsRes.ok ? `hall=${banquetAbsRes.hallPublicId}` : banquetAbsRes.err,
  )

  /* ── Сценарий 3: зал «только декорации» — продавать нечего ── */

  console.log('\n=== 3. Зал только из декораций (stage + столы-объекты) ===')
  const decorOnly = {
    version: '1.0',
    canvas: { width: 900, height: 620 },
    background: null,
    sectors: [],
    staticObjects: [
      { id: 'd1', kind: 'table', x: 200, y: 200, width: 120, height: 80, opacity: 1 },
      { id: 'd2', kind: 'table', x: 400, y: 200, width: 120, height: 80, opacity: 1 },
      { id: 'd3', kind: 'stage', x: 300, y: 40, width: 300, height: 60, opacity: 1 },
    ],
  }
  const decorRes = await createHallWithSchema(venueId, 'Зал-декорация (только объекты)', decorOnly)
  log(
    'Зал только из декораций — публикация отклонена кодом SCHEMA_EMPTY_PAYLOAD',
    !decorRes.ok && /SCHEMA_EMPTY_PAYLOAD/.test(decorRes.err ?? ''),
    decorRes.ok ? 'ОШИБКА: опубликован зал без продаваемых позиций' : decorRes.err,
  )

  /* ── Сценарий 4: сквозной путь до продажи (событие → сеанс → инвентарь) ── */

  console.log('\n=== 4. Сквозной путь: событие → сеанс → инвентарь из схемы ===')
  res = await api('POST', '/events', {
    slug: `hall-lab-${stamp}`, title: 'Тестовый концерт (конструктор залов)', status: 'published',
  })
  const eventId = res.json?.data?.id
  log('POST /events', res.status === 201 && !!eventId, `event_id=${eventId}`)

  const session = async (hallPublicId, startsAt, label, expected) => {
    const hallRow = await api('GET', `/halls/${hallPublicId}`)
    const r = await api('POST', '/sessions', {
      event_id: eventId, hall_id: hallRow.json?.data?.id, starts_at: startsAt,
    })
    if (r.status !== 201) {
      log(`POST /sessions (${label}) → генерация инвентаря`, false, `HTTP ${r.status} ${firstError(r)}`)
      return null
    }
    const sid = r.json?.data?.id
    const inv = await api('GET', `/inventory/sessions/${sid}/availability`)
    const d = inv.json?.data ?? {}
    log(
      `POST /sessions (${label}) → генерация инвентаря`,
      d.total === expected,
      `позиций=${d.total} (ожидалось ${expected})`,
    )
    return sid
  }

  // Концертный зал: 280 мест (10×12 + 8×16 + 4×8) + 1 стоячая зона
  // (capacity 150 — одна позиция инвентаря на всю фан-зону, так устроен
  // inventory_items для standing) = 281 позиция.
  const concertSessionId = await session(concertEditor.hallPublicId, '2026-12-01T19:00:00+03:00', 'концертный', 281)
  // Банкет: 8+8+10+6+12+8 = 52 места за 6 столами.
  const banquetSessionId = await session(banquetEditor.hallPublicId, '2026-12-02T19:00:00+03:00', 'банкет', 52)

  // Артефакт для скриншот-прогона (CDP): слаги/сеансы/залы последнего запуска.
  writeFileSync(
    '.shots/hall-lab-last.json',
    JSON.stringify({
      eventSlug: `hall-lab-${stamp}`,
      eventId,
      venueId,
      concertHall: concertEditor.hallPublicId,
      banquetHall: banquetEditor.hallPublicId,
      concertSession: concertSessionId,
      banquetSession: banquetSessionId,
    }, null, 2),
  )

  /* ── Что реально лежит в опубликованных схемах ── */

  console.log('\n=== Опубликованные схемы (как их увидит витрина) ===')
  for (const [label, hallPublicId] of [
    ['концертный', concertEditor.hallPublicId],
    ['банкетный', banquetEditor.hallPublicId],
  ]) {
    if (!hallPublicId) continue
    const r = await api('GET', `/halls/${hallPublicId}/schema`)
    const secs = r.json?.data?.schema?.sectors ?? []
    console.log(`  ${label} (${hallPublicId}):`)
    for (const s of secs) {
      const perRow = {}
      for (const seat of s.seats ?? []) perRow[seat.row] = (perRow[seat.row] ?? 0) + 1
      const rows = Object.keys(perRow).sort((a, b) => a - b)
      console.log(
        `    • ${s.name}: shape=${s.shape}, type=${s.type}, priceMinor=${s.priceMinor}, ` +
        `мест=${(s.seats ?? []).length}, рядов=${rows.length}, в ряду=${rows.length ? perRow[rows[0]] : 0}, ` +
        `x/y=${s.x}/${s.y}, rowPrices=${JSON.stringify(s.rowPrices ?? {})}`,
      )
    }
    const st = r.json?.data?.schema?.staticObjects ?? []
    console.log(`    статика: ${st.map((o) => o.kind + (o.capacity ? `(${o.capacity})` : '')).join(', ')}`)
  }

  /* ── Сводка ── */
  console.log('\n=== СВОДКА ===')
  const failed = results.filter((r) => r.ok === false)
  for (const r of results) console.log(`${r.ok === null ? '•' : r.ok ? '✓' : '✗'} ${r.step}${r.extra ? ' — ' + r.extra : ''}`)
  console.log(`\nИтого: ${results.length - failed.length}/${results.length} проверок пройдено`)
}

run().catch((e) => {
  console.error('FATAL', e)
  process.exit(1)
})
