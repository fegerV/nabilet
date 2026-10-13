/**
 * «Клуб зрителей», фаза 1: избранное и подписки — вызовы `/api/v1/me/*`.
 *
 * ОБА СПИСКА ЛИЧНЫЕ И ОБА ТРЕБУЮТ АККАУНТА
 *   Весь префикс `me` закрыт `auth:api` (`app/Modules/Loyalty/routes/api.php`):
 *   без аккаунта сервер отвечает 401 на любой из шести путей
 *   (`LoyaltyEndpointsTest::test_every_endpoint_requires_an_account`). Поэтому
 *   витрина не «тихо теряет отметку» для гостя, а отправляет его войти —
 *   этим занимаются компоненты, а не этот модуль.
 *
 * МЕРОПРИЯТИЕ АДРЕСУЕТСЯ `public_id`, А НЕ ВНУТРЕННИМ `id`
 *   В ответах афиши есть ОБА поля: `EventResource` отдаёт и `id` (BIGINT), и
 *   `public_id` (ULID). Подстановка внутреннего номера в
 *   `POST /me/events/{event}/favorite` дала бы 404 на `resolveEvent()`, который
 *   ищет событие по `public_id`. Поэтому все методы принимают именно его.
 *
 * ИДЕМПОТЕНТНОСТЬ НЕ НАША, А СЕРВЕРНАЯ
 *   Повторное «♡» отвечает 200 (а не 201) с той же строкой, повторный DELETE —
 *   тем же 204 (`uq_event_favorites_user_event` в схеме). Клиенту это значит
 *   ровно одно: код ответа не меняет обработку, расхождение состояния
 *   невозможно в принципе.
 */
import { get, send } from './api'

/**
 * Карточка мероприятия внутри списков кабинета — схема `EventSummary`.
 *
 * Намеренно не `EventCard`/`Event`: у списков кабинета другая задача. Им не
 * нужны цены, сеансы и статусы — им нужно название, дата и возраст, чтобы
 * покупатель узнал мероприятие и решил, открывать его или убрать.
 */
export interface LoyaltyEvent {
  /** `events.public_id` (ULID) — по нему строится ссылка на витрину. */
  id: string
  title: string
  slug: string | null
  poster: string | null
  /** «12+», «16+» или null — в схеме строка, но терпим число из старых данных. */
  age_limit: string | number | null
  /** Ближайший предстоящий сеанс или null, если все уже прошли. */
  starts_at: string | null
}

/**
 * Строка списка — схемы `EventFavorite` и `EventFollow`.
 *
 * Формы совпадают намеренно (см. `EventFollowResource`), поэтому один тип на
 * оба списка: второй парсер под ту же строку был бы лишним местом расхождения.
 */
export interface LoyaltyEntry {
  /** `event_favorites.public_id` / `event_follows.public_id` — справочно. */
  id: string
  event: LoyaltyEvent | null
  created_at: string | null
}

/** Ответ списка приходит как `{ data: [...] }` — но терпим и голый массив. */
type ListPayload = LoyaltyEntry[] | { data?: LoyaltyEntry[] | null } | null

function toList(payload: ListPayload): LoyaltyEntry[] {
  if (Array.isArray(payload)) return payload.map(normalizeEntry)
  if (payload && Array.isArray(payload.data)) return payload.data.map(normalizeEntry)
  // Пустой ответ — не ошибка: у нового аккаунта списки пусты by design.
  return []
}

function normalizeEntry(raw: LoyaltyEntry): LoyaltyEntry {
  const event = raw.event
  return {
    id: String(raw.id ?? ''),
    event: event
      ? {
          id: String(event.id ?? ''),
          title: String(event.title ?? ''),
          slug: event.slug ?? null,
          poster: event.poster ?? null,
          age_limit: event.age_limit ?? null,
          starts_at: event.starts_at ?? null,
        }
      : null,
    created_at: raw.created_at ?? null,
  }
}

/** `GET /me/favorites` — избранное, свежие сверху. */
export async function fetchFavorites(): Promise<LoyaltyEntry[]> {
  const res = await get<ListPayload>('/me/favorites')
  return toList(res.data as ListPayload)
}

/** `GET /me/follows` — подписки на письма о новых сеансах, свежие сверху. */
export async function fetchFollows(): Promise<LoyaltyEntry[]> {
  const res = await get<ListPayload>('/me/follows')
  return toList(res.data as ListPayload)
}

/** `POST /me/events/{event}/favorite` — 201 впервые, 200 при повторе. */
export async function addFavorite(eventId: string): Promise<LoyaltyEntry | null> {
  const res = await send<LoyaltyEntry | null>(`/me/events/${encodeURIComponent(eventId)}/favorite`, 'POST')
  return res.data ?? null
}

/** `DELETE /me/events/{event}/favorite` — 204 всегда, отметки нет и не было. */
export async function removeFavorite(eventId: string): Promise<void> {
  await send<null>(`/me/events/${encodeURIComponent(eventId)}/favorite`, 'DELETE')
}

/** `POST /me/events/{event}/follow` — 201 впервые, 200 при повторе. */
export async function followEvent(eventId: string): Promise<LoyaltyEntry | null> {
  const res = await send<LoyaltyEntry | null>(`/me/events/${encodeURIComponent(eventId)}/follow`, 'POST')
  return res.data ?? null
}

/** `DELETE /me/events/{event}/follow` — 204 всегда. */
export async function unfollowEvent(eventId: string): Promise<void> {
  await send<null>(`/me/events/${encodeURIComponent(eventId)}/follow`, 'DELETE')
}
