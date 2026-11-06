/**
 * «Было / стало»: две версии схемы зала в одном масштабе.
 *
 * Считает точки мест в координатах холста и рисует панели так, чтобы оба
 * плана были одного размера — иначе разница в геометрии не читается.
 *
 * Запуск: node .shots/hall-schema-before-after.mjs <before.json> <after.json> <out.html> [titleBefore] [titleAfter]
 */
import { readFileSync, writeFileSync } from 'node:fs'

const [, , BEFORE, AFTER, OUT, TITLE_BEFORE = 'Было', TITLE_AFTER = 'Стало'] = process.argv
const load = (file) => JSON.parse(readFileSync(file, 'utf8').trim())

function dots(schema) {
  const out = []
  for (const sector of schema.sectors) {
    for (const seat of sector.seats) out.push({ x: sector.x + seat.x + 8, y: sector.y + seat.y + 8 })
  }
  return out
}

const before = load(BEFORE)
const after = load(AFTER)
const a = dots(before)
const b = dots(after)

const all = [...a, ...b]
const minX = Math.min(...all.map((p) => p.x))
const minY = Math.min(...all.map((p) => p.y))
const maxX = Math.max(...all.map((p) => p.x))
const maxY = Math.max(...all.map((p) => p.y))
const w = Math.round(maxX - minX) + 80
const h = Math.round(maxY - minY) + 80

function panel(schema, points, title, note) {
  const circles = points.map((p) => `<circle cx="${(p.x - minX + 40).toFixed(1)}" cy="${(p.y - minY + 40).toFixed(1)}" r="5" fill="#8b9ac0"/>`).join('')
  const statics = (schema.staticObjects ?? []).map((o) => `<rect x="${(o.x - minX + 40).toFixed(1)}" y="${(o.y - minY + 40).toFixed(1)}" width="${o.width}" height="${o.height}" fill="#20283c" stroke="#3b4766" rx="6"/>`).join('')
  return `<figure><figcaption><b>${title}</b><br><span>${note}</span></figcaption>
<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">${statics}${circles}</svg></figure>`
}

const html = `<!doctype html><meta charset="utf-8">
<style>body{margin:0;background:#12141a;color:#e6e9ef;font:14px/1.45 "Segoe UI",system-ui,sans-serif}
main{display:flex;gap:24px;padding:24px;align-items:flex-start}
figure{margin:0;background:#171a22;border:1px solid #262c3a;border-radius:12px;padding:14px}
figcaption{margin-bottom:10px} figcaption span{color:#9aa4b2;font-size:12px}
svg{display:block;border-radius:8px}</style>
<main>
${panel(before, a, TITLE_BEFORE, `секторов ${before.sectors.length}, мест ${a.length}`)}
${panel(after, b, TITLE_AFTER, `секторов ${after.sectors.length}, мест ${b.length}`)}
</main>`

writeFileSync(OUT, html)
console.log(`${OUT}: before ${a.length} мест, after ${b.length} мест, ${w}×${h}`)
