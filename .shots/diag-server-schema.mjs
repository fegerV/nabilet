/** Диагностика: сравнить схему каждой версии зала с тем, что отдаёт редактор. */
import { readFileSync } from 'node:fs'
const BASE = 'http://127.0.0.1:8099/api/v1'
const lab = JSON.parse(readFileSync('.shots/hall-lab-last.json', 'utf8'))

const lj = await (await fetch(`${BASE}/auth/login`, {
  method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }),
})).json()
const TOKEN = lj.token ?? lj?.data?.token

const res = await fetch(`${BASE}/halls/${lab.banquetHall}/schema-versions`, {
  headers: { Accept: 'application/json', Authorization: `Bearer ${TOKEN}` },
})
const body = await res.json()
const list = Array.isArray(body.data) ? body.data : (body.data?.data ?? [])
console.log('версий:', list.length)
for (const v of list) {
  let s = v.schema
  if (typeof s === 'string') { try { s = JSON.parse(s) } catch { /* raw */ } }
  const sectors = s?.sectors ?? []
  console.log(`  v${v.version} ${v.status} rev=${v.revision} id=${v.id}`,
    '| keys:', s ? Object.keys(s).join(',') : 'null',
    '| sectors:', sectors.length,
    '| seats:', sectors.reduce((a, x) => a + (x.seats?.length ?? 0) + (x.rows ?? []).reduce((b, r) => b + (r.seats?.length ?? 0), 0), 0),
    '| backgrounds:', Array.isArray(s?.backgrounds) ? s.backgrounds.length : 'нет',
    '| statics:', (s?.staticObjects ?? []).length,
    '| bg:', s?.background ? 'есть' : 'null')
}
