/** Проверка: есть ли в черновике пред-существующие наложения мест (правило сервера: |dx|<16 && |dy|<16 в canvas-пространстве). */
const BASE = process.argv[2] ?? 'http://127.0.0.1:8090'
const PID = process.argv[3] ?? '01M760QC8XAMPFJT60CT1XX5WR'
const login = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})
const TOKEN = (await login.json())?.data?.token
const res = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, {
  headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' },
})
const j = await res.json()
const draft = (j?.data ?? []).find((v) => v.status === 'draft')
if (!draft) { console.log('черновика нет'); process.exit(0) }
const sectors = draft.schema?.sectors ?? []
console.log(`секторов: ${sectors.length}, мест: ${sectors.reduce((n, s) => n + (s.seats?.length ?? 0), 0)}`)
// Простая проверка O(n^2) по парам секторов: строим глобальный список (cx, cy, метка)
const all = []
for (const s of sectors) for (const seat of s.seats ?? []) all.push({ x: s.x + seat.x, y: s.y + seat.y, label: `${s.name} · ряд ${seat.row} · ${seat.number ?? seat.num ?? ''}` })
const overlaps = []
for (let i = 0; i < all.length; i += 1) {
  for (let k = i + 1; k < all.length; k += 1) {
    if (Math.abs(all[i].x - all[k].x) < 16 && Math.abs(all[i].y - all[k].y) < 16) {
      overlaps.push(`${all[i].label} ↔ ${all[k].label} (dx=${Math.abs(all[i].x - all[k].x)}, dy=${Math.abs(all[i].y - all[k].y)})`)
    }
  }
}
console.log(`пар-наложений: ${overlaps.length}`)
console.log(overlaps.slice(0, 15).join('\n'))
