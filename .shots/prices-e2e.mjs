/**
 * E2E ценового потока: мероприятие → сеанс → инвентарь (цены из схемы) →
 * PATCH /inventory/sessions/{id}/prices (session_only true/false).
 * Запуск: node .shots/prices-e2e.mjs
 */
const BASE = 'http://127.0.0.1:8090'
const H = await (async () => {
  const r = await fetch(`${BASE}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@nabilet.local', password: 'admin123' }) })
  const j = await r.json()
  return { Authorization: `Bearer ${j.data.token}`, 'Content-Type': 'application/json', Accept: 'application/json' }
})()

const fails = []
const ok = (cond, label, detail = '') => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? ' — ' + detail : ''}`)
  if (!cond) fails.push(label)
}

// 1. Зал филармонии (schema v1 published, 872 места)
const halls = (await fetch(`${BASE}/api/v1/halls?per_page=100`, { headers: H }).then(r => r.json()))
const hallList = Array.isArray(halls.data) ? halls.data : halls.data.data
const venues = (await fetch(`${BASE}/api/v1/venues?per_page=100`, { headers: H }).then(r => r.json()))
const venueList = Array.isArray(venues.data) ? venues.data : venues.data.data
const filVenue = venueList.find(v => /филармони/i.test(v.name))
const filHall = hallList.find(h => h.venue_id === filVenue?.id)
if (!filHall) { console.log('FAIL  зал филармонии не найден'); process.exit(1) }
console.log('зал:', filHall.id, filHall.name, '| venue:', filVenue.id, filVenue.name)

// 2. Мероприятие
const slug = `prices-e2e-${Date.now()}`
const evRes = await fetch(`${BASE}/api/v1/events`, { method: 'POST', headers: H, body: JSON.stringify({ title: 'E2E цены', slug, venue_id: filVenue.id, description: 'Проверка ценового потока' }) })
const evJson = await evRes.json()
ok(evRes.status === 201 || evRes.status === 200, 'создание мероприятия', `HTTP ${evRes.status}`)
const evId = evJson.data?.id

// 3. Сеанс
const sesRes = await fetch(`${BASE}/api/v1/sessions`, { method: 'POST', headers: H, body: JSON.stringify({ event_id: evId, hall_id: filHall.id, starts_at: '2026-12-20T19:00:00+05:00' }) })
const sesJson = await sesRes.json()
ok(sesRes.status === 201 || sesRes.status === 200, 'создание сеанса', `HTTP ${sesRes.status}`)
const sesId = sesJson.data?.id
ok(Number.isInteger(sesId), 'у сеанса есть числовой id', String(sesId))

// 4. Инвентарь: цены должны прийти из схемы (ряды филармонии имели price_amount)
const invPage1 = await fetch(`${BASE}/api/v1/inventory?session_id=${sesId}&per_page=500`, { headers: H }).then(r => r.json())
const invTotal = invPage1.meta?.total ?? invPage1.data?.total ?? 0
ok(invTotal === 872, 'инвентарь сгенерирован из схемы (872 позиции)', `total=${invTotal}`)
// `data` — LengthAwarePaginator: элементы лежат в data.data.
const items = Array.isArray(invPage1.data) ? invPage1.data : (invPage1.data?.data ?? [])
const withPrice = items.filter(i => Number(i.price_amount) > 0)
ok(withPrice.length > 0, 'в инвентаре есть ненулевые цены', `${withPrice.length}/${items.length}`)
const prices = [...new Set(items.map(i => Number(i.price_amount)))].sort((a, b) => a - b)
console.log('   цены (копейки):', prices.slice(0, 10).join(', '), prices.length > 10 ? `… всего ${prices.length} уникальных` : '')

// 5. Группировка по рядам: выбрать ряд с одинаковой ценой у всех мест
const byRow = new Map()
for (const it of items) {
  const rid = it.seat?.row_id
  if (!rid) continue
  const e = byRow.get(rid) ?? { ids: [], prices: new Set(), sector: it.seat?.sector_name, row: it.seat?.row_number }
  e.ids.push(it.id); e.prices.add(Number(it.price_amount))
  byRow.set(rid, e)
}
const uniformRow = [...byRow.entries()].find(([, e]) => e.prices.size === 1)
if (!uniformRow) { console.log('FAIL  нет однородного ряда для теста'); process.exit(1) }
const [rowId, rowEntry] = uniformRow
const oldPrice = [...rowEntry.prices][0]
console.log('тест-ряд:', rowId, rowEntry.sector, 'ряд', rowEntry.row, '| мест:', rowEntry.ids.length, '| цена:', oldPrice)

// 6. PATCH session_only=true → инвентарь меняется, сетка зала НЕ трогается
const NEW1 = oldPrice + 12345
const p1 = await fetch(`${BASE}/api/v1/inventory/sessions/${sesId}/prices`, { method: 'PATCH', headers: H, body: JSON.stringify({ rows: [{ row_id: rowId, price_amount: NEW1 }], session_only: true }) })
const p1Json = await p1.json()
ok(p1.status === 200, 'PATCH prices (session_only=true)', `HTTP ${p1.status}`)
ok(p1Json.data?.items_updated === rowEntry.ids.length, 'обновлены позиции ряда', JSON.stringify(p1Json.data))
const invAfter1 = await fetch(`${BASE}/api/v1/inventory?session_id=${sesId}&per_page=500`, { headers: H }).then(r => r.json())
const itemsAfter1 = Array.isArray(invAfter1.data) ? invAfter1.data : (invAfter1.data?.data ?? [])
const rowAfter1 = itemsAfter1.filter(i => i.seat?.row_id === rowId)
ok(rowAfter1.every(i => Number(i.price_amount) === NEW1), 'цены ряда изменились в инвентаре', `новая=${rowAfter1[0]?.price_amount}`)

// 7. PATCH session_only=false → сетка зала тоже обновляется (проверим через БД)
const NEW2 = oldPrice + 54321
const p2 = await fetch(`${BASE}/api/v1/inventory/sessions/${sesId}/prices`, { method: 'PATCH', headers: H, body: JSON.stringify({ rows: [{ row_id: rowId, price_amount: NEW2 }], session_only: false }) })
ok(p2.status === 200, 'PATCH prices (session_only=false)', `HTTP ${p2.status}`)

// 8. Некорректный запрос: цена отрицательная → 422
const p3 = await fetch(`${BASE}/api/v1/inventory/sessions/${sesId}/prices`, { method: 'PATCH', headers: H, body: JSON.stringify({ rows: [{ row_id: rowId, price_amount: -5 }] }) })
ok(p3.status === 422, 'отрицательная цена отвергается 422', `HTTP ${p3.status}`)

// 9а. Несуществующий ряд → валидация `exists:` отвергает 422 (fail-closed).
const p4 = await fetch(`${BASE}/api/v1/inventory/sessions/${sesId}/prices`, { method: 'PATCH', headers: H, body: JSON.stringify({ rows: [{ row_id: 99999999, price_amount: 100 }] }) })
ok(p4.status === 422, 'несуществующий ряд → 422', `HTTP ${p4.status}`)

// 9б. РЕАЛЬНЫЙ ряд ДРУГОГО зала → сервис отвечает 404 (ряд не относится к сеансу).
const otherHall = hallList.find(h => h.id !== filHall.id)
const otherSes = await fetch(`${BASE}/api/v1/sessions`, { method: 'POST', headers: H, body: JSON.stringify({ event_id: evId, hall_id: otherHall.id, starts_at: '2026-12-21T19:00:00+05:00' }) }).then(r => r.json())
const otherRowId = await fetch(`${BASE}/api/v1/inventory?session_id=${otherSes.data.id}&per_page=1`, { headers: H }).then(r => r.json())
  .then(j => j.data?.data?.[0]?.seat?.row_id ?? null)
if (otherRowId) {
  const p5 = await fetch(`${BASE}/api/v1/inventory/sessions/${sesId}/prices`, { method: 'PATCH', headers: H, body: JSON.stringify({ rows: [{ row_id: otherRowId, price_amount: 100 }] }) })
  ok(p5.status === 404, 'ряд чужого сеанса → 404', `HTTP ${p5.status}`)
} else {
  console.log('SKIP  9б: у второго зала нет инвентаря')
}
await fetch(`${BASE}/api/v1/sessions/${otherSes.data.id}`, { method: 'DELETE', headers: H })

// 10. Уборка: удалить сеанс и мероприятие
const delS = await fetch(`${BASE}/api/v1/sessions/${sesId}`, { method: 'DELETE', headers: H })
const delE = await fetch(`${BASE}/api/v1/events/${evId}`, { method: 'DELETE', headers: H })
ok(delS.status === 200 || delS.status === 204, 'сеанс удалён', `HTTP ${delS.status}`)
ok(delE.status === 200 || delE.status === 204, 'мероприятие удалено', `HTTP ${delE.status}`)

console.log(fails.length === 0 ? '\n[✓] ВСЕ ПРОВЕРКИ ПРОЙДЕНЫ' : `\n[!] ПРОВАЛЕНО: ${fails.join('; ')}`)
process.exit(fails.length === 0 ? 0 : 1)
