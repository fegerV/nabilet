/**
 * Импорт схемы площадки из Яндекс Афиши в NABILET через публичный API.
 *
 * Скрипт идемпотентен: площадка и зал ищутся по имени, схема всегда пишется
 * НОВЫМ черновиком и публикуется (предыдущая опубликованная версия уходит в
 * архив силами сервиса).
 *
 * Запуск: node .shots/yandex-hall-import.mjs [baseUrl] [schemaFile] [profile]
 *   profile: neftyanik (по умолчанию) или surgu.
 */
import { readFileSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const SCHEMA_FILE = process.argv[3] ?? 'outputs/hall-neftyanik-schema.json'
const PROFILE = process.argv[4] ?? (SCHEMA_FILE.toLowerCase().includes('surgu') ? 'surgu' : 'neftyanik')

const PROFILES = {
  neftyanik: {
    venue: {
      name: 'Дворец искусств Нефтяник',
      slug: 'dvorec-iskusstv-neftyanik',
      city: 'Сургут',
      region: 'Ханты-Мансийский автономный округ — Югра',
      country: 'Россия',
      address: 'Югорский тракт, 5, Сургут, ХМАО-Югра, 628415',
      latitude: 61.263151,
      longitude: 73.34784,
      description: 'Концертно-театральный зал. Схема зала импортирована из Яндекс Афиши '
        + '(сеансы 348671 и 330497): партер, приставные места, бельэтаж, балкон.',
    },
    hall: {
      name: 'Концертно-театральный зал',
      description: 'Концертно-театральный зал (ложи отдельно) — импорт сетки из Яндекс Афиши.',
    },
  },
  surgu: {
    venue: {
      name: 'Театр СурГУ',
      slug: 'teatr-surgu',
      city: 'Сургут',
      region: 'Ханты-Мансийский автономный округ — Югра',
      country: 'Россия',
      address: 'ХМАО-Югра, Сургут, просп. Ленина, 1',
      latitude: 61.239822,
      longitude: 73.410891,
      description: 'Театр Сургутского государственного университета. Площадка сохранена '
        + 'по данным Яндекс Афиши; схема зала импортирована из событий 339296, 337191, '
        + '328823, 361245, 344046 (партер 364 места, балкон 148).',
    },
    hall: {
      name: 'Основной зал',
      description: 'Основной зал Театр СурГУ — партер (дуга 16 рядов) и балкон, '
        + 'импорт сетки из Яндекс Афиши.',
    },
  },
  babylon: {
    venue: {
      name: 'Вавилон',
      slug: 'vavilon-surgut',
      city: 'Сургут',
      region: 'Ханты-Мансийский автономный округ — Югра',
      country: 'Россия',
      address: 'ХМАО-Югра, Сургут, ул. Профсоюзов, 55',
      latitude: 61.263354,
      longitude: 73.432163,
      description: 'Концертная площадка Вавилон. Схема с VIP- и боковыми столами, '
        + 'столами на танцполе, столами с ограниченной видимостью и танцполом; импорт '
        + 'выполнен по событию 359964 из Яндекс Афиши.',
    },
    hall: {
      name: 'Основной зал',
      description: 'Основной зал Вавилона — столы и танцпол, импорт из Яндекс Афиши.',
    },
  },
}
const config = PROFILES[PROFILE]
if (!config) throw new Error(`неизвестный профиль: ${PROFILE}`)
const VENUE = config.venue
const HALL = config.hall

const schema = JSON.parse(readFileSync(SCHEMA_FILE, 'utf8'))
const seatCount = schema.sectors.reduce((sum, s) => sum + (s.seats?.length ?? 0), 0)
  + schema.staticObjects
    .filter((o) => o.kind === 'standing')
    .reduce((sum, o) => sum + Math.max(0, Number(o.capacity ?? 0)), 0)

let token = null

async function api(method, path, body) {
  const res = await fetch(`${BASE}/api/v1${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const text = await res.text()
  let json = null
  try { json = text ? JSON.parse(text) : null } catch { /* оставляем сырой текст */ }
  if (!res.ok) {
    const detail = json ? JSON.stringify(json).slice(0, 900) : text.slice(0, 400)
    throw new Error(`${method} ${path} → ${res.status}: ${detail}`)
  }
  return json
}

function list(payload) {
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  if (Array.isArray(payload?.data?.data)) return payload.data.data
  return []
}

/* ── 1. Авторизация ──────────────────────────────────────────────────── */
const login = await api('POST', '/auth/login', {
  email: 'admin@nabilet.local',
  password: 'admin123',
})
token = login?.data?.token ?? login?.token
if (!token) throw new Error('не удалось авторизоваться')
console.log(`[✓] авторизованы на ${BASE}`)

/* ── 2. Площадка ─────────────────────────────────────────────────────── */
let venue = list(await api('GET', '/venues?limit=100')).find((v) => v.name === VENUE.name)
if (venue) {
  console.log(`[•] площадка уже есть: ${venue.name} (${venue.public_id ?? venue.id})`)
} else {
  venue = (await api('POST', '/venues', VENUE))?.data ?? null
  console.log(`[✓] площадка создана: ${venue?.name} (${venue?.public_id ?? venue?.id})`)
}
const venueId = venue?.id

/* ── 3. Зал ──────────────────────────────────────────────────────────── */
// Ищем зал только внутри выбранной площадки. Глобальный fallback по имени
// опасен: «Основной зал» есть у нескольких площадок и мог перезаписать схему
// СурГУ при импорте Вавилона.
let hall = list(await api('GET', `/venues/${venue?.public_id ?? venueId}/halls?limit=100`))
  .find((h) => h.name === HALL.name)
if (hall) {
  // Вместимость и размеры — свойства шаблона зала, а не события. Обновляем
  // их при повторном импорте, чтобы удаление столов с танцпола не оставило
  // старую capacity/геометрию на карточке зала.
  hall = (await api('PUT', `/halls/${hall.public_id ?? hall.id}`, {
    name: HALL.name,
    description: HALL.description,
    capacity: seatCount,
    width: schema.canvas.width,
    height: schema.canvas.height,
  }))?.data ?? hall
  console.log(`[•] зал обновлён: ${hall.name} (${hall.public_id ?? hall.id})`)
} else {
  hall = (await api('POST', '/halls', {
    venue_id: venueId,
    name: HALL.name,
    description: HALL.description,
    capacity: seatCount,
    width: schema.canvas.width,
    height: schema.canvas.height,
    status: 'active',
  }))?.data ?? null
  console.log(`[✓] зал создан: ${hall?.name} (${hall?.public_id ?? hall?.id})`)
}
const hallPid = hall?.public_id
if (!hallPid) throw new Error('нет public_id зала — нечем сохранять схему')

/* ── 4. Черновик схемы ───────────────────────────────────────────────── */
const draft = await api('POST', `/halls/${hallPid}/schema-versions/draft`, { payload: schema })
const version = draft?.data ?? draft
console.log(`[✓] черновик создан: версия ${version?.version} (id ${version?.id}, статус ${version?.status})`)

/* ── 5. Публикация ───────────────────────────────────────────────────── */
const published = await api('POST', `/halls/${hallPid}/schema-versions/${version.id}/publish`)
console.log(`[✓] опубликовано: версия ${published?.data?.version} (статус ${published?.data?.status})`)

/* ── 6. Проверка: что реально отдаёт публичный роут ──────────────────── */
const pub = await api('GET', `/halls/${hallPid}/schema`)
const pubSchema = pub?.data?.schema ?? {}
const pubSeats = (pubSchema.sectors ?? []).reduce((sum, s) => sum + (s.seats?.length ?? 0), 0)
const pubStanding = (pubSchema.staticObjects ?? [])
  .filter((o) => o.kind === 'standing')
  .reduce((sum, o) => sum + Math.max(0, Number(o.capacity ?? 0)), 0)
const pubCapacity = pubSeats + pubStanding
console.log(`[✓] публичная схема: секторов ${(pubSchema.sectors ?? []).length}, `
  + `мест ${pubSeats} + standing ${pubStanding} = ${pubCapacity}, `
  + `холст ${pubSchema.canvas?.width}×${pubSchema.canvas?.height}, статус ${pub?.data?.status}`)
if (pubCapacity !== seatCount) {
  console.log(`[!] расхождение: в файле ${seatCount} мест, отдаётся ${pubCapacity}`)
}
console.log(`[✓] зал: ${hallPid} — импорт завершён`)
