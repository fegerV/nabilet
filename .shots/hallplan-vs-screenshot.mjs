/**
 * Схема зала рядом со скриншотом витрины Яндекса.
 *
 * Рисует места из schema_json точками, окрашенными по цене (как в виджете
 * Афиши), и ставит картинку рядом со скриншотом — глазами видно, совпадает
 * ли рисунок зала, а не только цифры.
 *
 * Запуск: node .shots/hallplan-vs-screenshot.mjs <schema.json> <screenshot.png> <out.html> [подпись]
 */
import { readFileSync, writeFileSync } from 'node:fs'

const [, , SCHEMA, SHOT, OUT, TITLE = 'Схема зала'] = process.argv
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8').trim())

const PALETTE = ['#2f9e44', '#3b82f6', '#f59f00', '#e8590c', '#c2255c', '#7048e8']

const seats = []
for (const sector of schema.sectors) {
  for (const seat of sector.seats) {
    seats.push({
      x: sector.x + seat.x + 8,
      y: sector.y + seat.y + 8,
      price: Number(seat.priceMinor ?? sector.rowPrices?.[seat.row] ?? sector.priceMinor ?? 0),
      row: seat.row,
      number: seat.number,
    })
  }
}
const prices = [...new Set(seats.map((s) => s.price))].sort((a, b) => a - b)
const colorOf = (price) => PALETTE[Math.max(0, prices.indexOf(price)) % PALETTE.length]

const minX = Math.min(...seats.map((s) => s.x))
const minY = Math.min(...seats.map((s) => s.y))
const maxX = Math.max(...seats.map((s) => s.x))
const maxY = Math.max(...seats.map((s) => s.y))
const pad = 40
const w = Math.round(maxX - minX + pad * 2)
const h = Math.round(maxY - minY + pad * 2)
// Радиус точки подбираем от габарита: у больших залов иначе всё сливается.
const r = Math.max(1.6, Math.min(5, Math.min(w, h) / 240))

const dots = seats.map((s) => `<circle cx="${(s.x - minX + pad).toFixed(1)}" cy="${(s.y - minY + pad).toFixed(1)}" r="${r}" fill="${colorOf(s.price)}"/>`).join('')
const legend = prices.map((p) => `<span><i style="background:${colorOf(p)}"></i>${(p / 100).toLocaleString('ru-RU')} ₽</span>`).join('')

const html = `<!doctype html><meta charset="utf-8">
<style>
  body{margin:0;background:#0f1116;color:#e6e9ef;font:14px/1.45 "Segoe UI",system-ui,sans-serif}
  main{display:flex;gap:20px;padding:20px;align-items:flex-start}
  figure{margin:0;background:#161a22;border:1px solid #262c3a;border-radius:12px;padding:12px}
  figcaption{margin-bottom:10px;font-size:13px;color:#9aa4b2}
  figcaption b{color:#fff;font-size:14px}
  img{display:block;max-width:1180px;border-radius:8px}
  svg{display:block;background:#fff;border-radius:8px}
  .legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;font-size:12px;color:#c3cad8}
  .legend i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px}
</style>
<main>
  <figure>
    <figcaption><b>Витрина Яндекса</b><br>${TITLE}</figcaption>
    <img src="file:///${SHOT.replace(/\\/g, '/')}" alt="screenshot">
  </figure>
  <figure>
    <figcaption><b>Схема NABILET</b><br>${seats.length} мест, габарит ${w}×${h}</figcaption>
    <svg viewBox="0 0 ${w} ${h}" width="${Math.min(w, 900)}" height="${Math.min(w, 900) * h / w}">${dots}</svg>
    <div class="legend">${legend}</div>
  </figure>
</main>`

writeFileSync(OUT, html)
console.log(`${OUT}: ${seats.length} мест, ${w}×${h}`)
