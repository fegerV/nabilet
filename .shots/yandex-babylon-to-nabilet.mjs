/**
 * Конвертер схемы «Вавилон» из Яндекс Билетов в NABILET.
 *
 * Специфика этой площадки:
 * - Яндекс отдаёт 62 физических стола отдельными уровнями/рядами:
 *   «Столы на танцполе», «Боковые столы», «Столы с ограниченной видимостью»
 *   и «VIP столы».
 * - Каждый row в этих уровнях — это ОДИН стол, а не длинный ряд. Поэтому
 *   обычный конвертер сетки здесь ошибочен: он превращает все места уровня
 *   в один сектор и теряет столы.
 * - У стола shape='table', места лежат кольцом. При проданных/скрытых местах
 *   недостающие позиции достраиваются по шаблону ближайшего стола того же
 *   уровня и вместимости; известные координаты всегда сохраняются.
 * - Уровень «Танцпол» — admission: в NABILET он становится standing-зоной,
 *   а не шестью наложенными местами (Яндекс повторяет одну admission-точку).
 *
 * Запуск:
 *   node .shots/yandex-babylon-to-nabilet.mjs \
 *     .shots/yandex-hallplan-359964.json outputs/hall-babylon-schema.json
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'

const PLAN_FILE = process.argv[2] ?? '.shots/yandex-hallplan-359964.json'
const OUTPUT = process.argv[3] ?? 'outputs/hall-babylon-schema.json'
const PAD = 80
const SEAT = 16
const SCALE = 1.2 // исходный шаг бывает 14–15 px; 1.2 сохраняет компактность и проходит overlap

const root = JSON.parse(readFileSync(PLAN_FILE, 'utf8'))
const result = root.result
const hall = result.hallplan
// «Столы на танцполе» в выдаче Яндекса — вариант конкретного события,
// а не часть постоянной схемы Вавилона: пользователь подтвердил, что на
// танцполе площадки столов нет. В шаблон сохраняем только физические столы,
// а сам танцпол — отдельной standing-зоной.
const tableLevels = hall.levels.filter((level) =>
  !level.admission && level.rows.length > 0 && level.name !== 'Столы на танцполе'
)

function rowsFor(level) {
  return level.rows.map((row) => ({
    row,
    known: level.seats
      .filter((seat) => String(seat.seat.row) === String(row.row))
      .sort((a, b) => Number(a.seat.place) - Number(b.seat.place)),
  }))
}

function anchorFor(item) {
  const metaX = Number(item.row.xCoord)
  const metaY = Number(item.row.yCoord)
  const metaSeat = item.known.find((seat) => Number(seat.seat.xCoord) === metaX && Number(seat.seat.yCoord) === metaY)
  const p2 = item.known.find((seat) => Number(seat.seat.place) === 2)
  return {
    x: metaX,
    y: metaY,
    // row.xCoord/yCoord — координата одного опорного места, но его номер
    // меняется у разных направлений столов (например, боковые столы — p5,
    // VIP — p2). Узнаём опорный номер по совпадению координат.
    place: Number(metaSeat?.seat.place ?? p2?.seat.place ?? 2),
  }
}

function templateFor(items, item) {
  const candidates = items
    .filter((candidate) => candidate !== item && candidate.known.length >= 2)
    .map((candidate) => ({
      candidate,
      distance: Math.hypot(
        Number(candidate.row.xCoord) - Number(item.row.xCoord),
        Number(candidate.row.yCoord) - Number(item.row.yCoord),
      ),
    }))
    .sort((a, b) => a.distance - b.distance)
  const donor = candidates.find((x) => Number(x.candidate.row.seatCount) === Number(item.row.seatCount))
    ?? candidates[0]
  if (!donor) return { offsets: new Map(), place: 2 }
  const anchor = anchorFor(donor.candidate)
  return {
    place: anchor.place,
    offsets: new Map(donor.candidate.known.map((seat) => [
      Number(seat.seat.place), {
        dx: Number(seat.seat.xCoord) - anchor.x,
        dy: Number(seat.seat.yCoord) - anchor.y,
      },
    ])),
  }
}

function priceFor(level, item, items) {
  const prices = item.known
    .map((seat) => Number(seat.priceInfo?.price?.value))
    .filter((price) => Number.isFinite(price) && price > 0)
  if (prices.length > 0) return Math.min(...prices)
  const nearby = items
    .filter((candidate) => candidate.known.length > 0)
    .map((candidate) => ({
      distance: Math.hypot(
        Number(candidate.row.xCoord) - Number(item.row.xCoord),
        Number(candidate.row.yCoord) - Number(item.row.yCoord),
      ),
      price: Number(candidate.known[0].priceInfo?.price?.value),
    }))
    .filter((x) => Number.isFinite(x.price) && x.price > 0)
    .sort((a, b) => a.distance - b.distance)
  return nearby[0]?.price ?? Number(level.prices?.[0]?.value ?? 0)
}

function categoryTitle(levelName) {
  return levelName
    .replace(/^Столы на танцполе$/, 'Столы на танцполе')
    .replace(/^Боковые столы$/, 'Боковые столы')
    .replace(/^Столы с ограниченной видимостью$/, 'Столы с ограниченной видимостью')
    .replace(/^VIP столы$/, 'VIP столы')
}

const sectors = []
const diagnostics = []
const reconstructedIds = new Set()
let tableSeatCount = 0

for (const level of tableLevels) {
  const items = rowsFor(level)
  for (const item of items) {
    const tableId = `${level.id}-${item.row.row}`
    const anchor = anchorFor(item)
    const template = templateFor(items, item)
    const knownByPlace = new Map(item.known.map((seat) => [Number(seat.seat.place), seat]))
    const priceMinor = priceFor(level, item, items)
    const seatsAbs = []

    for (let place = 1; place <= Number(item.row.seatCount); place += 1) {
      const known = knownByPlace.get(place)
      const offset = template.offsets.get(place)
      const x = known
        ? Number(known.seat.xCoord)
        : (anchor.x + (offset?.dx ?? ((place - template.place) * 20)))
      const y = known
        ? Number(known.seat.yCoord)
        : (anchor.y + (offset?.dy ?? 0))
      let absX = x * SCALE
      let absY = y * SCALE
      // У полностью распроданного VIP-стола у донора может отсутствовать
      // крайнее место; не допускаем дубль fallback-координаты внутри кольца.
      if (!known) {
        while (seatsAbs.some((seat) => Math.abs(seat.x - absX) < 1 && Math.abs(seat.y - absY) < 1)) {
          absX += 20 * SCALE
          absY += 10 * SCALE
        }
      }
      seatsAbs.push({ place, x: absX, y: absY, known: Boolean(known) })
    }

    const minX = Math.min(...seatsAbs.map((seat) => seat.x))
    const minY = Math.min(...seatsAbs.map((seat) => seat.y))
    sectors.push({
      id: `table-${tableId}`,
      name: `${categoryTitle(level.name)} · стол ${item.row.row}`,
      x: Math.round(PAD + minX),
      y: Math.round(PAD + minY),
      priceMinor,
      type: 'seated',
      rowPrices: { 1: priceMinor },
      shape: 'table',
      arcSpread: 120,
      arcBaseR: 200,
      arcRowGap: 24,
      arcOffsetX: 0,
      arcOffsetY: 0,
      seats: seatsAbs.map((seat) => ({
        id: `table-${tableId}-seat-${seat.place}`,
        row: 1,
        number: seat.place,
        kind: priceMinor >= 180000 ? 'vip' : 'standard',
        x: Math.round(seat.x - minX),
        y: Math.round(seat.y - minY),
      })),
    })
    for (const seat of seatsAbs) {
      if (!seat.known) reconstructedIds.add(`table-${tableId}-seat-${seat.place}`)
    }
    tableSeatCount += seatsAbs.length
    diagnostics.push({
      level: level.name,
      table: String(item.row.row),
      seats: seatsAbs.length,
      known: item.known.length,
      reconstructed: seatsAbs.length - item.known.length,
      priceMinor,
    })
  }
}

const sourceMinX = Math.min(...tableLevels.flatMap((level) => level.seats.map((s) => s.seat.xCoord))) * SCALE
const sourceMaxX = Math.max(...tableLevels.flatMap((level) => level.seats.map((s) => s.seat.xCoord))) * SCALE
const sourceMinY = Math.min(...tableLevels.flatMap((level) => level.seats.map((s) => s.seat.yCoord))) * SCALE
const sourceMaxY = Math.max(...tableLevels.flatMap((level) => level.seats.map((s) => s.seat.yCoord))) * SCALE
const canvasWidth = Math.round(sourceMaxX - sourceMinX + PAD * 2)

// Разводим восстановленные места, которые оказались рядом с соседним столом.
// Известные координаты Яндекса не двигаем; достроенное место сдвигаем от
// центра сцены, сохраняя кольцо стола и проход между физическими столами.
function seatPointsNow() {
  return sectors.flatMap((sector) => sector.seats.map((seat) => ({ sector, seat, x: sector.x + seat.x, y: sector.y + seat.y })))
}
for (let pass = 0; pass < 4; pass += 1) {
  let moved = false
  const points = seatPointsNow()
  for (let i = 0; i < points.length; i += 1) {
    for (let j = i + 1; j < points.length; j += 1) {
      const a = points[i], b = points[j]
      const dx = Math.abs(a.x - b.x), dy = Math.abs(a.y - b.y)
      if (dx >= SEAT || dy >= SEAT) continue
      const candidate = reconstructedIds.has(b.seat.id) ? b
        : reconstructedIds.has(a.seat.id) ? a : null
      if (!candidate) continue
      const direction = candidate.x < canvasWidth / 2 ? -1 : 1
      candidate.seat.x += direction * 24
      moved = true
    }
  }
  if (!moved) break
}

const tableBottom = sourceMaxY + PAD
const danceWidth = 520
const danceHeight = 300
const danceX = Math.round(canvasWidth / 2 - danceWidth / 2)
const danceY = Math.round((sourceMinY + sourceMaxY) / 2 + 80)
const stageWidth = 420
const stageY = tableBottom + 100

const admissionLevel = hall.levels.find((level) => level.admission)
// Для admission Яндекс повторяет одну и ту же точку несколько раз, поэтому
// `seats.length` — не вместимость. Реальная доступная ёмкость зоны лежит в
// `level.availableSeatCount` (на событии 359964 — 95 мест).
const danceCapacity = Math.max(1, Number(admissionLevel?.availableSeatCount ?? 1))

const schema = {
  version: '1.0',
  canvas: {
    width: canvasWidth,
    height: Math.round(stageY + 80),
  },
  background: null,
  backgrounds: [],
  sectors,
  staticObjects: [
    {
      id: 'static-dancefloor',
      kind: 'standing',
      x: danceX,
      y: danceY,
      width: danceWidth,
      height: danceHeight,
      rotation: 0,
      opacity: 1,
      locked: false,
      text: 'Танцпол',
      capacity: danceCapacity,
      priceMinor: Number(admissionLevel?.seats[0]?.priceInfo?.price?.value ?? 70000),
    },
    {
      id: 'static-stage',
      kind: 'stage',
      x: Math.round(canvasWidth / 2 - stageWidth / 2),
      y: stageY,
      width: stageWidth,
      height: 70,
      rotation: 0,
      opacity: 1,
      locked: false,
      text: 'Сцена',
    },
  ],
}

const seatPoints = []
for (const sector of schema.sectors) {
  for (const seat of sector.seats) seatPoints.push({ x: sector.x + seat.x, y: sector.y + seat.y })
}
const overlaps = []
for (let i = 0; i < seatPoints.length; i += 1) {
  for (let j = i + 1; j < seatPoints.length; j += 1) {
    const dx = Math.abs(seatPoints[i].x - seatPoints[j].x)
    const dy = Math.abs(seatPoints[i].y - seatPoints[j].y)
    if (dx < SEAT && dy < SEAT) overlaps.push({ dx, dy, a: seatPoints[i], b: seatPoints[j] })
  }
}

mkdirSync('outputs', { recursive: true })
writeFileSync(OUTPUT, JSON.stringify(schema, null, 2))
writeFileSync(OUTPUT.replace(/\.json$/, '.report.json'), JSON.stringify({
  sourceTotal: hall.totalSeatCount,
  sourceAvailable: hall.availableSeatCount,
  tableLevels: tableLevels.map((level) => ({ name: level.name, seats: level.seats.length, tables: level.rows.length })),
  tables: diagnostics,
  tableCount: sectors.length,
  tableSeatCount,
  danceCapacity,
  outputSeats: tableSeatCount,
  overlapCount: overlaps.length,
  canvas: schema.canvas,
}, null, 2))

console.log(`Источник: всего ${hall.totalSeatCount}, доступно ${hall.availableSeatCount}`)
console.log(`Столов: ${sectors.length}, мест за столами: ${tableSeatCount}`)
console.log(`Танцпол: standing capacity=${danceCapacity}, price=${schema.staticObjects[0].priceMinor}`)
console.log(`Достроено мест за столами: ${diagnostics.reduce((sum, x) => sum + x.reconstructed, 0)}`)
console.log(`Наложений мест: ${overlaps.length}`)
console.log(`Холст: ${schema.canvas.width}×${schema.canvas.height}`)
console.log(`Файл: ${OUTPUT}`)
