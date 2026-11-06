/**
 * Проверка схемы зала по КОНТУРУ из hallplan Яндекса.
 *
 * Яндекс отдаёт для каждого уровня поле `outline` — SVG-путь реального
 * контура зала. Это независимый от мест источник истины: контур есть даже
 * там, где все места распроданы и координат для сверки нет.
 *
 * Скрипт раскладывает контур в полигон, переносит его в систему координат
 * схемы (масштаб + сдвиг выводятся из сопоставленных мест), считает места
 * ВНЕ контура и рисует всё в один SVG: контур серым, места внутри — зелёные,
 * снаружи — красные. «Место за контуром» — это и есть визуальный «места как
 * попало»: ряд уехал за границу зала.
 *
 * Запуск:
 *   node .shots/hallplan-outline-check.mjs <hallplan.json> <schema.json> <out.html>
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { execFileSync } from 'node:child_process'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
const [, , PLAN, SCHEMA, OUT] = process.argv
if (!OUT) {
  console.error('нужно: <hallplan.json> <schema.json> <out.html>')
  process.exit(1)
}

const plan = JSON.parse(readFileSync(PLAN, 'utf8')).result.hallplan
const schema = JSON.parse(readFileSync(SCHEMA, 'utf8').trim())

/* ── Контур: SVG-путь → полигон ────────────────────────────────────────── */

/** Разбирает подмножество path-синтаксиса, которое использует Яндекс: M/L/C/Z. */
function flattenPath(d) {
  const tokens = d.match(/[MLCZmlcz]|-?\d*\.?\d+(?:e-?\d+)?/g) ?? []
  const rings = []
  let ring = []
  let cur = [0, 0]
  let i = 0
  const num = () => Number(tokens[i++])
  while (i < tokens.length) {
    const cmd = tokens[i++]
    if (cmd === 'M' || cmd === 'm') {
      if (ring.length > 2) rings.push(ring)
      const x = num()
      const y = num()
      cur = cmd === 'm' ? [cur[0] + x, cur[1] + y] : [x, y]
      ring = [cur.slice()]
    } else if (cmd === 'L' || cmd === 'l') {
      const x = num()
      const y = num()
      cur = cmd === 'l' ? [cur[0] + x, cur[1] + y] : [x, y]
      ring.push(cur.slice())
    } else if (cmd === 'C' || cmd === 'c') {
      const p = []
      for (let k = 0; k < 6; k += 1) p.push(num())
      const abs = cmd === 'c' ? [p[0] + cur[0], p[1] + cur[1], p[2] + cur[0], p[3] + cur[1], p[4] + cur[0], p[5] + cur[1]] : p
      const [x0, y0] = cur
      // Кубическая кривая: 16 отрезков достаточно, чтобы ошибка была <0.2 px.
      for (let s = 1; s <= 16; s += 1) {
        const t = s / 16
        const u = 1 - t
        ring.push([
          u * u * u * x0 + 3 * u * u * t * abs[0] + 3 * u * t * t * abs[2] + t * t * t * abs[4],
          u * u * u * y0 + 3 * u * u * t * abs[1] + 3 * u * t * t * abs[3] + t * t * t * abs[5],
        ])
      }
      cur = [abs[4], abs[5]]
    } else if (cmd === 'Z' || cmd === 'z') {
      if (ring.length > 2) rings.push(ring)
      ring = []
    } else {
      // Неизвестная команда — числа без команды трактуем как продолжение L.
      const x = Number(cmd)
      const y = num()
      cur = [x, y]
      ring.push(cur.slice())
    }
  }
  if (ring.length > 2) rings.push(ring)
  return rings
}

/** Точка внутри полигона (ray casting). */
function inside(point, rings) {
  let hit = false
  for (const ring of rings) {
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i, i += 1) {
      const [xi, yi] = ring[i]
      const [xj, yj] = ring[j]
      if (yi > point[1] !== yj > point[1] && point[0] < ((xj - xi) * (point[1] - yi)) / (yj - yi) + xi) {
        hit = !hit
      }
    }
  }
  return hit
}

/* ── Сопоставление мест: масштаб и сдвиг ───────────────────────────────── */

const source = new Map()
for (const level of plan.levels) {
  for (const item of level.seats ?? []) {
    source.set(`${level.id}:${item.seat.row}:${item.seat.place}`, {
      x: Number(item.seat.xCoord),
      y: Number(item.seat.yCoord),
      levelId: level.id,
    })
  }
}

// Уровень сектора: сначала по id (`level-<id>-row-…`), иначе по имени —
// конвертер называет сектор именем уровня Яндекса («Партер», «Балкон»).
const levelByName = new Map(plan.levels.map((l) => [String(l.name).trim().toLowerCase(), String(l.id)]))
const levelOf = (sector) => {
  if (sector.id.startsWith('level-')) return sector.id.slice(6).split('-row-')[0]
  const byName = levelByName.get(String(sector.name ?? '').trim().toLowerCase())
  return byName ?? null
}

const seats = []
for (const sector of schema.sectors) {
  const levelId = levelOf(sector)
  for (const seat of sector.seats) {
    seats.push({
      levelId,
      row: seat.row,
      number: seat.number,
      x: sector.x + seat.x + 8,
      y: sector.y + seat.y + 8,
    })
  }
}

const pairs = []
for (const seat of seats) {
  const src = source.get(`${seat.levelId}:${seat.row}:${seat.number}`)
  if (src) pairs.push({ sx: src.x, sy: src.y, dx: seat.x, dy: seat.y })
}
if (pairs.length < 3) {
  console.error('слишком мало сопоставленных мест — не вывести масштаб/сдвиг')
  process.exit(1)
}

// Масштаб — МНК-наклон dx(sx) и dy(sy): сдвиг при этом выпадает сам, а
// медиана отношений «на глаз» даёт смещение из-за того, что центр схемы
// сдвинут относительно центра источника.
const mean = (v) => v.reduce((s, x) => s + x, 0) / v.length
const slope = (src, dst) => {
  const ms = mean(src)
  const md = mean(dst)
  let num = 0
  let den = 0
  for (let i = 0; i < src.length; i += 1) {
    num += (src[i] - ms) * (dst[i] - md)
    den += (src[i] - ms) ** 2
  }
  return den === 0 ? 1 : num / den
}
const scale = (slope(pairs.map((p) => p.sx), pairs.map((p) => p.dx))
  + slope(pairs.map((p) => p.sy), pairs.map((p) => p.dy))) / 2
const median = (v) => [...v].sort((a, b) => a - b)[Math.floor(v.length / 2)]
const shiftX = median(pairs.map((p) => p.dx - scale * p.sx))
const shiftY = median(pairs.map((p) => p.dy - scale * p.sy))
const toSchema = ([x, y]) => [scale * x + shiftX, scale * y + shiftY]

/* ── Проверка ──────────────────────────────────────────────────────────── */

const outlines = new Map()
for (const level of plan.levels) {
  if (!level.outline) continue
  outlines.set(String(level.id), flattenPath(level.outline).map((ring) => ring.map(toSchema)))
}

const outside = []
for (const seat of seats) {
  const rings = outlines.get(String(seat.levelId))
  if (!rings) continue
  if (!inside([seat.x, seat.y], rings)) outside.push(seat)
}
const checked = seats.filter((s) => outlines.has(String(s.levelId))).length

console.log(`масштаб ${scale.toFixed(4)}, сдвиг dx=${shiftX.toFixed(1)} dy=${shiftY.toFixed(1)}`)
console.log(`мест в схеме: ${seats.length}, проверено по контуру: ${checked}`)
console.log(`вне контура: ${outside.length} (${((100 * outside.length) / Math.max(1, checked)).toFixed(1)}%)`)
const byLevel = new Map()
for (const s of outside) byLevel.set(s.levelId, (byLevel.get(s.levelId) ?? 0) + 1)
for (const [levelId, n] of byLevel) {
  const name = plan.levels.find((l) => String(l.id) === String(levelId))?.name ?? levelId
  const total = seats.filter((s) => String(s.levelId) === String(levelId)).length
  console.log(`   ${name}: ${n} из ${total}`)
}
if (outside.length) {
  const rows = [...new Set(outside.map((s) => `${s.levelId}:ряд ${s.row}`))].sort()
  console.log(`   затронуты: ${rows.slice(0, 12).join(', ')}${rows.length > 12 ? ' …' : ''}`)
}

/* ── Картинка ──────────────────────────────────────────────────────────── */

const allX = []
const allY = []
for (const rings of outlines.values()) {
  for (const ring of rings) {
    for (const [x, y] of ring) {
      allX.push(x)
      allY.push(y)
    }
  }
}
for (const s of seats) {
  allX.push(s.x)
  allY.push(s.y)
}
const pad = 24
const minX = Math.min(...allX) - pad
const minY = Math.min(...allY) - pad
const w = Math.round(Math.max(...allX) - minX + pad)
const h = Math.round(Math.max(...allY) - minY + pad)

const pathOf = (rings) =>
  rings.map((ring) => `M${ring.map(([x, y]) => `${(x - minX).toFixed(1)} ${(y - minY).toFixed(1)}`).join('L')}Z`).join(' ')

const outlineSvg = [...outlines.values()].map((rings) => `<path d="${pathOf(rings)}" fill="none" stroke="#94a3b8" stroke-width="2"/>`).join('')
const dot = (s, color) => `<circle cx="${(s.x - minX).toFixed(1)}" cy="${(s.y - minY).toFixed(1)}" r="3.4" fill="${color}"/>`
const seatsSvg = seats.map((s) => dot(s, '#1f6feb')).join('')
const outsideSvg = outside.map((s) => dot(s, '#e03131')).join('')

const html = `<!doctype html><meta charset="utf-8">
<style>
  body{margin:0;background:#0f1116;color:#e6e9ef;font:14px/1.45 "Segoe UI",system-ui,sans-serif;padding:18px}
  h1{font-size:15px;margin:0 0 4px}
  p{margin:0 0 12px;color:#9aa4b2;font-size:13px}
  .legend{display:flex;gap:16px;margin:10px 0 0;font-size:12px;color:#c3cad8}
  .legend i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
  svg{display:block;background:#fff;border-radius:10px;max-width:100%;height:auto}
</style>
<h1>${PLAN.split(/[\\/]/).pop()} — контур Яндекса против схемы NABILET</h1>
<p>масштаб ${scale.toFixed(3)} · мест ${seats.length} · вне контура <b style="color:${outside.length ? '#ff8787' : '#69db7c'}">${outside.length}</b> из ${checked}</p>
<svg viewBox="0 0 ${w} ${h}" width="${Math.min(w, 1100)}">${outlineSvg}${seatsSvg}${outsideSvg}</svg>
<div class="legend">
  <span><i style="background:#94a3b8"></i>контур уровня (Яндекс)</span>
  <span><i style="background:#1f6feb"></i>место внутри контура</span>
  <span><i style="background:#e03131"></i>место вне контура</span>
</div>`

writeFileSync(OUT, html)
const png = OUT.replace(/\.html$/, '.png')
const abs = (p) => resolve(p).replace(/\\/g, '/')
try {
  // Chrome пишет скриншот относительно СВОЕГО cwd, поэтому только абсолютный путь.
  execFileSync(CHROME, [
    '--headless=new',
    `--screenshot=${resolve(png).replace(/\\/g, '/')}`,
    `--window-size=${Math.min(1200, w + 60)},${Math.min(1600, h + 120)}`,
    '--hide-scrollbars',
    `file:///${abs(OUT)}`,
  ], { stdio: 'ignore', timeout: 60000 })
  console.log(`картинка: ${png}`)
} catch (e) {
  console.log(`png не снят: ${e.message}`)
}
