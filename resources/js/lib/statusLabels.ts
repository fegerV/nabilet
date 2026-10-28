/**
 * Словарь статусов: «код состояния → подпись, цвет, точка».
 *
 * ЕДИНСТВЕННЫЙ ВЛАДЕЛЕЦ. Здесь заканчивается свобода дизайнера: подписи и
 * цвета привязаны к машинам состояний из `docs/STATE-MACHINES.md`. Если статус
 * заказа `awaiting_payment` — он везде «Ждём оплаты» и везде одного цвета: в
 * таблице заказов, в шапке деталки, в письме, в аналитике. Пользователь учит
 * систему один раз.
 *
 * ПОЧЕМУ СЛОВАРЬ ПЕРЕЕХАЛ ИЗ `NStatusBadge`
 *   Он там и был объявлен — с докблоком, который обещал ровно то, что написано
 *   выше. Но обещание держалось на честном слове: рядом жили четыре копии,
 *   и каждая заводилась не со зла, а потому что «мне нужна только подпись, без
 *   цвета» — то есть потому что владелец не давал способа взять подпись
 *   отдельно. Модуль закрывает именно эту дыру: `statusLabel()` для текста,
 *   `statusMeta()` для текста с цветом, `statusOptions()` для `<select>`.
 *
 * ЧТО НАШЛОСЬ В КОПИЯХ
 *   1. `AdminAnalyticsPage` — восемь подписей заказов, побайтово равные
 *      словарю бейджа. Разошлись бы на первом же переименовании статуса, и
 *      разошлись бы молча: аналитика — отдельный экран, его никто не сверяет
 *      с таблицей заказов.
 *   2. `TicketCard` — пять подписей билетов и одно расхождение
 *      (`refunded: «Возврат оформлен»` против «Возврат»). Расхождение здесь
 *      оставлено СОЗНАТЕЛЬНО, но теперь оно названо вслух и стоит отдельно от
 *      словаря (см. `TicketCard.vue`), а не спрятано внутри копии.
 *   3. `SessionFormModal` и `AdminEventFormPage` — два побайтово одинаковых
 *      списка состояний сеанса `scheduled | on_sale | held | completed |
 *      cancelled`. Это СТАРЫЙ, УЖЕ ИСПРАВЛЕННЫЙ НА БЭКЕНДЕ список:
 *      `SessionStateMachine::all()` вычистил из него `held` (это состояние
 *      позиции инвентаря, в `ck_sessions_status` его нет) и вернул `draft`,
 *      `sold_out`, `closed`. Копия на фронтенде пережила исправление и
 *      продолжала предлагать администратору «Проведён» — форма отправляла
 *      `held` и получала 422 от того самого нового правила. Копия не просто
 *      разошлась с владельцем: она ЗАКОНСЕРВИРОВАЛА баг, который в другом
 *      месте уже починили.
 *   4. `lib/raffles.ts` — `RAFFLE_STATUS_LABELS` плюс `raffleStatusClass()`,
 *      который «тесной копией палитры админки» выписывал Tailwind-классы
 *      руками и подмешивался в `NBadge` через `:class`. Копия палитры успела
 *      разойтись: `drawn` был покрашен в `indigo` — цвет из ДЕФОЛТНОЙ палитры
 *      Tailwind, которого нет среди токенов проекта. Он рисуется (палитра
 *      Tailwind расширена, а не заменена), но выпадает из семантики: «Разыгран»
 *      — единственное ПОЛОЖИТЕЛЬНОЕ терминальное состояние — оказывался
 *      единственным синим элементом во всём интерфейсе. Заодно все плашки
 *      розыгрышей потеряли `ring`, который настоящие тона `NBadge` дают:
 *      копия палитры воспроизвела фон и текст, но не всё правило.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ МОДУЛЬ, А НЕ КОМПОНЕНТ
 *   Словарь нужен и там, где плашки нет вовсе: в аналитике — строка легенды, в
 *   форме — опции `<select>`. Пока словарь жил в `.vue`, эти места могли взять
 *   его только вместе с шаблоном, поэтому брали копию.
 *
 * ГРАНИЦА С БЭКЕНДОМ
 *   Значения — не наша выдумка, а словари PHP-владельцев
 *   (`OrderStateMachine`, `TicketStateMachine`, `EventStatus`,
 *   `SessionStateMachine`, `RaffleStateMachine`), закреплённые CHECK-ограничениями
 *   в `nabilet_core_spec/migrations.sql`. Совпадение проверяется не на глаз:
 *   `tests/Unit/statusVocabularyParity.test.ts` читает PHP-классы с диска и
 *   сверяет множества значений. Поэтому тип каждого словаря здесь —
 *   `Record<ИмяСтатуса, …>`, а не `Record<string, …>`: пропущенное состояние
 *   ломает сборку типов, а лишнее — падает в паритетном тесте.
 */
import type {
  EventStatus,
  OrderStatus,
  RaffleStatus,
  SessionStatus,
  TicketStatus,
  Tone,
} from './types'

/**
 * Типы сущностей со своим словарём статусов.
 *
 * `Record<StatusKind, …>` в `VOCABULARY` ниже — это компиляторная защита: новый
 * тип сущности без словаря не соберётся.
 */
export type StatusKind =
  | 'order'
  | 'ticket'
  | 'event'
  | 'session'
  | 'hall'
  | 'venue'
  | 'payment'
  | 'user'
  | 'raffle'

export interface StatusMeta {
  readonly label: string
  readonly tone: Tone
  /** Точка слева: для статусов-процессов («идёт», «ждём»). */
  readonly dot: boolean
}

/** Заказы. Восемь состояний `OrderStateMachine`, закреплены `ck_orders_status`. */
export const ORDER_STATUS: Record<OrderStatus, StatusMeta> = {
  pending: { label: 'Создан', tone: 'neutral', dot: true },
  awaiting_payment: { label: 'Ждём оплаты', tone: 'sun', dot: true },
  paid: { label: 'Оплачен', tone: 'mint', dot: false },
  payment_failed: { label: 'Оплата не прошла', tone: 'rose', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  expired: { label: 'Истёк', tone: 'neutral', dot: false },
  partially_refunded: { label: 'Частичный возврат', tone: 'sun', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
}

/** Билеты. Шесть состояний `TicketStateMachine`, закреплены `ck_tickets_status`. */
export const TICKET_STATUS: Record<TicketStatus, StatusMeta> = {
  issued: { label: 'Действителен', tone: 'mint', dot: true },
  used: { label: 'Использован', tone: 'brand', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
  expired: { label: 'Истёк', tone: 'neutral', dot: false },
  revoked: { label: 'Отозван', tone: 'rose', dot: false },
}

/**
 * Мероприятия — шесть состояний `EventStatus`, закреплены `ck_events_status`.
 *
 * Раньше карта шла по типу, где были `sold_out` и `finished` (бэкенд их не
 * знает) и не было `scheduled`, `completed`, `archived`. Поэтому три реальных
 * состояния показывались сырой английской строкой, а две записи словаря были
 * мёртвыми.
 */
export const EVENT_STATUS: Record<EventStatus, StatusMeta> = {
  draft: { label: 'Черновик', tone: 'neutral', dot: false },
  scheduled: { label: 'Запланирован', tone: 'neutral', dot: true },
  published: { label: 'В продаже', tone: 'mint', dot: true },
  completed: { label: 'Завершён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'rose', dot: false },
  archived: { label: 'Архив', tone: 'neutral', dot: false },
}

/**
 * Сеансы — СЕМЬ состояний `SessionStateMachine::all()`, `ck_sessions_status`.
 *
 * Здесь стояло пять, и одним из них был `held` — состояние позиции инвентаря,
 * которого у сеанса не существует. Подробности и последствия — в докблоке
 * `SessionStatus` в `lib/types.ts`. Коротко: форма предлагала «Проведён»,
 * отправляла `held`, получала 422; `sold_out` и `closed` выставить было нельзя.
 *
 * `closed` и `completed` — РАЗНЫЕ вещи, и подписи у них разные: `closed` —
 * «касса закрыта, спектакль ещё не был», `completed` — «был». Свести их в одно
 * слово значило бы сделать «могу ли я ещё продавать?» и «это уже произошло?»
 * одним вопросом.
 */
export const SESSION_STATUS: Record<SessionStatus, StatusMeta> = {
  draft: { label: 'Черновик', tone: 'neutral', dot: false },
  scheduled: { label: 'Запланирован', tone: 'neutral', dot: true },
  on_sale: { label: 'В продаже', tone: 'mint', dot: true },
  sold_out: { label: 'Продано', tone: 'sun', dot: false },
  closed: { label: 'Продажи закрыты', tone: 'neutral', dot: false },
  completed: { label: 'Завершён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'rose', dot: false },
}

/** Залы и площадки: одна машина состояний на две таблицы. */
const ACTIVE_INACTIVE: Record<string, StatusMeta> = {
  active: { label: 'Активен', tone: 'mint', dot: true },
  inactive: { label: 'Неактивен', tone: 'neutral', dot: false },
}

export const HALL_STATUS = ACTIVE_INACTIVE
/** Площадка использует ту же машину состояний, что и зал (active/inactive). */
export const VENUE_STATUS = ACTIVE_INACTIVE

/**
 * Платёж: статусы провайдера (см. `Payment::$status`). «succeeded» и «pending»
 * — самые частые, и путать их нельзя: по «pending» деньги ещё не пришли,
 * хотя покупатель мог уже увидеть экран успеха.
 *
 * `canceled` с одной L — словарь ЮKassa, `cancelled` с двумя — наш. Оба
 * законны, поэтому оба в словаре.
 */
export const PAYMENT_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'В обработке', tone: 'sun', dot: true },
  waiting_for_capture: { label: 'Ждёт подтверждения', tone: 'sun', dot: true },
  succeeded: { label: 'Оплачен', tone: 'mint', dot: false },
  canceled: { label: 'Отменён', tone: 'neutral', dot: false },
  cancelled: { label: 'Отменён', tone: 'neutral', dot: false },
  failed: { label: 'Ошибка', tone: 'rose', dot: false },
  refunded: { label: 'Возврат', tone: 'rose', dot: false },
  partially_refunded: { label: 'Частичный возврат', tone: 'sun', dot: false },
}

/** Пользователь: active/inactive/banned из `users.status` (`UserStatus`). */
export const USER_STATUS: Record<string, StatusMeta> = {
  active: { label: 'Активен', tone: 'mint', dot: true },
  inactive: { label: 'Неактивен', tone: 'neutral', dot: false },
  banned: { label: 'Заблокирован', tone: 'rose', dot: false },
}

/**
 * Розыгрыши «счастливый билет» — четыре состояния `RaffleStateMachine`.
 *
 * `drawn` был покрашен в `indigo` — цвет ДЕФОЛТНОЙ палитры Tailwind. Он
 * рисуется (палитра проекта расширяет дефолтную, а не заменяет её), но обходит
 * семантический словарь: `brand` — акцент покупки, и «Разыгран» должен читаться
 * как «получилось», а не как «что-то синее».
 */
export const RAFFLE_STATUS: Record<RaffleStatus, StatusMeta> = {
  draft: { label: 'Черновик', tone: 'neutral', dot: false },
  active: { label: 'Набирает участников', tone: 'mint', dot: true },
  drawn: { label: 'Разыгран', tone: 'brand', dot: false },
  cancelled: { label: 'Отменён', tone: 'rose', dot: false },
}

/**
 * Все словари по типу сущности.
 *
 * `Record<StatusKind, …>` — не украшение: забытый тип сущности не соберётся.
 */
const VOCABULARY: Record<StatusKind, Readonly<Record<string, StatusMeta>>> = {
  order: ORDER_STATUS,
  ticket: TICKET_STATUS,
  event: EVENT_STATUS,
  session: SESSION_STATUS,
  hall: HALL_STATUS,
  venue: VENUE_STATUS,
  payment: PAYMENT_STATUS,
  user: USER_STATUS,
  raffle: RAFFLE_STATUS,
}

/**
 * Запись словаря или `null`, если такого статуса у этой сущности нет.
 *
 * `null`, а не заглушка: вызывающему иногда важно РАЗЛИЧИТЬ «неизвестный
 * статус» и «статус без подписи». Подпись под QR билета — ровно такой случай
 * (см. `TicketCard.vue`): незнакомый код должен оставить нейтральное
 * «Покажите на входе», а не вывалиться английской строкой на экран покупателя.
 */
export function findStatusMeta(kind: StatusKind, status: string): StatusMeta | null {
  return VOCABULARY[kind][status] ?? null
}

/**
 * Запись словаря с запасным вариантом.
 *
 * Неизвестный статус (новый провайдер, ручная правка БД, сборка старше
 * бэкенда) не должен ронять страницу: показываем сам код — администратору он
 * всё равно полезнее, чем пустая ячейка.
 */
export function statusMeta(kind: StatusKind, status: string): StatusMeta {
  return findStatusMeta(kind, status) ?? { label: status || '—', tone: 'neutral', dot: false }
}

/** Только подпись — для легенд, таблиц и заголовков, где цвет не нужен. */
export function statusLabel(kind: StatusKind, status: string): string {
  return statusMeta(kind, status).label
}

/**
 * Опции для `<select>`: значение и подпись из того же словаря.
 *
 * Форма редактирования — такой же потребитель словаря, как и бейдж. Именно
 * здесь копия обошлась дороже всего: список состояний сеанса разошёлся с
 * машиной, и администратор выбирал состояние, которое API отвергал.
 *
 * Порядок — порядок объявления в словаре (он же порядок машины состояний), а не
 * алфавит: состояния читаются как жизненный цикл.
 */
export function statusOptions(kind: StatusKind): Array<{ value: string; label: string }> {
  return Object.entries(VOCABULARY[kind]).map(([value, meta]) => ({ value, label: meta.label }))
}
