/**
 * Проверка схемы зала против исходного hallplan Яндекса.
 *
 * Считает то, что видно глазами только на overlay: совпадение центров столов
 * с исходными, минимальный шаг между местами, наложения (валидатор зала
 * запрещает |dx|<16 && |dy|<16) и сохранность известных координат.
 *
 * Запуск: node .shots/hallplan-schema-check.mjs <hallplan.json> <schema.json>
 */
import { readFileSync } from 'node:fs'

const [, , PLAN, SCHEMA] = process.argv
const plan = JSON.parse(readFileSync(PLAN, 'utf8')).result.hallplan
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8'))

const tableLevels = plan.levels.filter((l) => !l.admission && (l.rows ?? []).length > 0)
const known = new Map() // `${levelId}:${row}:${place}` -> {x,y}
for (const level of tableLevels) {
  for (const item of level.seats) {
    known.set(`${level.id}:${item.seat.row}:${item.seat.place}`, { x: item.seat.xCoord, y: item.seat.yCoord })
  }
}

// Точки в координатах холста (sector offset + seat offset + центр места).
const points = []
for (const sector of schema.sectors) {
  for (const seat of sector.seats) {
    points.push({ sector: sector.id, seat: seat.number, x: sector.x + seat.x + 8, y: sector.y + seat.y + 8 })
  }
}

let minStep = Infinity
let minPair = null
const overlaps = []
for (let i = 0; i < points.length; i += 1) {
  for (let j = i + 1; j < points.length; j += 1) {
    const dx = Math.abs(points[i].x - points[j].x)
    const dy = Math.abs(points[i].y - points[j].y)
    const d = Math.hypot(dx, dy)
    if (d < minStep) {
      minStep = d
      minPair = [points[i], points[j]]
    }
    if (dx < 16 && dy < 16) overlaps.push({ a: points[i], b: points[j], dx, dy })
  }
}

// Известные места обязаны остаться на своих координатах (сдвиг раскладки — общий).
const xs = points.map((p) => p.x)
const ys = points.map((p) => p.y)
let shiftX = null
let shiftY = null
let exact = 0
let drifted = []
for (const level of tableLevels) {
  for (const sector of schema.sectors) {
    if (!sector.id.startsWith(`level-${level.id}-row-`)) continue
    const row = sector.id.slice(`level-${level.id}-row-`.length)
    for (const seat of sector.seats) {
      const src = known.get(`${level.id}:${row}:${seat.number}`)
      if (!src) continue
      const x = sector.x + seat.x + 8
      const y = sector.y + seat.y + 8
      if (shiftX === null) {
        shiftX = x - src.x
        shiftY = y - src.y
      }
      if (Math.abs(x - shiftX - src.x) <= 1 && Math.abs(y - shiftY - src.y) <= 1) exact += 1
      else drifted.push({ sector: sector.id, seat: seat.number, dx: (x - shiftX - src.x).toFixed(1), dy: (y - shiftY - src.y).toFixed(1) })
    }
  }
}

// Центры столов: схема против исходных известных мест.
console.log(`схема: секторов ${schema.sectors.length}, мест ${points.length}`)
console.log(`источник: известных мест ${known.size}`)
console.log(`сдвиг раскладки: dx=${shiftX} dy=${shiftY}`)
console.log(`известных мест на своих координатах: ${exact}/${known.size}`)
if (drifted.length) console.log('съехали:', drifted.slice(0, 10))
console.log(`мин. шаг между центрами мест: ${minStep.toFixed(2)} px`, minPair ? `(${minPair[0].sector}#${minPair[0].seat} ↔ ${minPair[1].sector}#${minPair[1].seat})` : '')
console.log(`наложений (|dx|<16 && |dy|<16): ${overlaps.length}`)
for (const o of overlaps.slice(0, 10)) console.log('  ', JSON.stringify(o))
console.log(`габарит мест: x ${Math.min(...xs).toFixed(0)}…${Math.max(...xs).toFixed(0)}, y ${Math.min(...ys).toFixed(0)}…${Math.max(...ys).toFixed(0)}`)
console.log(`canvas: ${schema.canvas.width}×${schema.canvas.height}`)
for (const obj of schema.staticObjects ?? []) {
  console.log(`static ${obj.kind} «${obj.text}» @ ${obj.x},${obj.y} ${obj.width}×${obj.height} cap=${obj.capacity}`)
}
