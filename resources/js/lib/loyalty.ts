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

/* ── Уровни «Клуба зрителей» (фаза 3 плана) ────────────────────────────────
 * Один эндпоинт под тем же `auth:api`: текущий уровень и прогресс до следующего.
 * Уровень — производное от числа ОПЛАЧЕННЫХ заказов, пересчитываемое на сервере
 * при смене статуса заказа, поэтому фронт только показывает готовый снимок и не
 * держит пороги (они — в конфиге уровней на сервере, а не в вёрстке).
 * ────────────────────────────────────────────────────────────────────────── */

/** Схема `LoyaltyLevel` — один уровень из конфига. */
export interface LoyaltyLevel {
  slug: string
  /** Отображаемое имя: «Старт» / «Друг» / «VIP». */
  name: string
  /** Порог в оплаченных заказах. */
  min_paid_orders: number
}

/** Схема `LoyaltyLevelStatus` — снимок уровня покупателя. */
export interface LoyaltyLevelStatus {
  slug: string
  name: string
  min_paid_orders: number
  /** Число оплаченных заказов покупателя. */
  paid_orders: number
  /** Следующий уровень или null на самом верхнем. */
  next_level: LoyaltyLevel | null
  /** Оплаченных заказов до следующего уровня (0 на вершине). */
  orders_to_next: number
}

/** `GET /me/loyalty-level` — текущий уровень и прогресс. Гость сюда не попадает. */
export async function fetchLoyaltyLevel(): Promise<LoyaltyLevelStatus> {
  const res = await get<Partial<LoyaltyLevelStatus> | null>('/me/loyalty-level')
  const data = res.data ?? {}

  const next = data.next_level
  const nextLevel: LoyaltyLevel | null =
    next && typeof next === 'object'
      ? {
          slug: String(next.slug ?? 'start'),
          name: String(next.name ?? ''),
          min_paid_orders: toInt(next.min_paid_orders),
        }
      : null

  return {
    slug: String(data.slug ?? 'start'),
    name: String(data.name ?? 'Старт'),
    min_paid_orders: toInt(data.min_paid_orders),
    paid_orders: toInt(data.paid_orders),
    next_level: nextLevel,
    orders_to_next: toInt(data.orders_to_next),
  }
}

/* ── Лимит списания в чекауте (фаза 2b) ────────────────────────────────────
 * ЗЕРКАЛО `BonusPolicy`, А НЕ ВТОРОЕ ПРАВИЛО
 *   Числа ниже повторяют `BonusPolicy::SPEND_CAP_PERCENT` и
 *   `BonusPolicy::MINOR_UNITS_PER_BONUS`. Это осознанное дублирование, и оно
 *   безопасно ровно потому, что сервер остаётся источником истины: витрина
 *   считает лимит, чтобы ПОКАЗАТЬ его и не дать покупателю отметить больше, а
 *   чекаут пересчитывает тот же лимит заново и отвечает 422
 *   `BONUS_LIMIT_EXCEEDED` на превышение. Клиентское число — предпросмотр,
 *   серверное — решение; ровно та же схема, что у промокода.
 *
 *   Поэтому расхождение здесь не может привести к списанию сверх лимита — оно
 *   может привести только к лишнему отказу, и это заметно сразу, а не в сверке.
 * ────────────────────────────────────────────────────────────────────────── */

/** 1 бонус = 1 ₽ = 100 минорных единиц (`BonusPolicy::MINOR_UNITS_PER_BONUS`). */
export const MINOR_UNITS_PER_BONUS = 100

/** Лимит списания: 30% от подытога (`BonusPolicy::SPEND_CAP_PERCENT`). */
export const SPEND_CAP_PERCENT = 30

/**
 * Сколько бонусов можно списать в заказе с такими суммами — всё в минорных
 * единицах.
 *
 * АРИФМЕТИКА ПОВТОРЯЕТ СЕРВЕРНУЮ ДО ПОСЛЕДНЕЙ ОПЕРАЦИИ, И ЭТО НАМЕРЕННО
 *   `Math.round(subtotal * 30 / 100)` — та же самая float-выраженность и то же
 *   округление половины вверх, что у `Money::percentage()`. Это не случайное
 *   совпадение: IEEE-754 считает одно и то же выражение одинаково в PHP и в JS,
 *   поэтому клиентский лимит совпадает с серверным до копейки и покупатель не
 *   получает отказ из-за разницы в одну минорную единицу. Переписав здесь
 *   формулу «по-человечески» (например, через `Math.ceil`), мы получили бы
 *   лимит на копейку больше серверного — и отказ на ровном месте.
 *
 * ТРИ ОГРАНИЧЕНИЯ, КАК И У СЕРВЕРА
 *   30% от подытога (доля считается от подытога, а не от остатка после скидки:
 *   обещание не должно уменьшаться от того, что покупатель нашёл промокод),
 *   остаток после скидки (бонусы не могут увести итог в минус) и баланс.
 */
export function spendCapMinor(
  subtotalMinor: number,
  discountMinor: number,
  balanceMinor: number,
): number {
  if (!(balanceMinor > 0)) return 0

  const cap = Math.round((subtotalMinor * SPEND_CAP_PERCENT) / 100)
  const ceiling = discountMinor > subtotalMinor ? 0 : subtotalMinor - discountMinor

  return Math.min(cap, ceiling, balanceMinor)
}

/* ── Применение бонусов в чекауте — ЕДИНСТВЕННЫЙ ВЛАДЕЛЕЦ ЭТОГО ПРАВИЛА ──────
 * Раньше те же пять величин (лимит, применённая сумма, итог, лимит в бонусах,
 * остаток) считались ДВАЖДЫ — в `usePurchaseFlow` (предпросмотр на витрине) и
 * в `CheckoutPage` (там же и отправка `bonus_amount`). Формулы были
 * побайтово равны, поэтому расхождения не было — но копия несла в себе именно
 * ту беду, от которой её писали защитить: стоило поменять лимит в одном месте
 * (например, добавить «не больше заказа» к потолку), как вторая страница
 * продолжила бы показывать старый итог, а сервер вернул бы 422 на новый.
 *
 * Владелец — эта чистая функция; страницы только ПЕРЕДАЮТ ей корзину и флаг.
 * `spendCapMinor` здесь вызывается один раз на всю программу.
 */
export interface BonusApplicationInput {
  /** Подытог до скидки — основа 30%-лимита (`spendCapMinor`). */
  subtotalMinor: number
  /** Скидка (промокод) — задаёт потолок «не больше остатка после скидки». */
  discountMinor: number
  /** Баланс бонусов покупателя в минорах. */
  balanceMinor: number
  /** Итог корзины после скидки — из него вычитаются применённые бонусы. */
  cartTotalMinor: number
  /** Покупатель согласился списать бонусы. */
  useBonuses: boolean
}

export interface BonusApplication {
  /** Максимум к списанию, миноры (＝ `spendCapMinor(...)`). */
  capMinor: number
  /** Реально списано, миноры (0, если `useBonuses` снят). */
  appliedMinor: number
  /** Итог к оплате после бонусов (не меньше 0). */
  totalMinor: number
  /** Лимит в бонусах — то число, которое называет покупатель. */
  capUnits: number
  /** Бонусов, остающихся ПОСЛЕ списания (для подписи у галочки). */
  leftUnits: number
}

export function applyBonuses(input: BonusApplicationInput): BonusApplication {
  const capMinor = spendCapMinor(input.subtotalMinor, input.discountMinor, input.balanceMinor)
  const appliedMinor = input.useBonuses ? capMinor : 0
  const totalMinor = Math.max(0, input.cartTotalMinor - appliedMinor)
  const capUnits = Math.floor(capMinor / MINOR_UNITS_PER_BONUS)
  const leftUnits = Math.floor(Math.max(0, input.balanceMinor - capMinor) / MINOR_UNITS_PER_BONUS)

  return { capMinor, appliedMinor, totalMinor, capUnits, leftUnits }
}
