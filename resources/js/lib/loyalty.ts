/**
 * «Клуб зрителей»: вызовы `/api/v1/me/*` — избранное, подписки (фаза 1) и
 * бонусный счёт (фаза 2a).
 *
 * ВСЁ ЛИЧНОЕ И ВСЁ ТРЕБУЕТ АККАУНТА
 *   Весь префикс `me` закрыт `auth:api` (`app/Modules/Loyalty/routes/api.php`):
 *   без аккаунта сервер отвечает 401 на любой из восьми путей
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

/* ── «Бонусный счёт» (фаза 2a плана) ───────────────────────────────────────
 * Два эндпоинта под тем же `auth:api`: баланс и append-only журнал движений.
 *
 * ДВА ЧИСЛА ОБ ОДНОМ ЗНАЧЕНИИ, И ЭТО НЕ ДУБЛИРОВАНИЕ
 *   `balance` — минорные единицы (копейки), как и все деньги в API: это то,
 *   что лежит в базе. `bonuses` — тот же баланс по курсу «1 бонус = 1 ₽»
 *   (`BonusPolicy::MINOR_UNITS_PER_BONUS`). Курс применяет СЕРВЕР
 *   (`BonusBalanceResource`): если бы сотню делила вёрстка, правка курса
 *   превратилась бы в поиск всех мест, где зашита сотня.
 *
 * ЗНАК `delta` — СМЫСЛ, А НЕ ОФОРМЛЕНИЕ
 *   Плюс — начислили, минус — списали, вернули или сожгли по сроку. Знак без
 *   причины не отличает кэшбэк от подарка за регистрацию, причина без знака —
 *   начисление от отката. Поэтому история несёт оба поля, а подпись причины
 *   собирает `bonusReasonLabel()`.
 * ────────────────────────────────────────────────────────────────────────── */

/** Схема `BonusBalance`. Счёта ещё нет — сервер отдаёт нули, а не 404. */
export interface BonusBalance {
  /** Минорные единицы (копейки) — то, чем считает сервер. */
  balance: number
  /** Тот же баланс в бонусах (1 бонус = 1 ₽) — тоже считает сервер. */
  bonuses: number
  currency: string
}

/** Схема `BonusTransaction` — одно движение по счёту. */
export interface BonusTransaction {
  id: string
  /** `orders.public_id`; `null` — движение не связано с заказом. */
  orderId: string | null
  /** Знаковый, в минорных единицах. */
  delta: number
  reason: string
  createdAt: string | null
}

/** Страница журнала: `data`-список + `meta` пагинатора. */
export interface BonusHistory {
  items: BonusTransaction[]
  page: number
  perPage: number
  total: number
  lastPage: number
}

/**
 * Привести число из ответа к целому. `NaN`/`null`/`"abc"` → 0.
 *
 * Баланс и суммы — деньги: `NaN` в интерфейсе («У вас NaN бонусов») выглядит
 * как поломка сайта, а не как сбой одного поля. Ноль честнее и не ломает
 * вёрстку; настоящую ошибку всё равно покажет код ответа, а не число.
 */
function toInt(value: unknown): number {
  const n = Number(value)
  return Number.isFinite(n) ? Math.trunc(n) : 0
}

/** `GET /me/bonus` — баланс. Гость сюда не попадает: путь под `auth:api`. */
export async function fetchBonusBalance(): Promise<BonusBalance> {
  const res = await get<Partial<BonusBalance> | null>('/me/bonus')
  const data = res.data ?? {}

  return {
    balance: toInt(data.balance),
    bonuses: toInt(data.bonuses),
    currency: String(data.currency ?? 'RUB'),
  }
}

/**
 * `GET /me/bonus/transactions` — журнал движений, свежие сверху.
 *
 * `page` в строку запроса не попадает для первой страницы: сервер и так
 * начинает с первой, а `?page=1` в логах только шумит.
 *
 * `meta` может отсутствовать, если ответ пришёл не по контракту. Тогда
 * считаем, что страница одна: показать список без «Показать ещё» честнее, чем
 * нарисовать кнопку, которая уйдёт в пустоту.
 */
export async function fetchBonusTransactions(
  params: { page?: number; perPage?: number } = {},
): Promise<BonusHistory> {
  const query = new URLSearchParams()
  if (params.page !== undefined && params.page > 1) query.set('page', String(params.page))
  if (params.perPage !== undefined) query.set('per_page', String(params.perPage))
  const suffix = query.toString() === '' ? '' : `?${query.toString()}`

  const res = await get<Array<Record<string, unknown>>>(
    `/me/bonus/transactions${suffix}`,
  )
  const rows = Array.isArray(res.data) ? res.data : []
  const meta = res.meta

  return {
    items: rows.map(normalizeTransaction),
    page: toInt(meta?.current_page) || 1,
    perPage: toInt(meta?.per_page) || rows.length || 25,
    total: toInt(meta?.total) || rows.length,
    lastPage: toInt(meta?.last_page) || 1,
  }
}

function normalizeTransaction(raw: Record<string, unknown>): BonusTransaction {
  const orderId = raw.order_id

  return {
    id: String(raw.id ?? ''),
    orderId: orderId === undefined || orderId === null ? null : String(orderId),
    delta: toInt(raw.delta),
    reason: String(raw.reason ?? ''),
    createdAt: raw.created_at === undefined || raw.created_at === null ? null : String(raw.created_at),
  }
}

/** Подписи словаря `ck_bonus_transactions_reason`. */
const BONUS_REASON_LABELS: Record<string, string> = {
  accrual: 'Кэшбэк за заказ',
  registration: 'Бонус за регистрацию',
  first_purchase: 'Бонус за первую покупку',
  spend: 'Списание бонусов',
  refund: 'Возврат бонусов',
  expiry: 'Сгорели по сроку',
}

/**
 * Подпись причины движения.
 *
 * Неизвестный код отдаём КАК ЕСТЬ, а не прячем за общее «операция по счёту»:
 * сервер и витрина выпускаются порознь, и если словарь на сервере расширят
 * раньше, чем здесь появится строка, покупатель увидит `family_discount` —
 * непонятно, но правда. Выдуманная подпись соврала бы о причине списания.
 */
export function bonusReasonLabel(reason: string): string {
  return BONUS_REASON_LABELS[reason] ?? reason
}
