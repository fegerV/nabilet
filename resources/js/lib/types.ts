/** Общие типы интерфейсов. Совпадают с машинами состояний docs/STATE-MACHINES.md. */

export type SeatState = 'free' | 'selected' | 'held' | 'sold' | 'unavailable' | 'vip' | 'accessible'

export type OrderStatus =
  | 'pending'
  | 'awaiting_payment'
  | 'paid'
  | 'payment_failed'
  | 'cancelled'
  | 'expired'
  | 'partially_refunded'
  | 'refunded'

export type TicketStatus = 'issued' | 'used' | 'cancelled' | 'refunded' | 'expired' | 'revoked'

/**
 * Статусы `events.status` — словарь бэкенда.
 *
 * Раньше здесь стояло `'draft' | 'published' | 'sold_out' | 'finished' | 'cancelled'`:
 * `sold_out` и `finished` бэкенд не знает НИКОГДА (это статусы сеанса и выдуманное
 * «завершено»), а `scheduled`, `completed` и `archived` отсутствовали — поэтому
 * `NStatusBadge` не умел их подписать и показывал сырую английскую строку.
 * Источник истины — `EventStatus::all()` и `EventStateMachine`; с миграции
 * `015_events_status_check.sql` тот же список проверяет и база.
 */
export type EventStatus = 'draft' | 'scheduled' | 'published' | 'completed' | 'cancelled' | 'archived'

/**
 * Статусы `sessions.status` — словарь бэкенда.
 *
 * Раньше этого типа НЕ БЫЛО ВООБЩЕ: `SessionFormState.status` и
 * `SessionRecord.status` были просто `string`, и поэтому компилятор молчал,
 * пока по трём файлам ходил словарь `scheduled | on_sale | held | completed |
 * cancelled`. Он разошёлся с машиной в обе стороны ровно так же, как когда-то
 * `EventStatus`:
 *
 *  - `held` — состояние позиции инвентаря (`InventoryItemStateMachine::HELD`),
 *    попавшее в словарь сеанса копированием из соседнего. В `ck_sessions_status`
 *    его нет. Бэкенд вычистил его ещё в `SessionStateMachine::all()` (запись с
 *    ним проходила валидацию и падала на MySQL — 500 вместо 422), а ФРОНТЕНД
 *    остался на старом списке: форма предлагала «Проведён», отправляла `held` и
 *    получала 422 уже на новом правиле.
 *  - `draft`, `sold_out` и `closed` отсутствовали, хотя машина их знает, а
 *    `sold_out` и `closed` — обязательные состояния продаж
 *    (`on_sale ⇄ sold_out → closed`). Выставить их из админки было нельзя.
 *
 * Источник истины — `SessionStateMachine::all()`; тот же список закреплён
 * ограничением `ck_sessions_status` и сверяется гейтом
 * `tools/verify-state-machines.php`. Совпадение этого типа с PHP-словарём
 * стережёт `tests/Unit/statusVocabularyParity.test.ts`.
 */
export type SessionStatus =
  | 'draft'
  | 'scheduled'
  | 'on_sale'
  | 'sold_out'
  | 'closed'
  | 'completed'
  | 'cancelled'

/**
 * Статусы `raffles.status` — словарь `RaffleStateMachine`.
 *
 * Тип живёт здесь, а не в `lib/raffles.ts`, по одной причине: подписи статусов
 * собираются в `lib/statusLabels.ts`, который обязан оставаться чистым — он
 * попадает в бандл вместе с `NStatusBadge`. Импорт из `lib/raffles.ts` притащил
 * бы туда клиент API (`lib/api`) ради четырёх строковых литералов.
 */
export type RaffleStatus = 'draft' | 'active' | 'drawn' | 'cancelled'

export type CheckinResult =
  | 'valid'
  | 'already_used'
  | 'cancelled'
  | 'refunded'
  | 'expired'
  | 'invalid_signature'
  | 'wrong_session'
  | 'wrong_event'
  | 'unknown_ticket'
  | 'conflict'

/** Тональность статуса — не украшение, а соглашение: одинаковые статусы выглядят одинаково. */
export type Tone = 'neutral' | 'brand' | 'accent' | 'sun' | 'mint' | 'rose' | 'sky'

export interface EventCard {
  id: string
  /**
   * `events.public_id` (ULID) — адрес мероприятия в `/me/*` («♡ Избранное»,
   * «🔔 Подписки»). Внутренний `id` сервер тоже отдаёт, но
   * `LoyaltyController::resolveEvent()` ищет событие только по `public_id`,
   * поэтому все обращения к API лояльности идут по нему.
   */
  public_id?: string
  slug?: string
  title: string
  subtitle?: string
  short_description?: string
  /** API отдаёт категорию то строкой, то объектом, то null — проп это терпит. */
  category?: string | { name?: string } | null
  venue?: string | { name?: string; city?: string } | null
  city?: string
  posterFrom?: string
  posterTo?: string
  posterAccent?: string
  poster?: string
  cover?: string
  priceFromMinor?: number
  price_from_minor?: number
  status: EventStatus
  sessionsCount?: number
  sessions_count?: number
  /** Сеансы: то, что реально покупает пользователь. */
  sessions?: Array<{
    id: string
    startsAt?: string
    starts_at?: string
    hall?: string
    hall_name?: string
    availableSeats?: number
    available_seats?: number
  }>
}

export interface OrderRow {
  id: string
  number: string
  customer: string
  email: string
  eventTitle: string
  sessionAt: string
  seats: number
  totalMinor: number
  status: OrderStatus
  channel: 'site' | 'telegram' | 'embed' | 'admin'
  createdAt: string
}

export interface TicketTemplateConfig {
  width: number
  height: number
  backgroundColor?: string
  backgroundGradient?: { from: string; to: string; direction?: string } | null
  elements: Array<Record<string, unknown>>
}

export interface TicketCard {
  id: string
  code: string
  eventTitle: string
  venue: string
  sessionAt: string
  sector: string
  row: number
  seat: number
  priceMinor: number
  status: TicketStatus
  qrPayload: string
  holder?: string
  orderId?: number | string
  template?: TicketTemplateConfig | null
}
