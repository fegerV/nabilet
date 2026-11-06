/**
 * Расхождение схемы зала с исходным hallplan Яндекса (режим рядов).
 *
 * Сопоставляет места по ключу «уровень : ряд : номер» и после вычета общего
 * сдвига показывает, насколько схема NABILET расходится с координатами
 * источника. Заодно считает наложения (валидатор запрещает |dx|<16 && |dy|<16).
 *
 * Запуск: node .shots/hallplan-schema-diff.mjs <hallplan.json> <schema.json>
 */
import { readFileSync } from 'node:fs'

const [, , PLAN, SCHEMA] = process.argv
const plan = JSON.parse(readFileSync(PLAN, 'utf8')).result.hallplan
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8').trim())

const source = new Map()
for (const level of plan.levels) {
  for (const item of level.seats ?? []) {
    source.set(`${level.id}:${item.seat.row}:${item.seat.place}`, {
      x: Number(item.seat.xCoord),
      y: Number(item.seat.yCoord),
      level: level.name,
    })
  }
}

const matched = []
const points = []
for (const sector of schema.sectors) {
  const levelId = sector.id.startsWith('level-') ? sector.id.slice(6).split('-row-')[0] : null
  for (const seat of sector.seats) {
    const x = sector.x + seat.x + 8
    const y = sector.y + seat.y + 8
    points.push({ x, y })
    const key = `${levelId}:${seat.row}:${seat.number}`
    const src = source.get(key)
    if (src) matched.push({ key, level: src.level, dx: x - src.x, dy: y - src.y })
  }
}

if (matched.length === 0) {
  console.log('совпадений нет — id секторов/мест не соответствуют источнику')
  process.exit(0)
}

// Общий сдвиг — медиана: перенос всей раскладки не является искажением.
const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b)
  return sorted[Math.floor(sorted.length / 2)]
}
const shiftX = median(matched.map((m) => m.dx))
const shiftY = median(matched.map((m) => m.dy))
const residuals = matched.map((m) => ({
  ...m,
  r: Math.hypot(m.dx - shiftX, m.dy - shiftY),
})).sort((a, b) => b.r - a.r)

const avg = residuals.reduce((s, x) => s + x.r, 0) / residuals.length
const bad = residuals.filter((x) => x.r > 20)

console.log(`мест в схеме: ${points.length}, сопоставлено с источником: ${matched.length} из ${source.size}`)
console.log(`общий сдвиг: dx=${shiftX} dy=${shiftY}`)
console.log(`отклонение после сдвига: среднее ${avg.toFixed(1)} px, макс ${residuals[0].r.toFixed(1)} px`)
console.log(`мест со сдвигом >20 px: ${bad.length} (${(100 * bad.length / residuals.length).toFixed(0)}%)`)
for (const b of residuals.slice(0, 8)) {
  console.log(`   ${b.level} ${b.key}: ${b.r.toFixed(0)} px`)
}

let minStep = Infinity
let overlaps = 0
for (let i = 0; i < points.length; i += 1) {
  for (let j = i + 1; j < points.length; j += 1) {
    const dx = Math.abs(points[i].x - points[j].x)
    const dy = Math.abs(points[i].y - points[j].y)
    const d = Math.hypot(dx, dy)
    if (d < minStep) minStep = d
    if (dx < 16 && dy < 16) overlaps += 1
  }
}
console.log(`мин. шаг: ${minStep.toFixed(1)} px, наложений: ${overlaps}`)
