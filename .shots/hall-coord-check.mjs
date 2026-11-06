/**
 * Проверка координатной карты зала (CoordSeatMap) для многопозиционного зала.
 *
 * Витрина (`SeatSelectionPage` → `CoordSeatMap`) рисует места по `seat.x/seat.y`,
 * которые кладёт `HallSchemaVersion::toInventoryFormat()`: он нормирует координаты
 * места как `x / canvasWidth * 60`. Смещение сектора (`sector.x/y`) при этом НЕ
 * прибавляется — значит, сектора, разнесённые по холсту, в нормированном
 * пространстве начинаются с одной и той же точки.
 *
 * Скрипт печатает bbox каждого сектора в нормированных координатах и считает,
 * пересекаются ли они.
 *
 * Запуск: node .shots/hall-coord-check.mjs
 */
const BASE = 'http://127.0.0.1:8099/api/v1'
const SEAT = 16
const GAP = 6
const ROW_GAP = 12

let token = null

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
  try { json = text ? JSON.parse(text) : null } catch { /* raw */ }
  return { status: res.status, json, text }
}

function gridSeats(rows, perRow, offX, offY) {
  const seats = []
  for (let r = 0; r < rows; r++) {
    for (let n = 0; n < perRow; n++) {
      seats.push({
        id: `s-${offX}-${offY}-${r}-${n}`, row: r + 1, number: n + 1, kind: 'standard',
        x: offX + n * (SEAT + GAP), y: offY + r * (SEAT + ROW_GAP),
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
  return { arcBaseR: R0, arcRowGap: rowGap, arcOffsetX: maxR * Math.sin(theta / 2), arcOffsetY: -R0 * Math.cos(theta / 2) }
}

function arcSeats(rows, perRow, spreadDeg, offX, offY) {
  const p = arcLayoutParams(rows, perRow, spreadDeg)
  const theta = (spreadDeg * Math.PI) / 180
  const seats = []
  for (let r = 0; r < rows; r++) {
    for (let n = 0; n < perRow; n++) {
      const R = p.arcBaseR + r * p.arcRowGap
      const a = -theta / 2 + (n * theta) / (perRow - 1)
      seats.push({
        id: `a-${offX}-${offY}-${r}-${n}`, row: r + 1, number: n + 1, kind: 'standard',
        x: Math.round(offX + R * Math.sin(a) + p.arcOffsetX), y: Math.round(offY + R * Math.cos(a) + p.arcOffsetY),
      })
    }
  }
  return seats
}

const run = async () => {
  let res = await api('POST', '/auth/login', { email: 'admin@nabilet.local', password: 'admin123' })
  token = res.json.token ?? res.json.access_token ?? res.json?.data?.token
  const stamp = Date.now().toString().slice(-6)

  res = await api('POST', '/venues', { name: `Координаты ${stamp}`, city: 'Москва' })
  const venueId = res.json?.data?.id

  res = await api('POST', '/halls', { venue_id: venueId, name: 'Большой зал', capacity: 0 })
  const hall = res.json?.data?.public_id

  const canvas = { width: 900, height: 620 }
  const abs = [
    { id: 'p', name: 'Партер', x: 80, y: 140, priceMinor: 350000, type: 'seated', shape: 'grid', rowPrices: {}, arcSpread: 0, arcBaseR: 0, arcRowGap: 0, arcOffsetX: 0, arcOffsetY: 0, seats: gridSeats(10, 12, 80, 140) },
    { id: 'a', name: 'Амфитеатр', x: 520, y: 150, priceMinor: 280000, type: 'seated', shape: 'arc', rowPrices: {}, arcSpread: 140, ...arcLayoutParams(8, 16, 140), seats: arcSeats(8, 16, 140, 520, 150) },
    { id: 'b', name: 'Балкон', x: 80, y: 480, priceMinor: 180000, type: 'seated', shape: 'grid', rowPrices: {}, arcSpread: 0, arcBaseR: 0, arcRowGap: 0, arcOffsetX: 0, arcOffsetY: 0, seats: gridSeats(4, 8, 80, 480) },
  ]
  // Как их реально шлёт редактор: места — локальные, смещение — в sector.x/y.
  const local = abs.map((s) => ({ ...s, seats: s.seats.map((k) => ({ ...k, x: k.x - s.x, y: k.y - s.y })) }))

  const payload = {
    version: '1.0', canvas, background: null, sectors: local,
    staticObjects: [
      { id: 'st', kind: 'stage', x: 120, y: 30, width: 400, height: 70, opacity: 1 },
      { id: 'fan', kind: 'standing', x: 420, y: 300, width: 260, height: 160, text: 'Танцпол', capacity: 150, priceMinor: 200000, opacity: 1 },
    ],
  }

  res = await api('POST', `/halls/${hall}/schema-versions/draft`, { payload })
  const vid = res.json?.data?.id
  res = await api('POST', `/halls/${hall}/schema-versions/${vid}/publish`)
  console.log('publish:', res.status)

  res = await api('POST', '/events', { slug: `coord-${stamp}`, title: 'Координатный тест', status: 'published' })
  const eventId = res.json?.data?.id
  const hallRow = await api('GET', `/halls/${hall}`)
  res = await api('POST', '/sessions', { event_id: eventId, hall_id: hallRow.json?.data?.id, starts_at: '2026-12-05T19:00:00+03:00' })
  const sid = res.json?.data?.id
  console.log('session:', sid, res.status)

  const inv = await api('GET', `/inventory?session_id=${sid}&per_page=400&page=1`)
  const list = Array.isArray(inv.json?.data) ? inv.json.data : (inv.json?.data?.data ?? [])
  console.log('инвентарь позиций:', list.length)

  const bySector = new Map()
  for (const it of list) {
    if (it.type !== 'seat' || !it.seat) continue
    const name = it.seat?.sector ?? it.seat?.sector_name ?? '?'
    const b = bySector.get(name) ?? { minX: Infinity, maxX: -Infinity, minY: Infinity, maxY: -Infinity, n: 0 }
    const x = Number(it.seat.x ?? 0)
    const y = Number(it.seat.y ?? 0)
    b.minX = Math.min(b.minX, x); b.maxX = Math.max(b.maxX, x)
    b.minY = Math.min(b.minY, y); b.maxY = Math.max(b.maxY, y)
    b.n += 1
    bySector.set(name, b)
  }

  console.log('\nbbox секторов в НОРМИРОВАННЫХ координатах (то, что рисует CoordSeatMap):')
  const entries = [...bySector.entries()]
  for (const [name, b] of entries) {
    console.log(`  ${name.padEnd(14)} x=${b.minX}..${b.maxX}  y=${b.minY}..${b.maxY}  мест=${b.n}`)
  }

  console.log('\nпопарные пересечения bbox:')
  let collisions = 0
  for (let i = 0; i < entries.length; i++) {
    for (let j = i + 1; j < entries.length; j++) {
      const [na, a] = entries[i]
      const [nb, b] = entries[j]
      const overlap = a.minX <= b.maxX && b.minX <= a.maxX && a.minY <= b.maxY && b.minY <= a.maxY
      if (overlap) { collisions++; console.log(`  ✗ ${na} ∩ ${nb} — сектора накладываются`) }
      else console.log(`  ✓ ${na} / ${nb} — не пересекаются`)
    }
  }
  console.log(`\nвывод: ${collisions === 0 ? 'сектора разнесены корректно' : `НАЙДЕНО ${collisions} наложений — карта зала «слепит» сектора`}`)
}

run().catch((e) => { console.error('FATAL', e); process.exit(1) })
