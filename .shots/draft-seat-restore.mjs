/** Вернуть место table-2-63-seat-1 в исходную позицию черновика (33,77). */
const BASE = 'http://127.0.0.1:8090'
const PID = '01M760QC8XAMPFJT60CT1XX5WR'
const login = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }) })
const TOKEN = (await login.json())?.data?.token
const headers = { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json', Accept: 'application/json' }
const res = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, { headers })
const j = await res.json()
const draft = (j?.data ?? []).find((v) => v.status === 'draft')
if (!draft) { console.log('черновика нет'); process.exit(0) }
const sectors = draft.schema.sectors
let restored = 0
for (const s of sectors) {
  for (const seat of s.seats ?? []) {
    if (seat.id === 'table-2-63-seat-1') { seat.x = 33; seat.y = 77; restored += 1 }
  }
}
console.log('восстановлено мест:', restored)
const payload = { payload: draft.schema, version_id: draft.id }
if (draft.revision != null) payload.base_revision = draft.revision
const save = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions/draft`, {
  method: 'POST', headers, body: JSON.stringify(payload),
})
console.log('сохранение черновика:', save.status)
const check = await (await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, { headers })).json()
const d2 = (check?.data ?? []).find((v) => v.status === 'draft')
for (const s of d2?.schema?.sectors ?? []) for (const seat of s.seats ?? []) if (seat.id === 'table-2-63-seat-1') console.log(`проверка: seat-1=(${seat.x},${seat.y})`)
