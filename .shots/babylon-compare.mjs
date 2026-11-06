/**
 * Сравнение «Яндекс Афиша vs текущая схема NABILET» для зала Вавилон.
 *
 * Рисует две панели в одном SVG-координатном пространстве, чтобы видно было
 * не «красиво/некрасиво», а именно расхождение геометрии: слева исходные
 * координаты мест из hallplan Яндекса, справа — то, что лежит в опубликованной
 * версии схемы NABILET. Места — точки, цвет = уровень (тип стола).
 *
 * Запуск: node .shots/babylon-compare.mjs [out.html]
 */
import { readFileSync, writeFileSync } from 'node:fs'

const SRC = process.argv[3] ?? 'C:/Users/Professional/Downloads/Вавилон.json'
const SCHEMA = process.argv[4] ?? 'outputs/hallplan-vavilon-schema-v2.json'
const OUT = process.argv[2] ?? 'outputs/babylon-compare.html'
const SRC_SCALE = 1.2

const COLORS = ['#e8556d', '#4ea8de', '#f4a259', '#8ac926', '#b07cf5', '#ffd166']

const src = JSON.parse(readFileSync(SRC, 'utf8')).result.hallplan
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8').trim())

// ── источник ───────────────────────────────────────────────────────────────
const srcDots = []
let li = 0
for (const level of src.levels) {
  if (level.admission) continue
  const color = COLORS[li % COLORS.length]
  for (const seat of level.seats) {
    srcDots.push({ x: seat.seat.xCoord * SRC_SCALE, y: seat.seat.yCoord * SRC_SCALE, color, level: level.name })
  }
  li += 1
}

// ── текущая схема ──────────────────────────────────────────────────────────
const outDots = []
const outStatic = []
for (const sector of schema.sectors) {
  const color = COLORS[(sector.name.includes('VIP') ? 3 : 0) % COLORS.length]
  for (const seat of sector.seats) outDots.push({ x: sector.x + seat.x, y: sector.y + seat.y, color, level: sector.name })
}
for (const obj of schema.staticObjects ?? []) outStatic.push(obj)

function bbox(dots) {
  const xs = dots.map((d) => d.x)
  const ys = dots.map((d) => d.y)
  return { minX: Math.min(...xs), minY: Math.min(...ys), maxX: Math.max(...xs), maxY: Math.max(...ys) }
}

function panel(dots, statics, title, note) {
  const b = bbox(dots)
  const pad = 60
  const w = Math.round(b.maxX - b.minX + pad * 2)
  const h = Math.round(b.maxY - b.minY + pad * 2)
  const circles = dots.map((d) => `<circle cx="${(d.x - b.minX + pad).toFixed(1)}" cy="${(d.y - b.minY + pad).toFixed(1)}" r="6" fill="${d.color}" />`).join('')
  const rects = (statics ?? []).map((o) => `<rect x="${(o.x - b.minX + pad).toFixed(1)}" y="${(o.y - b.minY + pad).toFixed(1)}" width="${o.width}" height="${o.height}" fill="none" stroke="#9aa4b2" stroke-dasharray="6 4" rx="6" /><text x="${(o.x - b.minX + pad + 8).toFixed(1)}" y="${(o.y - b.minY + pad + 20).toFixed(1)}" fill="#9aa4b2" font-size="14">${o.text ?? o.kind}</text>`).join('')
  return `<figure>
    <figcaption><b>${title}</b><br><span>${note}</span></figcaption>
    <svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">${rects}${circles}</svg>
  </figure>`
}

const srcBox = bbox(srcDots)
const outBox = bbox(outDots)
const srcNote = `мест: ${srcDots.length}, габарит ${Math.round(srcBox.maxX - srcBox.minX)}×${Math.round(srcBox.maxY - srcBox.minY)}`
const outNote = `мест: ${outDots.length}, габарит ${Math.round(outBox.maxX - outBox.minX)}×${Math.round(outBox.maxY - outBox.minY)}`

const html = `<!doctype html><meta charset="utf-8">
<style>
  body{margin:0;background:#12141a;color:#e6e9ef;font:14px/1.4 "Segoe UI",system-ui,sans-serif}
  main{display:flex;gap:32px;padding:24px;align-items:flex-start}
  figure{margin:0;background:#1a1d26;border:1px solid #2a2f3d;border-radius:12px;padding:16px}
  figcaption{margin-bottom:12px}
  figcaption span{color:#9aa4b2;font-size:12px}
  svg{display:block}
</style>
<main>
${panel(srcDots, [], 'Яндекс Афиша (источник)', srcNote)}
${panel(outDots, outStatic, 'NABILET (опубликованная схема v23)', outNote)}
</main>`

writeFileSync(OUT, html)
console.log(OUT, 'src', srcDots.length, 'out', outDots.length)
