/** Все версии схемы зала: статус, id, версия, позиция seat-1 в каждой. */
const BASE = 'http://127.0.0.1:8090'
const PID = '01M760QC8XAMPFJT60CT1XX5WR'
const login = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }) })
const TOKEN = (await login.json())?.data?.token
const res = await fetch(`${BASE}/api/v1/halls/${PID}/schema-versions`, { headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' } })
const j = await res.json()
for (const v of j?.data ?? []) {
  const sectors = v.schema?.sectors ?? []
  let s1 = '?'
  for (const s of sectors) for (const seat of s.seats ?? []) if (seat.id === 'table-2-63-seat-1') s1 = `${seat.x},${seat.y}`
  console.log(`v${v.version} id=${v.id} status=${v.status} rev=${v.revision ?? '?'} seat-1=(${s1}) created=${v.created_at}`)
}
