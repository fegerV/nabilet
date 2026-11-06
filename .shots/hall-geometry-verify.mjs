// Строгая проверка геометрии: сравниваем КАЖДУЮ пару соседних мест ряда
// (включая проходы) и шаг между рядами — по сектору, а не по всему залу.
import fs from 'node:fs'

const schemaFile = process.argv[2] || '.tmp-hall/schema-32.json'
const planFiles = process.argv.slice(3)

const schema = JSON.parse(fs.readFileSync(schemaFile, 'utf8'))
const ours = new Map()
for (const sector of schema.sectors) {
  for (const seat of sector.seats || []) ours.set(`${sector.id}|${seat.row}|${seat.number}`, { x: seat.x, y: seat.y })
}
const sectorByName = new Map(schema.sectors.map((s) => [String(s.name).toLowerCase(), s.id]))

// Объединение всех источников: у каждого сеанса проданы разные места, вместе
// они покрывают зал плотнее.
const anchors = new Map()
for (const file of planFiles) {
  const plan = JSON.parse(fs.readFileSync(file, 'utf8')).result.hallplan
  for (const level of plan.levels || []) {
    const sectorId = sectorByName.get(String(level.name || '').toLowerCase())
    if (!sectorId) continue
    for (const item of level.seats || []) {
      const s = item.seat
      anchors.set(`${sectorId}|${s.row}|${s.place}`, { x: Number(s.xCoord), y: Number(s.yCoord) })
    }
  }
}

const matched = []
for (const [key, a] of anchors) {
  const m = ours.get(key)
  if (m) matched.push({ key, ax: a.x, ay: a.y, mx: m.x, my: m.y })
}
console.log('ANCHORS: sources=%s matched=%s of %s schema seats', anchors.size, matched.length, ours.size)

// Подгонка по сектору.
const bySector = new Map()
for (const p of matched) {
  const [sectorId] = p.key.split('|')
  if (!bySector.has(sectorId)) bySector.set(sectorId, [])
  bySector.get(sectorId).push(p)
}

const fits = new Map()
for (const [sectorId, list] of bySector) {
  const fit = (gp, gm) => {
    const n = list.length
    const mx = list.reduce((a, p) => a + gp(p), 0) / n
    const my = list.reduce((a, p) => a + gm(p), 0) / n
    let num = 0, den = 0
    for (const p of list) { const d = gp(p) - mx; num += d * (gm(p) - my); den += d * d }
    const a = num / den
    const b = my - a * mx
    let maxRes = 0, sum = 0
    for (const p of list) { const r = Math.abs(gm(p) - (a * gp(p) + b)); maxRes = Math.max(maxRes, r); sum += r }
    return { a, b, maxRes, meanRes: sum / n }
  }
  const fx = fit((p) => p.ax, (p) => p.mx)
  const fy = fit((p) => p.ay, (p) => p.my)
  fits.set(sectorId, { fx, fy })
  console.log(
    'SECTOR %s: n=%s scaleX=%s scaleY=%s | maxRes X=%s Y=%s | meanRes X=%s Y=%s | uniform=%s',
    sectorId, list.length, fx.a.toFixed(4), fy.a.toFixed(4),
    fx.maxRes.toFixed(2), fy.maxRes.toFixed(2), fx.meanRes.toFixed(2), fy.meanRes.toFixed(2),
    Math.abs(fx.a - fy.a) < 0.005,
  )
}

// Шаг между соседними по номеру местами ряда: отношение «наше / Яндекс».
// Если геометрия перенесена верно, отношение равно масштабу сектора ВЕЗДЕ,
// включая проходы.
const rows = new Map()
for (const p of matched) {
  const [sectorId, row] = p.key.split('|')
  const key = `${sectorId}|${row}`
  if (!rows.has(key)) rows.set(key, [])
  rows.get(key).push({ ...p, number: Number(p.key.split('|')[2]) })
}

let worst = null
const ratios = []
for (const [key, list] of rows) {
  list.sort((a, b) => a.number - b.number)
  const { fx, fy } = fits.get(key.split('|')[0])
  for (let i = 1; i < list.length; i++) {
    const a = list[i - 1], b = list[i]
    if (b.number - a.number !== 1) continue
    const yDist = Math.hypot(b.ax - a.ax, b.ay - a.ay)
    if (yDist < 1) continue
    const mDist = Math.hypot(b.mx - a.mx, b.my - a.my)
    const expected = yDist * fx.a
    const err = Math.abs(mDist - expected)
    ratios.push(err)
    if (!worst || err > worst.err) worst = { key, a: a.number, b: b.number, yDist, mDist, expected, err }
  }
}
ratios.sort((x, y) => y - x)
console.log('PAIR STEPS CHECKED:', ratios.length)
console.log('STEP ERROR: max=%s px, 95th=%s px, mean=%s px',
  ratios[0]?.toFixed(2), (ratios[Math.floor(ratios.length * 0.05)] ?? 0).toFixed(2),
  (ratios.reduce((a, b) => a + b, 0) / ratios.length).toFixed(2))
if (worst) console.log('WORST PAIR:', worst.key, `#${worst.a}->#${worst.b}`, 'yandex=%s ours=%s expected=%s err=%s',
  worst.yDist.toFixed(1), worst.mDist.toFixed(1), worst.expected.toFixed(1), worst.err.toFixed(1))

// Шаг между рядами — внутри сектора.
for (const [sectorId, list] of bySector) {
  const centers = new Map()
  for (const p of list) {
    const row = p.key.split('|')[1]
    if (!centers.has(row)) centers.set(row, { sum: 0, n: 0 })
    const c = centers.get(row); c.sum += p.my; c.n++
  }
  const arr = [...centers.entries()].map(([row, c]) => ({ row: Number(row), cy: c.sum / c.n })).sort((a, b) => a.row - b.row)
  const gaps = []
  for (let i = 1; i < arr.length; i++) gaps.push(+(arr[i].cy - arr[i - 1].cy).toFixed(1))
  console.log('ROW PITCH %s (по порядку рядов):', sectorId, gaps.join(', '))
}
