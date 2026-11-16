/** Проверить позицию места table-2-63-seat-1 в черновике на сервере. */
const BASE = 'http://127.0.0.1:8090'
const PID = '01M760QC8XAMPFJT60CT1XX5WR'
const login = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }) })
const TOKEN = (await login.json())?.data?.token
const res = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, { headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' } })
const j = await res.json()
const draft = (j?.data ?? []).find((v) => v.status === 'draft')
const sectors = draft?.schema?.sectors ?? []
for (const s of sectors) {
  for (const seat of s.seats ?? []) {
    if (seat.id === 'table-2-63-seat-1') console.log(`draft: ${s.name} · ${seat.id} local=(${seat.x},${seat.y}) sector=(${s.x},${s.y})`)
  }
}
console.log('draft id:', draft?.id, 'revision:', draft?.revision ?? '?')
