/**
 * «Рекомендуем для вас» — персональная полка мероприятий (фаза 5 плана).
 *
 * ПЕРСОНАЛЬНОЕ, А НЕ АДМИНСКОЕ «РЕКОМЕНДУЕМ»
 *   В конструкторе витрины уже есть виджет `featured` с заголовком
 *   «Рекомендуем» — но это ПОДБОРКА, которую собрал организатор, одинаковая для
 *   всех. Здесь другое: список, который сервер считает из личной истории
 *   покупателя (избранное, подписки, оплаченные заказы). Поэтому вызов живёт
 *   под `auth:api` и показывается только на странице клуба, куда гость не
 *   попадает.
 *
 * ТОЛЬКО `EventSummary`
 *   Форма карточки — та же, что у списков кабинета (id/slug/title/poster/
 *   age_limit/starts_at). Отдельная схема была бы вторым местом, которое надо
 *   править при изменении карточки, и разошлась бы с кабинетом.
 */
import { get } from './api'

/** Сколько карточек просим по умолчанию. Сервер зажимает значение 1..24. */
export const RECOMMENDATIONS_DEFAULT_LIMIT = 6

/** Карточка рекомендованного мероприятия — схема `EventSummary`. */
export interface RecommendedEvent {
  /** `events.public_id` (ULID) — по нему строится ключ списка. */
  id: string
  title: string
  slug: string | null
  poster: string | null
  age_limit: string | number | null
  /** Ближайший предстоящий сеанс или null. */
  starts_at: string | null
}

/** Ответ приходит как `{ data: [...] }` — но терпим и голый массив. */
type Payload = RecommendedEvent[] | { data?: RecommendedEvent[] | null } | null

function normalize(raw: Partial<RecommendedEvent>): RecommendedEvent {
  return {
    id: String(raw.id ?? ''),
    title: String(raw.title ?? ''),
    slug: raw.slug ?? null,
    poster: raw.poster ?? null,
    age_limit: raw.age_limit ?? null,
    starts_at: raw.starts_at ?? null,
  }
}

function toList(payload: Payload): RecommendedEvent[] {
  if (Array.isArray(payload)) return payload.map(normalize)
  if (payload && Array.isArray(payload.data)) return payload.data.map(normalize)
  // Пустой ответ — не ошибка: у нового аккаунта рекомендаций ещё нет, и это
  // честное «пока нечего предложить», а не сбой загрузки.
  return []
}

/**
 * `GET /me/recommendations` — персональная полка, самая релевантная сверху.
 *
 * Неположительный `limit` не уходит в строку запроса: сервер и так применит
 * значение по умолчанию, а `?limit=0` в логах только шумит.
 */
export async function fetchRecommendations(
  limit: number = RECOMMENDATIONS_DEFAULT_LIMIT,
): Promise<RecommendedEvent[]> {
  const query = limit > 0 ? `?limit=${encodeURIComponent(String(limit))}` : ''
  const res = await get<Payload>(`/me/recommendations${query}`)
  return toList(res.data)
}
