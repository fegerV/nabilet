// Сверка нашей схемы зала с оригиналом Яндекс.Афиши.
//
// Берём известные координаты мест из hallplan (`levels[].seats[]`) и ищем то же
// место в нашей схеме по (сектор, ряд, номер). Считаем аффинную подгонку
// «наш = a * яндекс + b» по обеим осям: если геометрия сохранена, остатки
// близки к нулю, а масштаб по X и Y одинаков (иначе зал сплющен).
import fs from 'node:fs'

const schemaFile = process.argv[2] || '.tmp-hall/schema-32.json'
const planFile = process.argv[3] || '.shots/yandex-hallplan-337191.json'

const schema = JSON.parse(fs.readFileSync(schemaFile, 'utf8'))
const planRaw = JSON.parse(fs.readFileSync(planFile, 'utf8'))
const plan = planRaw.result?.hallplan || planRaw.hallplan || planRaw

// Наши места: ключ «сектор|ряд|номер».
const ours = new Map()
for (const sector of schema.sectors) {
  for (const seat of sector.seats || []) {
    ours.set(`${sector.id}|${seat.row}|${seat.number}`, { x: seat.x, y: seat.y, sector: sector.id })
  }
}

// Уровни Яндекса сопоставляем с секторами по имени.
const sectorByName = new Map(schema.sectors.map((s) => [String(s.name).toLowerCase(), s.id]))

const pairs = []
const perLevel = []

for (const level of plan.levels || []) {
  const sectorId = sectorByName.get(String(level.name || '').toLowerCase())
  const known = level.seats || []
  let matched = 0

  for (const seat of known) {
    // Формат Яндекс.Билетов: координаты лежат во вложенном `seat`,
    // номер места — `place` (не `number`/`seat`).
    const inner = seat.seat ?? seat
    const number = inner.place ?? inner.number ?? inner.id
    const row = inner.row ?? inner.rowNumber
    const yx = Number(inner.xCoord ?? inner.x)
    const yy = Number(inner.yCoord ?? inner.y)
    const mine = ours.get(`${sectorId}|${row}|${number}`)
    if (!mine) continue
    matched++
    pairs.push({ sector: sectorId, row: Number(row), number: Number(number), yx, yy, mx: mine.x, my: mine.y })
  }

  perLevel.push({ level: level.name, sectorId, known: known.length, matched })
}

const fit = (getP, getM) => {
  const n = pairs.length
  const sx = pairs.reduce((a, p) => a + getP(p), 0)
  const sy = pairs.reduce((a, p) => a + getM(p), 0)
  const mx = sx / n, my = sy / n
  let num = 0, den = 0
  for (const p of pairs) { const dx = getP(p) - mx; num += dx * (getM(p) - my); den += dx * dx }
  const a = den === 0 ? 1 : num / den
  const b = my - a * mx
  let maxRes = 0, sum = 0
  for (const p of pairs) { const r = getM(p) - (a * getP(p) + b); maxRes = Math.max(maxRes, Math.abs(r)); sum += Math.abs(r) }
  return { a, b, maxRes, meanRes: sum / n }
}

console.log('SCHEMA:', schemaFile, 'canvas', JSON.stringify(schema.canvas))
console.log('PLAN  :', planFile)
console.log('LEVELS:', JSON.stringify(perLevel))
console.log('ANCHORS MATCHED:', pairs.length, 'of', (plan.levels || []).reduce((a, l) => a + (l.seats || []).length, 0))

if (pairs.length > 2) {
  const fx = fit((p) => p.yx, (p) => p.mx)
  const fy = fit((p) => p.yy, (p) => p.my)
  console.log('FIT X: a=%s b=%s maxRes=%s meanRes=%s', fx.a.toFixed(4), fx.b.toFixed(2), fx.maxRes.toFixed(2), fx.meanRes.toFixed(2))
  console.log('FIT Y: a=%s b=%s maxRes=%s meanRes=%s', fy.a.toFixed(4), fy.b.toFixed(2), fy.maxRes.toFixed(2), fy.meanRes.toFixed(2))
  console.log('SCALE EQUAL (|ax-ay|<0.01):', Math.abs(fx.a - fy.a) < 0.01)
}

// Подгонка ПО СЕКТОРАМ: секторы в нашей схеме сдвинуты друг относительно
// друга (балкон стоит отдельно), поэтому общая аффинная подгонка смешивает
// два разных преобразования и показывает несуществующее «сплющивание».
for (const sectorId of [...new Set(pairs.map((p) => p.sector))]) {
  const list = pairs.filter((p) => p.sector === sectorId)
  if (list.length < 3) continue
  const fitOn = (getP, getM) => {
    const n = list.length
    const mx = list.reduce((a, p) => a + getP(p), 0) / n
    const my = list.reduce((a, p) => a + getM(p), 0) / n
    let num = 0, den = 0
    for (const p of list) { const dx = getP(p) - mx; num += dx * (getM(p) - my); den += dx * dx }
    const a = den === 0 ? 1 : num / den
    const b = my - a * mx
    let maxRes = 0, sum = 0
    for (const p of list) { const r = getM(p) - (a * getP(p) + b); maxRes = Math.max(maxRes, Math.abs(r)); sum += Math.abs(r) }
    return { a, b, maxRes, meanRes: sum / n }
  }
  const sx = fitOn((p) => p.yx, (p) => p.mx)
  const sy = fitOn((p) => p.yy, (p) => p.my)
  console.log(
    'SECTOR %s: n=%s | X a=%s maxRes=%s meanRes=%s | Y a=%s maxRes=%s meanRes=%s | uniform=%s',
    sectorId, list.length,
    sx.a.toFixed(4), sx.maxRes.toFixed(2), sx.meanRes.toFixed(2),
    sy.a.toFixed(4), sy.maxRes.toFixed(2), sy.meanRes.toFixed(2),
    Math.abs(sx.a - sy.a) < 0.01,
  )
}

// Шаг сетки: расстояния между соседними по номеру местами внутри ряда.
const rows = new Map()
for (const p of pairs) {
  const key = `${p.sector}|${p.row}`
  if (!rows.has(key)) rows.set(key, [])
  rows.get(key).push(p)
}

const steps = []
for (const [key, list] of rows) {
  list.sort((a, b) => a.number - b.number)
  for (let i = 1; i < list.length; i++) {
    const a = list[i - 1], b = list[i]
    if (b.number - a.number !== 1) continue
    steps.push({ key, dx: b.mx - a.mx, dy: b.my - a.my, dist: Math.hypot(b.mx - a.mx, b.my - a.my) })
  }
}

if (steps.length) {
  const dists = steps.map((s) => s.dist)
  const min = Math.min(...dists), max = Math.max(...dists)
  const mean = dists.reduce((a, b) => a + b, 0) / dists.length
  const sd = Math.sqrt(dists.reduce((a, d) => a + (d - mean) ** 2, 0) / dists.length)
  console.log('GRID STEP (our px): min=%s max=%s mean=%s sd=%s n=%s', min.toFixed(2), max.toFixed(2), mean.toFixed(2), sd.toFixed(2), dists.length)
  const nonUniform = steps.filter((s) => Math.abs(s.dist - mean) > 0.35 * mean)
  console.log('NON-UNIFORM STEPS:', nonUniform.length, nonUniform.slice(0, 5).map((s) => `${s.key} d=${s.dist.toFixed(1)}`).join(' | '))
  // Вертикальный шаг между рядами.
  const rowCenters = []
  for (const [key, list] of rows) {
    const cy = list.reduce((a, p) => a + p.my, 0) / list.length
    rowCenters.push({ key, cy, n: list.length })
  }
  rowCenters.sort((a, b) => a.cy - b.cy)
  const dy = []
  for (let i = 1; i < rowCenters.length; i++) dy.push(rowCenters[i].cy - rowCenters[i - 1].cy)
  console.log('ROW PITCH (our px):', dy.map((d) => d.toFixed(1)).join(', '))
}
