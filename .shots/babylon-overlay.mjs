/**
 * Overlay: схема NABILET + известные места Яндекса поверх.
 *
 * Если перенос верный, красные точки (координаты из hallplan Яндекса) лягут
 * ровно на серые (места схемы). Сдвиг и масштаб подбираются по первым
 * совпавшим местам, поэтому overlay не зависит от общего преобразования.
 *
 * Запуск: node .shots/babylon-overlay.mjs <hallplan.json> <schema.json> <out.html>
 */
import { readFileSync, writeFileSync } from 'node:fs'

const [, , PLAN, SCHEMA, OUT] = process.argv
const plan = JSON.parse(readFileSync(PLAN, 'utf8')).result.hallplan
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8'))

const tableLevels = plan.levels.filter((l) => !l.admission && (l.rows ?? []).length > 0)

// Все места схемы в координатах холста.
const schemaSeats = []
for (const sector of schema.sectors) {
  for (const seat of sector.seats) {
    schemaSeats.push({ x: sector.x + seat.x + 8, y: sector.y + seat.y + 8 })
  }
}

// Известные места Яндекса: подбираем масштаб/сдвиг по совпадению с местом схемы.
const pairs = []
for (const level of tableLevels) {
  for (const item of level.seats) {
    const sector = schema.sectors.find((s) => s.id === `level-${level.id}-row-${item.seat.row}`)
    if (!sector) continue
    const seat = sector.seats.find((s) => String(s.number) === String(item.seat.place))
    if (!seat) continue
    pairs.push({
      src: { x: item.seat.xCoord, y: item.seat.yCoord },
      out: { x: sector.x + seat.x + 8, y: sector.y + seat.y + 8 },
    })
  }
}
// Масштаб — по двум самым далёким друг от друга парам (устойчиво к округлению).
let scale = 1
if (pairs.length > 1) {
  let best = null
  for (let i = 0; i < pairs.length; i += 1) {
    for (let j = i + 1; j < pairs.length; j += 1) {
      const ds = Math.hypot(pairs[i].src.x - pairs[j].src.x, pairs[i].src.y - pairs[j].src.y)
      const dO = Math.hypot(pairs[i].out.x - pairs[j].out.x, pairs[i].out.y - pairs[j].out.y)
      if (ds > 50 && (!best || ds > best.ds)) best = { ds, dO }
    }
  }
  if (best) scale = best.dO / best.ds
}
const shifted = pairs.map((p) => ({
  x: p.src.x * scale + (p.out.x - p.src.x * scale),
  y: p.src.y * scale + (p.out.y - p.src.y * scale),
}))
const shiftX = pairs.length ? pairs[0].out.x - pairs[0].src.x * scale : 0
const shiftY = pairs.length ? pairs[0].out.y - pairs[0].src.y * scale : 0
const srcPoints = tableLevels.flatMap((level) => level.seats.map((item) => ({
  x: item.seat.xCoord * scale + shiftX,
  y: item.seat.yCoord * scale + shiftY,
})))

let worst = 0
for (let i = 0; i < pairs.length; i += 1) {
  const p = shifted[i]
  const nearest = schemaSeats.reduce((acc, s) => {
    const d = Math.hypot(s.x - p.x, s.y - p.y)
    return d < acc ? d : acc
  }, Infinity)
  if (nearest > worst) worst = nearest
}

const all = [...schemaSeats, ...srcPoints]
const minX = Math.min(...all.map((p) => p.x)) - 40
const minY = Math.min(...all.map((p) => p.y)) - 40
const maxX = Math.max(...all.map((p) => p.x)) + 40
const maxY = Math.max(...all.map((p) => p.y)) + 40
const w = Math.round(maxX - minX)
const h = Math.round(maxY - minY)

const circles = (points, fill, r, opacity = 1) => points
  .map((p) => `<circle cx="${(p.x - minX).toFixed(1)}" cy="${(p.y - minY).toFixed(1)}" r="${r}" fill="${fill}" fill-opacity="${opacity}"/>`)
  .join('')
const statics = (schema.staticObjects ?? []).map((o) => `<rect x="${(o.x - minX).toFixed(1)}" y="${(o.y - minY).toFixed(1)}" width="${o.width}" height="${o.height}" fill="#2b3550" stroke="#4b5877" rx="8"/><text x="${(o.x - minX + 10).toFixed(1)}" y="${(o.y - minY + 22).toFixed(1)}" fill="#8f9cb8" font-size="15">${o.text ?? o.kind}</text>`).join('')

const html = `<!doctype html><meta charset="utf-8">
<style>body{margin:0;background:#12141a;color:#e6e9ef;font:14px/1.45 "Segoe UI",system-ui,sans-serif}
header{padding:16px 24px} .k{color:#9aa4b2} b{color:#fff}
.legend{display:flex;gap:24px;padding:0 24px 12px;font-size:13px}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}
svg{display:block;margin:0 24px 24px;background:#171a22;border-radius:12px}</style>
<header><b>Вавилон — схема NABILET поверх hallplan Яндекса</b><br>
<span class="k">масштаб ${scale.toFixed(4)} · известных мест ${pairs.length} · худшее расхождение ${worst.toFixed(1)} px · мест в схеме ${schemaSeats.length}</span></header>
<div class="legend"><span><i class="dot" style="background:#5b6b8c"></i>места схемы NABILET (259)</span>
<span><i class="dot" style="background:#ff4d6d"></i>проданные места из Яндекса (координаты источника)</span></div>
<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">${statics}${circles(schemaSeats, '#5b6b8c', 5)}${circles(srcPoints, '#ff4d6d', 4, 0.95)}</svg>`

writeFileSync(OUT, html)
console.log(`scale=${scale.toFixed(4)} pairs=${pairs.length} worst=${worst.toFixed(2)}px schema=${schemaSeats.length} src=${srcPoints.length}`)
