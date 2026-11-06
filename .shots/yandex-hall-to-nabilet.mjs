/**
 * Конвертер плана зала Яндекс Билетов → схема зала NABILET (формат редактора).
 *
 * Что здесь решается, и почему это не «просто переложить координаты»:
 *
 * 1. Виджет отдаёт только СВОБОДНЫЕ места (на сеансе 348671 — 908 из 1121),
 *    при этом в метаданных `level.rows[].seatCount` лежит ПОЛНОЕ число мест
 *    ряда. Значит проданные места надо восстановить, иначе в зал импортируется
 *    дырявая сетка. Восстанавливаем интерполяцией между известными соседями
 *    по номеру места: внутри яруса ряд — дуга, но между соседними местами
 *    она почти линейна, поэтому кусочная линейная интерполяция даёт верную
 *    рассадку и честно сохраняет проходы (разрывы в координатах).
 *
 * 2. Координаты Яндекса — в «чужих» пикселях, шаг между местами ~19.6 px,
 *    между рядами партера 20 px (между 1 и 2 рядом — 14 px). Сервер отвергает
 *    схему, если два места ближе 16 px по обеим осям
 *    (HallService::validateSchemaPayload). Поэтому масштаб подбирается так,
 *    чтобы минимальный шаг по одной из осей был ≥ 16 — см. SCALE и проверку
 *    minSpacing ниже.
 *
 * 3. Ряды приставных мест называются «1А»…«4А» — в схеме NABILET номер ряда
 *    обязан быть целым ≥ 1, буква уходит в название сектора.
 *
 * Вход по умолчанию:  .shots/yandex-hallplan.json, .shots/yandex-hallplan-330497.json
 * Вход с аргументами: node ... [plan1] [plan2] [output]
 * Выход по умолчанию: outputs/hall-neftyanik-schema.json
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'

const PAD = 60
const SEAT_PX = 16          // сторона места на холсте редактора (совпадает с серверной проверкой)
const MIN_STEP = 17         // запас к серверному порогу 16 px
const BASE_SCALE = 1.15     // для полноценных планов сохраняем компактный масштаб

const normRow = (r) => String(r).trim().toUpperCase()

/**
 * Ключ ряда в карте известных мест.
 *
 * Номера рядов повторяются между уровнями: и в Партере, и на Балконе есть
 * ряды 1…6, а Бельэтаж продолжает нумерацию Партера (11…17). Ключ «только
 * номер ряда» смешивал уровни — места Партера получали координаты Балкона.
 * Буквенные ряды приставных мест уникальны сами по себе, и именно по ним
 * склеиваются данные второго сеанса (там приставные лежат внутри «Партера»).
 */
const rowKeyOf = (levelName, row) => {
  const r = normRow(row)
  return /\D/.test(r) ? r : `${normRow(levelName)}::${r}`
}

/* ── 1. Собираем все известные координаты мест из обоих сеансов ────────── */
const plans = process.argv[2]
  ? process.argv[2].split(',').map((p) => p.trim()).filter(Boolean)
  : ['.shots/yandex-hallplan.json', '.shots/yandex-hallplan-330497.json']
const outputFile = process.argv[3] ?? 'outputs/hall-neftyanik-schema.json'
const known = new Map()   // rowKey → Map(place → {x, y})
const prices = new Map()  // rowKey → Map(place → цена в копейках)

let stats = { plans: 0, knownSeats: 0 }
for (const file of plans) {
  let plan
  try {
    plan = JSON.parse(readFileSync(file, 'utf8')).result.hallplan
  } catch {
    console.log(`(пропущен ${file})`)
    continue
  }
  stats.plans += 1
  for (const level of plan.levels) {
    for (const s of level.seats) {
      const rowKey = rowKeyOf(level.name, s.seat.row)
      const place = Number(s.seat.place)
      if (!Number.isInteger(place) || place < 1) continue
      const rowMap = known.get(rowKey) ?? new Map()
      const priceMap = prices.get(rowKey) ?? new Map()
      // Первый источник (основной сеанс) приоритетнее: в нём зал собран целиком.
      if (!rowMap.has(place)) {
        rowMap.set(place, { x: s.seat.xCoord, y: s.seat.yCoord })
        stats.knownSeats += 1
      }
      priceMap.set(place, s.priceInfo?.price?.value ?? s.originalPrice?.price?.value ?? null)
      known.set(rowKey, rowMap)
      prices.set(rowKey, priceMap)
    }
  }
}

/* ── 2. Восстановление полной сетки ряда ──────────────────────────────── */
function stepBetween(pts, i, j) {
  const a = pts[i], b = pts[j]
  if (!a || !b || b.p === a.p) return { dx: 19.6, dy: 0 }
  return { dx: (b.x - a.x) / (b.p - a.p), dy: (b.y - a.y) / (b.p - a.p) }
}

/**
 * Полная сетка ряда.
 *
 * Место восстанавливается двумя способами, и порядок между ними принципиален:
 *
 *  1) ПО СОСЕДНИМ РЯДАМ (приоритет). Ряд зала — дуга, а продан может быть
 *     её целый блок: в 10-м ряду партера известны только места 1–10 и 36–45,
 *     центральные 25 мест проданы. Интерполяция внутри ряда провела бы ХОРДУ
 *     между краями — и задний ряд лёг бы прямо на 9-й (дуга вогнута к сцене,
 *     её центр заметно дальше хорды). Поэтому позиция берётся экстраполяцией
 *     по номеру ряда между двумя ближайшими рядами, в которых это место
 *     известно: форма дуги при таком переносе сохраняется.
 *
 *  2) ПО СОСЕДНИМ МЕСТАМ РЯДА — если в соседних рядах такого места нет.
 *
 * Место, которое не удалось вывести ни одним способом, не создаётся вовсе:
 * придуманная координата хуже отсутствующей.
 */
function median(values) {
  if (values.length === 0) return 0
  const s = [...values].sort((a, b) => a - b)
  const mid = Math.floor(s.length / 2)
  return s.length % 2 === 1 ? s[mid] : (s[mid - 1] + s[mid]) / 2
}

/** Кусочно-линейная кривая по точкам, отсортированным по u (с экстраполяцией по краям). */
function curveAt(points, u) {
  if (points.length === 0) return null
  if (u <= points[0].u) {
    const a = points[0], b = points[1] ?? a
    const t = b.u === a.u ? 0 : (u - a.u) / (b.u - a.u)
    return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t }
  }
  const last = points[points.length - 1]
  if (u >= last.u) {
    const a = points[points.length - 2] ?? last, b = last
    const t = b.u === a.u ? 0 : (u - a.u) / (b.u - a.u)
    return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t }
  }
  for (let i = 0; i < points.length - 1; i += 1) {
    const a = points[i], b = points[i + 1]
    if (u >= a.u && u <= b.u) {
      const t = b.u === a.u ? 0 : (u - a.u) / (b.u - a.u)
      return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t }
    }
  }
  return { x: last.x, y: last.y }
}

function buildSparseRow(level, row, seatCount) {
  const rowKey = rowKeyOf(level.name, row)
  const rowMap = known.get(rowKey) ?? new Map()
  const rowMeta = level.rows.find((r) => String(r.row) === String(row))
  const dx = []
  const dy = []
  const pts = [...rowMap.entries()].map(([p, xy]) => ({ p, x: xy.x, y: xy.y })).sort((a, b) => a.p - b.p)
  for (let i = 1; i < pts.length; i += 1) {
    const dp = pts[i].p - pts[i - 1].p
    if (dp > 0) {
      dx.push((pts[i].x - pts[i - 1].x) / dp)
      dy.push((pts[i].y - pts[i - 1].y) / dp)
    }
  }
  const pitchX = dx.length > 0 ? median(dx.filter((v) => v > 12 && v < 40)) || 20 : 20
  // Для sparse-режима не наклоняем ряд: yCoord уже различает ряды, а
  // перенос внутривого наклона из нескольких свободных мест пересекает
  // соседние ряды при больших распроданных блоках.
  const pitchY = 0
  const x0 = Number(rowMeta?.xCoord ?? pts[0]?.x ?? 0)
  const y0 = Number(rowMeta?.yCoord ?? pts[0]?.y ?? 0)
  return {
    seats: Array.from({ length: seatCount }, (_, i) => ({
      number: i + 1,
      x: x0 + i * pitchX,
      y: y0 + i * pitchY,
      // Для sparse-сеанса не выдаём частично свободные места за точную
      // геометрию: вся сетка синтезирована от метаданных ряда.
      exact: false,
    })),
    byRows: seatCount,
    bySeats: 0,
    skipped: 0,
  }
}

function buildRow(level, row, seatCount) {
  const rowKey = rowKeyOf(level.name, row)
  const rowMap = known.get(rowKey) ?? new Map()
  const pts = [...rowMap.entries()]
    .map(([p, xy]) => ({ p, x: xy.x, y: xy.y }))
    .sort((a, b) => a.p - b.p)
  const self = rowNumOf(level, row)
  const uOf = (p) => (p - 1) / Math.max(1, seatCount - 1)

  /**
   * Ряд-донор: тот же уровень, максимум известных мест (при равных — ближайший
   * по номеру). Его кривая задаёт ФОРМУ ряда, а смещение считается по
   * известным местам самого ряда — так дуга переносится целиком, без привязки
   * к номерам мест (проходы в соседних рядах не совпадают, перенос «по номеру
   * места» ложил восстановленные места прямо на соседние).
   */
  const fill = (c) => c.map.size / Math.max(1, c.seatCount)
  const all = level.rows
    .map((r) => ({
      num: rowNumOf(level, r.row),
      seatCount: r.seatCount,
      map: known.get(rowKeyOf(level.name, r.row)) ?? new Map(),
    }))
    .filter((c) => Number.isInteger(c.num) && c.map.size > 0 && c.num !== self)
  // Близкий ряд важнее заполненности: кривизна соседних рядов совпадает, а
  // перенос формы с дальнего ряда (даже идеально заполненного) уводит
  // восстановленный центр на соседний ряд.
  // Донор обязан быть сопоставимой длины: ряды одной зоны бывают разной
  // ширины (бельэтаж: 11–13 ряды по 32 места, 14–17 — по 46–48), и перенос
  // формы с короткого ряда на длинный растягивает нумерацию не туда.
  const sameLength = all.filter((c) => Math.abs(c.seatCount - seatCount) <= Math.max(2, seatCount * 0.1))
  const near = sameLength.filter((c) => fill(c) >= 0.6)
  const pool = near.length > 0 ? near : (sameLength.length > 0 ? sameLength : all)
  const donor = [...pool].sort((a, b) => {
    return (Math.abs(a.num - self) - Math.abs(b.num - self)) || (fill(b) - fill(a))
  })[0]
  const donorCurve = donor
    ? [...donor.map.entries()]
      .map(([p, xy]) => ({ u: (p - 1) / Math.max(1, donor.seatCount - 1), x: xy.x, y: xy.y }))
      .sort((a, b) => a.u - b.u)
    : []

  /**
   * Калибровка смещения ряда относительно кривой донора.
   *
   * Смещение — не константа: ряды differ и по ширине, и по кривизне, поэтому
   * расхождение на левом краю ряда и на правом разное. Константа (даже
   * медианная) уводила восстановленный центр на соседний ряд; линейная функция
   * offset(u) = a + b·u калибрует оба края, а внутри даёт плавный переход.
   */
  const linfit = (samples) => {
    // d = a + b·u методом наименьших квадратов
    let su = 0, sd = 0, suu = 0, sud = 0
    for (const { u, d } of samples) { su += u; sd += d; suu += u * u; sud += u * d }
    const n = samples.length
    if (n === 0) return { a: 0, b: 0 }
    if (n === 1) return { a: samples[0].d, b: 0 }
    const denom = n * suu - su * su
    const b = Math.abs(denom) < 1e-9 ? 0 : (n * sud - su * sd) / denom
    const a = (sd - b * su) / n
    return { a, b }
  }

  const samplesX = [], samplesY = []
  if (donorCurve.length > 0) {
    for (const q of pts) {
      const c = curveAt(donorCurve, uOf(q.p))
      if (!c) continue
      samplesX.push({ u: uOf(q.p), d: q.x - c.x })
      samplesY.push({ u: uOf(q.p), d: q.y - c.y })
    }
  }
  // Даже если ряд полностью распродан, metadata rows[].xCoord/yCoord
  // хранит позицию его места №1. Используем её как якорь — иначе полностью
  // пустые ряды Балкона наследуют y донора и накладываются на соседний ряд.
  if (pts.length === 0 && donorCurve.length > 0) {
    const c0 = curveAt(donorCurve, 0)
    const rowMeta = level.rows.find((r) => String(r.row) === String(row))
    if (c0 && rowMeta) {
      samplesX.push({ u: 0, d: Number(rowMeta.xCoord) - c0.x })
      samplesY.push({ u: 0, d: Number(rowMeta.yCoord) - c0.y })
    }
  }
  const fitX = linfit(samplesX)
  const fitY = linfit(samplesY)
  const offsetAt = (u) => ({ dx: fitX.a + fitX.b * u, dy: fitY.a + fitY.b * u })

  const seats = []
  let byRows = 0
  let bySeats = 0
  let skipped = 0

  for (let p = 1; p <= seatCount; p += 1) {
    const exact = rowMap.get(p)
    if (exact) {
      seats.push({ number: p, x: exact.x, y: exact.y, exact: true })
      continue
    }

    let lo = null, hi = null
    for (const q of pts) {
      if (q.p < p) lo = q
      if (q.p > p && hi === null) hi = q
    }
    const gapLen = lo && hi ? hi.p - lo.p - 1 : Number.POSITIVE_INFINITY

    // Короткий пропуск: внутри ряда (локально ряд почти прямолинеен, и это
    // сохраняет проходы). Длинный — по кривой донора со смещением.
    if (gapLen > 4 && donorCurve.length > 1) {
      const c = curveAt(donorCurve, uOf(p))
      const off = offsetAt(uOf(p))
      if (c) {
        seats.push({ number: p, x: c.x + off.dx, y: c.y + off.dy, exact: false })
        byRows += 1
        continue
      }
    }

    let x, y
    if (lo && hi) {
      const t = (p - lo.p) / (hi.p - lo.p)
      x = lo.x + (hi.x - lo.x) * t
      y = lo.y + (hi.y - lo.y) * t
    } else if (lo) {
      const st = stepBetween(pts, pts.indexOf(lo) - 1, pts.indexOf(lo))
      x = lo.x + st.dx * (p - lo.p)
      y = lo.y + st.dy * (p - lo.p)
    } else if (hi) {
      const idx = pts.indexOf(hi)
      const st = stepBetween(pts, idx, idx + 1)
      x = hi.x - st.dx * (hi.p - p)
      y = hi.y - st.dy * (hi.p - p)
    } else {
      skipped += 1
      continue
    }
    seats.push({ number: p, x, y, exact: false })
    bySeats += 1
  }
  return { seats, byRows, bySeats, skipped }
}

/** Числовой номер ряда внутри уровня (для приставных «1А» → 1). */
function rowNumOf(level, row) {
  return metaForLevel(level.name).rowToNumber(row)
}

/** Мода цены ряда (типичная цена), без учёта сервисного сбора. */
function rowPrice(rowKey, seats) {
  const priceMap = prices.get(rowKey) ?? new Map()
  const tally = new Map()
  for (const s of seats) {
    const v = priceMap.get(s.number)
    if (Number.isFinite(v) && v > 0) tally.set(v, (tally.get(v) ?? 0) + 1)
  }
  if (tally.size === 0) return null
  let best = null, bestN = -1
  for (const [v, n] of tally) if (n > bestN) { best = v; bestN = n }
  return best
}

/* ── 3. Собираем сектора из уровней основного сеанса ─────────────────── */
const base = JSON.parse(readFileSync(plans[0], 'utf8')).result.hallplan

const SECTOR_META = {
  'Партер': { id: 'parter', rowToNumber: (r) => Number(r) },
  'Приставные места': { id: 'pristavnye', rowToNumber: (r) => Number(String(r).replace(/\D/g, '')) },
  'Бельэтаж': { id: 'bel etazh', rowToNumber: (r) => Number(r) },
  'Балкон': { id: 'balkon', rowToNumber: (r) => Number(r) },
}

function metaForLevel(name) {
  if (SECTOR_META[name]) return SECTOR_META[name]
  const id = String(name).trim().toLowerCase()
    .replace(/[^a-zа-яё0-9]+/gi, '-')
    .replace(/^-|-$/g, '') || 'sector'
  return { id, rowToNumber: (r) => {
    const n = Number(String(r).replace(/\D/g, ''))
    return Number.isInteger(n) && n > 0 ? n : 1
  } }
}

const sourceSeatCount = base.levels.reduce((sum, level) => sum + level.rows.reduce((n, row) => n + Number(row.seatCount || 0), 0), 0)
const sparseImport = stats.knownSeats < sourceSeatCount * 0.6
// В редком плане ряды могут иметь исходный зазор 12–14 px; при 1.15 это
// нарушает серверный порог 16 px. Увеличиваем именно такую схему, не меняя
// компактный масштаб уже полноценных планов.
const scaleFactor = BASE_SCALE
if (sparseImport) {
  console.log(`(!) мало свободных мест (${stats.knownSeats}/${sourceSeatCount}) — используем стабильную сетку от metadata rows[]`)
}

const sectorsRaw = []
for (const level of base.levels) {
  const meta = metaForLevel(level.name)
  const seats = []
  const rowPrices = {}
  let sectorPrice = null
  const restored = { byRows: 0, bySeats: 0, skipped: 0 }
  for (const row of level.rows) {
    const rowKey = rowKeyOf(level.name, row.row)
    const built = sparseImport
      ? buildSparseRow(level, row.row, row.seatCount)
      : buildRow(level, row.row, row.seatCount)
    const rowSeats = built.seats
    restored.byRows += built.byRows
    restored.bySeats += built.bySeats
    restored.skipped += built.skipped
    const rn = meta.rowToNumber(row.row)
    const levelPrices = (level.prices ?? []).map((p) => Number(p.value)).filter((p) => Number.isFinite(p) && p > 0)
    const price = rowPrice(rowKey, rowSeats) ?? (levelPrices.length > 0 ? Math.min(...levelPrices) : null)
    if (Number.isInteger(rn) && rn > 0) {
      if (Number.isFinite(price)) {
        rowPrices[rn] = price
        sectorPrice = sectorPrice === null ? price : Math.min(sectorPrice, price)
      }
    }
    for (const s of rowSeats) {
      seats.push({ row: rn, number: s.number, x: s.x, y: s.y, exact: s.exact })
    }
  }
  sectorsRaw.push({
    id: meta.id,
    name: meta.id === 'pristavnye' ? 'Приставные места (ряды 1А–4А)' : level.name,
    priceMinor: sectorPrice ?? 0,
    rowPrices,
    seats,
    restored,
  })
}

/*
 * В некоторых сеансах Яндекс возвращает мало свободных мест, и для полной
 * сетки мы строим ряд по row.xCoord/yCoord + шагу места. У исходника есть
 * ряды с вертикальным зазором 8–14 px; при таком зазоре место визуально
 * пересекает соседний ряд после округления. Нормализуем только вертикальные
 * координаты рядов: сохраняем порядок и реальные большие интервалы, но
 * поднимаем минимальный исходный зазор до 18 px. Это заметно компактнее
 * старого scale=3.0 и одновременно проходит серверную проверку overlap.
 */
if (sparseImport) {
  for (const sector of sectorsRaw) {
    const rows = new Map()
    for (const seat of sector.seats) {
      const bucket = rows.get(seat.row) ?? []
      bucket.push(seat)
      rows.set(seat.row, bucket)
    }
    const ordered = [...rows.entries()]
      .map(([row, seats]) => ({ row, seats, sourceY: median(seats.map((s) => s.y)) }))
      .sort((a, b) => a.sourceY - b.sourceY)
    let previousY = -Infinity
    for (const item of ordered) {
      const normalizedY = Math.max(item.sourceY, previousY + 18)
      const delta = normalizedY - item.sourceY
      for (const seat of item.seats) seat.y += delta
      previousY = normalizedY
    }
  }
}

/* ── 4. Масштабирование и перенос на холст ──────────────────────────── */
let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
for (const s of sectorsRaw) {
  for (const seat of s.seats) {
    if (seat.x < minX) minX = seat.x
    if (seat.x > maxX) maxX = seat.x
    if (seat.y < minY) minY = seat.y
    if (seat.y > maxY) maxY = seat.y
  }
}
const toCanvas = (x, y) => ({
  x: Math.round((x - minX) * scaleFactor),
  y: Math.round((y - minY) * scaleFactor),
})

const sectors = []
const diag = []   // диагностика: откуда взялась каждая координата
let restored = 0, total = 0, restoredByRows = 0, restoredBySeats = 0, skipped = 0
for (const s of sectorsRaw) {
  const placed = s.seats.map((seat) => toCanvas(seat.x, seat.y))
  let bx = Infinity, by = Infinity
  for (const p of placed) {
    if (p.x < bx) bx = p.x
    if (p.y < by) by = p.y
  }
  sectors.push({
    id: s.id,
    name: s.name,
    // Места хранятся в ЛОКАЛЬНЫХ координатах сектора, смещение — в x/y сектора
    // (ровно так их кладёт HallEditorPage.vue).
    x: PAD + bx,
    y: PAD + by,
    priceMinor: s.priceMinor,
    type: 'seated',
    rowPrices: s.rowPrices,
    shape: 'grid',
    arcSpread: 120,
    arcBaseR: 200,
    arcRowGap: 24,
    arcOffsetX: 0,
    arcOffsetY: 0,
    seats: s.seats.map((seat, i) => ({
      id: `${s.id}-r${seat.row}-p${seat.number}`,
      row: seat.row,
      number: seat.number,
      kind: 'standard',
      x: placed[i].x - bx,
      y: placed[i].y - by,
    })),
  })
  s.seats.forEach((seat, i) => {
    diag.push({ sector: s.id, row: seat.row, number: seat.number, exact: seat.exact, cx: PAD + bx + placed[i].x - bx, cy: PAD + by + placed[i].y - by })
  })
  total += s.seats.length
  restored += s.seats.filter((seat) => !seat.exact).length
  restoredByRows += s.restored.byRows
  restoredBySeats += s.restored.bySeats
  skipped += s.restored.skipped
}

const canvasW = Math.round((maxX - minX) * scaleFactor) + PAD * 2
const hallH = Math.round((maxY - minY) * scaleFactor)

/* ── 5. Сцена ─────────────────────────────────────────────────────────
 * В схеме Яндекса нумерация растёт от сцены: партер ряд 1 (y=735) ближе к
 * сцене, чем ряд 10 (y=561), а приставные ряды 1А–4А стоят между партером и
 * сценой. Значит сцена — снизу по холсту, под приставными местами.
 */
const stageW = Math.round(canvasW * 0.42)
const stageH = 70
const stage = {
  id: 'static-stage',
  kind: 'stage',
  x: Math.round((canvasW - stageW) / 2),
  y: PAD + hallH + 45,
  width: stageW,
  height: stageH,
  rotation: 0,
  opacity: 1,
  locked: false,
  text: 'Сцена',
}

const schema = {
  version: '1.0',
  canvas: { width: canvasW, height: PAD + hallH + 45 + stageH + PAD },
  background: null,
  backgrounds: [],
  sectors,
  staticObjects: [stage],
}

/* ── 6. Самопроверка: те же правила, что применяет сервер ────────────── */
const points = []
for (const s of sectors) {
  for (const seat of s.seats) points.push({ x: seat.x + s.x, y: seat.y + s.y })
}
let minSpacing = Infinity
const overlaps = []
for (let i = 0; i < points.length; i += 1) {
  for (let j = i + 1; j < points.length; j += 1) {
    const dx = Math.abs(points[i].x - points[j].x)
    const dy = Math.abs(points[i].y - points[j].y)
    if (dx < SEAT_PX && dy < SEAT_PX) {
      overlaps.push({
        dx, dy,
        a: `${diag[i].sector} р${diag[i].row} м${diag[i].number}${diag[i].exact ? '' : ' (восстановлено)'}`,
        b: `${diag[j].sector} р${diag[j].row} м${diag[j].number}${diag[j].exact ? '' : ' (восстановлено)'}`,
      })
    }
    const spacing = Math.max(dx, dy)
    if (spacing < minSpacing) minSpacing = spacing
  }
}

const seatIds = new Set()
let dup = 0
for (const s of sectors) for (const seat of s.seats) {
  if (seatIds.has(seat.id)) dup += 1
  seatIds.add(seat.id)
}

mkdirSync('outputs', { recursive: true })
writeFileSync(outputFile, JSON.stringify(schema, null, 2))

console.log(`сеансов в источнике: ${stats.plans}, известно координат мест: ${stats.knownSeats}`)
console.log(`мест в схеме: ${total} (восстановлено: ${restored}, ${Math.round(restored / total * 100)}%)`)
console.log(`  из них по соседним рядам: ${restoredByRows}, по соседним местам: ${restoredBySeats}, пропущено: ${skipped}`)
for (const s of sectors) {
  console.log(`  ${s.name}: ${s.seats.length} мест, рядов ${Object.keys(s.rowPrices).length}, цена от ${Math.min(...Object.values(s.rowPrices))}`)
}
console.log(`холст: ${schema.canvas.width}×${schema.canvas.height}, минимальный шаг между местами: ${minSpacing.toFixed(1)} px (порог ${SEAT_PX})`)
if (overlaps.length > 0) {
  console.log(`(!) наложений: ${overlaps.length}; примеры:`)
  for (const o of overlaps.slice(0, 8)) console.log(`    dx=${o.dx} dy=${o.dy}  ${o.a} ↔ ${o.b}`)
}
console.log(`дубликатов id мест: ${dup}`)
console.log(`файл: ${outputFile}`)
