// Пройти события kassa-ugra (SSR), извлечь id→{title, venue, date}.
import { readFileSync, writeFileSync } from 'node:fs'
const ids = readFileSync('.shots/kassa-ugra-ids.txt', 'utf8').trim().split('\n')
const out = new Map()
async function fetchOne(id) {
  try {
    const res = await fetch(`https://surgut.kassa-ugra.ru/events/${id}`, { headers: { 'User-Agent': 'Mozilla/5.0 Chrome/126.0' } })
    if (!res.ok) return { id, error: res.status }
    const html = await res.text()
    const title = html.match(/<title>([^<]+)<\/title>/)?.[1] ?? ''
    // после вхождения id в payload берём ближайшие quoted-строки
    const idx = html.indexOf(`","${id}"`) >= 0 ? html.indexOf(`","${id}"`) + 1 : html.indexOf(`"${id}"`)
    let venue = null, date = null
    if (idx >= 0) {
      const seg = html.slice(idx, idx + 1200)
      const strs = [...seg.matchAll(/"((?:[^"\\]|\\.)*)"/g)].map(m => m[1].replace(/\\u003C/g, '<'))
      // эвристика: строка с «дворец/театр/центр/бар/клуб/парк/зал/паб/цирк/ресторан/молл/дворец» и не адрес
      const venueRe = /(дворец|театр|центр|бар|клуб|парк|зал|паб|цирк|ресторан|молл|филармони|кафе|house|hall|pub|bowl|квартал|усадьба|база|аквапарк|шапито|город)/i
      const addrRe = /(ул\.|просп|тракт|улица|д\.|\d{6})/i
      for (let i = 0; i < strs.length; i++) {
        if (venueRe.test(strs[i]) && !addrRe.test(strs[i]) && strs[i].length > 5 && strs[i] !== title) {
          // в payload перед площадкой бывает id строки — берём первое похожее ПОСЛЕ заголовка события
          if (strs[i].startsWith(title.slice(0, 12))) continue
          venue = strs[i]
          date = strs[i + 1]?.match(/^\d{4}-\d{2}-\d{2}/) ? strs[i + 1] : (strs.find(s => /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(s)) ?? null)
          break
        }
      }
    }
    return { id, title, venue, date }
  } catch (e) { return { id, error: String(e).slice(0, 80) } }
}
const CONC = 10
const results = []
for (let i = 0; i < ids.length; i += CONC) {
  const batch = ids.slice(i, i + CONC)
  const r = await Promise.all(batch.map(fetchOne))
  results.push(...r)
  process.stdout.write(`\r${Math.min(i + CONC, ids.length)}/${ids.length}`)
}
console.log()
const byVenue = {}
for (const r of results) {
  const key = r.venue ?? '(не определено)'
  ;(byVenue[key] ??= []).push({ id: r.id, title: r.title?.slice(0, 60), date: r.date })
}
for (const [v, list] of Object.entries(byVenue).sort((a, b) => b[1].length - a[1].length)) {
  console.log(`${list.length}\t${v}`)
}
writeFileSync('.shots/kassa-ugra-events.json', JSON.stringify(byVenue, null, 1))
