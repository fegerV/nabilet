/**
 * API-клиент мест (витрина).
 *
 * Реальная схема: сессия → inventory_items (продаваемые места/зоны).
 * Каждое место связано с seat (ряд/номер). Состояние места — это статус
 * inventory_items: available | held | sold | unavailable.
 *
 * Контракт API:
 *   GET  /api/v1/inventory?session_id=N   → { data: InventoryItem[], meta }
 *   POST /api/v1/cart/items               → холд места (session_id + inventory_item_id)
 *   DELETE /api/v1/cart/items/{id}        → снятие холда
 */

import { request, get, send } from './api'
import { embedGet, embedSend } from './embed-api'

/**
 * Максимум билетов за один заказ — ЗАПАСНОЕ значение.
 *
 * Основной источник — сервер: `GET /inventory` отдаёт лимит в
 * `meta.max_tickets_per_order`, и витрина использует именно его
 * (см. `fetchInventory` → `maxTicketsPerOrder`). Константа нужна только как
 * фолбэк, если ответ пришёл от старой сборки API без этого поля.
 *
 * Раньше значение было ЕДИНСТВЕННЫМ и жёстко продублированным здесь, в дефолте
 * `SeatMap` и в тексте уведомления. Снижение лимита на сервере тогда давало
 * «кнопка нажимается, а запрос отвергается 422-м». Дублировать его заново
 * нельзя: меняйте `CHECKOUT_MAX_ITEMS` на сервере.
 */
export const MAX_TICKETS_PER_ORDER = 10

/** Статусы inventory_items на бэкенде (InventoryItemStateMachine + CartService). */
export type InventoryStatus = 'available' | 'held' | 'sold_out' | 'sold' | 'blocked' | 'disabled' | 'unavailable' | string

export interface InventoryItem {
  id: number
  public_id?: string
  session_id: number
  type: 'seat' | 'standing' | 'table'
  seat_id?: number | null
  standing_zone_id?: number | null
  price_amount: number
  currency?: string
  capacity?: number
  available_quantity?: number
  status: InventoryStatus
  metadata_json?: Record<string, unknown> | string | null
  /**
   * Стоячая зона (`standing_zones`), подгружается `InventoryController::index()`.
   * Имя зоны живёт здесь, а не в `metadata_json`: в `metadata_json` лежит
   * снимок `sector_name` на момент генерации, и после переименования сектора
   * он устаревает.
   */
  standing_zone?: { id: number; name?: string | null } | null
  seat?: {
    id: number
    row_id?: number
    /** Настоящий НОМЕР ряда (`hall_rows.number` = 1, 2, …). НЕ `row_id`. */
    row_number?: number | string | null
    /** Имя сектора из схемы зала («Партер», «Стол 1»). */
    sector_name?: string | null
    number?: number | string
    label?: string
    type?: string
    x?: number | string
    y?: number | string
    /**
     * Индивидуальная цена места из схемы зала (копейки), `null` — место
     * наследует цену ряда. `InventoryController::index()` отдаёт её вместе с
     * моделью `Seat`; админка цен по рядам использует её, чтобы не затирать
     * VIP-места ценой ряда.
     */
    price_amount?: number | null
  } | null
}

/** Результат загрузки инвентаря: места + честная информация о пагинации. */
export interface InventoryResult {
  items: InventoryItem[]
  /** Всего мест на сервере (meta.total). Может быть больше items.length. */
  total: number
  /** True, если сервер отдал не все места (мы дошли до лимита страниц). */
  truncated: boolean
  /**
   * Лимит билетов на заказ, каким его видит СЕРВЕР (`meta.max_tickets_per_order`).
   * Фолбэк — `MAX_TICKETS_PER_ORDER`, если поле не пришло.
   */
  maxTicketsPerOrder: number
  /**
   * С какой стороны полотна сцена (`meta.stage_side`).
   *
   * Считается на сервере по статичному объекту `kind: "stage"` схемы зала:
   * витрина получает инвентарь без самой схемы, поэтому по местам направление
   * не восстановить. Фолбэк — `top`, как рисовалось раньше.
   */
  stageSide: 'top' | 'bottom'
}

const INVENTORY_PAGE_SIZE = 500
const INVENTORY_MAX_PAGES = 20 // страховка от бесконечного цикла при 10 000+ мест

/**
 * Получить все места сессии. Ответ API пагинированный ({ data, meta });
 * раньше читалась только первая страница per_page=500, и залы больше 500 мест
 * обрезались молча. Теперь листаем страницы до meta.last_page (с разумным
 * лимитом) и возвращаем total/truncated — страница покажет предупреждение.
 */
export async function fetchInventory(sessionId: number | string): Promise<InventoryResult> {
  const items: InventoryItem[] = []
  let total = 0
  let lastPage = 1
  let page = 1
  // Лимит приходит вместе с первой страницей; если сервер его не отдал
  // (старая сборка API) — остаётся фолбэк-константа.
  let maxTicketsPerOrder = MAX_TICKETS_PER_ORDER
  let stageSide: 'top' | 'bottom' = 'top'

  for (;;) {
    const res = await get<
      InventoryItem[],
      { current_page: number; per_page: number; total: number; last_page: number; max_tickets_per_order?: number; stage_side?: string }
    >(`/inventory?session_id=${sessionId}&per_page=${INVENTORY_PAGE_SIZE}&page=${page}`)
    // Laravel paginator: { data: [...], meta: {...} }. Иначе data — уже массив.
    const maybe = res as unknown as {
      data: InventoryItem[] | { data?: InventoryItem[] }
      meta?: { total?: number; last_page?: number; max_tickets_per_order?: number; stage_side?: string }
    }
    const inner = maybe.data
    const list = Array.isArray(inner) ? inner : ((inner as { data?: InventoryItem[] })?.data ?? [])
    if (!Array.isArray(list)) break
    items.push(...list)
    total = Number(maybe.meta?.total ?? items.length) || items.length
    lastPage = Number(maybe.meta?.last_page ?? 1) || 1
    const serverLimit = Number(maybe.meta?.max_tickets_per_order)
    if (Number.isFinite(serverLimit) && serverLimit >= 1) maxTicketsPerOrder = Math.floor(serverLimit)
    if (maybe.meta?.stage_side === 'bottom') stageSide = 'bottom'
    if (list.length === 0 || page >= lastPage || page >= INVENTORY_MAX_PAGES) break
    page += 1
  }

  return { items, total, truncated: items.length < total, maxTicketsPerOrder, stageSide }
}

/** Ответ addItem/GET /cart — форма корзины, которую понимает клиент. */
export interface CartPayload {
  /** БД-id элемента корзины (для DELETE /cart/items/{id}). */
  id?: number | string
  quantity?: number
  unit_price?: string | number
  inventory_item_id?: number
}

export interface ServerCart {
  id?: string
  status?: string
  expires_at?: string | null
  items_count?: number
  total_amount?: string | number
  currency?: string
  items?: Array<{
    /** `cart_items.id` — именно этот id ждёт `DELETE /cart/items/{id}`. */
    id?: number | string
    quantity?: number
    unit_price?: string | number
    inventory_item?: { id?: string | number; type?: string; seat?: { number?: number; row?: number; sector?: string } | null } | null
  }>
}

/** Холд на сервере может вернуть и модель (addItem), и ресурс (GET /cart). */
export interface HoldResponse {
  data?: CartPayload & { cart_item_id?: number | string; id?: number | string }
  cart?: { cart_id?: number; total_amount?: string | number; expires_at?: string | null } | null
  cart_token?: string
}

/** Захолдить место. Возвращает id элемента корзины (cart item) и expires_at корзины. */
export async function holdSeat(
  sessionId: number | string,
  inventoryItemId: number | string,
  quantity = 1,
): Promise<HoldResponse> {
  return send<CartPayload>('/cart/items', 'POST', {
    session_id: Number(sessionId),
    inventory_item_id: Number(inventoryItemId),
    quantity,
  }) as unknown as Promise<HoldResponse>
}

/** Снять холд. */
export async function releaseSeat(sessionId: number | string, cartItemId: number | string): Promise<void> {
  await request(`/cart/items/${cartItemId}`, {
    method: 'DELETE',
    body: { session_id: String(sessionId) },
  })
}

/** Прочитать активную корзину покупателя (X-Cart-Token подставляет api.ts).
 *  Используется для восстановления состояния после F5. */
export async function fetchCart(sessionId: number | string): Promise<ServerCart | null> {
  const res = await get<ServerCart | null>(`/cart?session_id=${sessionId}`)
  return (res?.data ?? null) as ServerCart | null
}

/** Оформить заказ (завершает холд → оплата). POST /cart/checkout → { data: CheckoutResult }. */
export interface CheckoutResult {
  cart_id: number | string
  order_id: string
  session_id: number | string
  total_amount: number
  subtotal_amount: number
  discount_amount: number
  /** Списано бонусами, минорные единицы (фаза 2b). */
  bonus_amount: number
  currency: string
  items: Array<{ inventory_item_id: number; quantity: number; unit_price: number; total_price: number }>
}

/**
 * Оформить заказ: сервер создаёт Order из удержанных мест корзины.
 * Контактные данные (ТЗ §66) обязательны для e-mail с билетом — сервер их
 * валидирует (customer_email required), поэтому шлём то, что ввёл покупатель.
 *
 * `customer_email_confirmation` — повтор ввода адреса. Письмо с билетом уходит
 * ровно на `customer_email`, поэтому сервер сверяет оба поля (`same`): опечатка
 * в одну букву иначе означала бы билет, который не придёт никогда.
 *
 * `bonus_amount` — сколько минорных единиц заказа закрыть бонусами. Необязателен:
 * гость и покупатель без бонусов его не шлют, и оформление для них не меняется.
 * Сумма проходит серверный лимит (`BonusPolicy::maxSpendable`), и превышение —
 * это 422 `BONUS_LIMIT_EXCEEDED`, а не молчаливое урезание: покупатель
 * подтвердил цену, и она не должна измениться втихую.
 *
 * `referral_code` — код приглашения из ссылки `/r/{code}` (фаза 4), сохранённый
 * в localStorage. Необязателен: его шлют только если друг действительно
 * переходил по приглашению. Сервер молча игнорирует неизвестный, чужой или
 * уже использованный код (`ReferralService::attachToOrder()`) — отказ (422)
 * сломал бы покупку из-за устаревшей ссылки, а награда от этого не росла бы.
 */
export async function checkoutSession(
  sessionId: number | string,
  customer: {
    customer_name?: string
    customer_email: string
    customer_email_confirmation?: string
    customer_phone?: string
    bonus_amount?: number
    referral_code?: string
  },
): Promise<{ data: CheckoutResult }> {
  return send<CheckoutResult>('/cart/checkout', 'POST', { session_id: Number(sessionId), ...customer })
}

/**
 * Продлить серверный холд корзины (B5). Возвращает новый `expires_at`
 * (ISO-строка), по которому UI синхронизирует свой таймер. Гостевой токен
 * (X-Cart-Token) подставляет api.ts — сервер найдёт именно корзину этого
 * покупателя. При отсутствии активной корзины сервер вернёт 409 CART_EXPIRED.
 */
export async function extendCartHold(sessionId: number | string): Promise<string> {
  const res = await send<{ expires_at: string }>('/cart/extend', 'POST', {
    session_id: Number(sessionId),
  })
  return res.data.expires_at
}

/* ── embed API: те же операции, но под capability-ключ виджета ───────────── */

const EMBED_CART_KEY = 'nabilet_embed_cart_'

function savedEmbedCart(sessionId: number | string): string {
  try {
    return sessionStorage.getItem(EMBED_CART_KEY + sessionId) ?? ''
  } catch {
    return ''
  }
}

function rememberEmbedCart(sessionId: number | string, cartId: string): void {
  try {
    sessionStorage.setItem(EMBED_CART_KEY + sessionId, cartId)
  } catch {
    // приватный режим: корзина всё равно вернётся сервером по cart_token
  }
}

/** Корзина embed-виджета создаётся один раз и переиспользуется между местами. */
export async function ensureEmbedCart(sessionId: number | string): Promise<string> {
  const saved = savedEmbedCart(sessionId)
  if (saved) return saved

  const res = await embedSend<{ id: string | number }>('/carts', 'POST', {
    session_id: String(sessionId),
  })
  const cartId = String(res.data.id)
  rememberEmbedCart(sessionId, cartId)
  return cartId
}

/** Карта зала из embed API: конверт совпадает с витринным /inventory. */
export async function fetchEmbedInventory(sessionId: number | string): Promise<InventoryResult> {
  const payload = await embedGet<{
    data: InventoryItem[]
    total?: number
    last_page?: number
  }>(`/sessions/${encodeURIComponent(String(sessionId))}/seatmap`)
  const list = Array.isArray(payload.data?.data) ? payload.data.data : []
  const meta = (payload.meta ?? {}) as {
    total?: number
    last_page?: number
    max_tickets_per_order?: number
    stage_side?: string
  }
  const total = Number(meta.total ?? list.length) || list.length
  const serverLimit = Number(meta.max_tickets_per_order)

  return {
    items: list,
    total,
    truncated: list.length < total,
    maxTicketsPerOrder: Number.isFinite(serverLimit) && serverLimit >= 1
      ? Math.floor(serverLimit)
      : MAX_TICKETS_PER_ORDER,
    stageSide: meta.stage_side === 'bottom' ? 'bottom' : 'top',
  }
}

/** Холд места через embed API. cart_item_id достаём из ответа CartResource. */
export async function holdEmbedSeat(
  sessionId: number | string,
  inventoryItemId: number | string,
  quantity = 1,
): Promise<HoldResponse> {
  const cartId = await ensureEmbedCart(sessionId)
  const res = await embedSend<{
    id?: string | number
    expires_at?: string
    items?: Array<{ id?: string | number; inventory_item?: { public_id?: string; id?: string | number } }>
  }>(`/carts/${encodeURIComponent(cartId)}/items`, 'POST', {
    inventory_item_id: String(inventoryItemId),
    quantity,
  })

  const items = Array.isArray(res.data.items) ? res.data.items : []
  const placed = items.find((item) => {
    const ref = String(item.inventory_item?.public_id ?? item.inventory_item?.id ?? '')
    return ref === String(inventoryItemId)
  }) ?? items[items.length - 1]

  return {
    data: { id: placed?.id, inventory_item_id: Number(inventoryItemId) },
    cart: { expires_at: res.data.expires_at ?? '' },
  }
}

/** Восстановление корзины после F5 внутри embed-рамки. */
export async function fetchEmbedCart(sessionId: number | string): Promise<ServerCart | null> {
  // У embed API нет GET корзины по id: POST /carts идемпотентен по cart_token
  // и возвращает ту же корзину с позициями.
  const res = await embedSend<ServerCart>('/carts', 'POST', { session_id: String(sessionId) })
  if (res.data?.id !== undefined) rememberEmbedCart(sessionId, String(res.data.id))
  return res.data ?? null
}

/** Чекаут embed-виджета: OrderResource → привычный CheckoutResult витрины. */
export async function checkoutEmbedOrder(
  sessionId: number | string,
  customer: {
    customer_name?: string
    customer_email: string
    customer_email_confirmation?: string
    customer_phone?: string
    bonus_amount?: number
    referral_code?: string
  },
): Promise<{ data: CheckoutResult }> {
  const cartId = await ensureEmbedCart(sessionId)
  const res = await embedSend<{
    id: string
    totals: {
      subtotal_amount: number
      discount_amount: number
      bonus_amount: number
      total_amount: number
      currency: string
    }
    items?: CheckoutResult['items']
  }>('/orders', 'POST', {
    cart_id: cartId,
    customer_name: customer.customer_name ?? null,
    customer_email: customer.customer_email,
    customer_email_confirmation: customer.customer_email_confirmation ?? null,
    customer_phone: customer.customer_phone ?? null,
    bonus_amount: customer.bonus_amount ?? 0,
    referral_code: customer.referral_code ?? null,
  })
  const order = res.data

  return {
    data: {
      cart_id: cartId,
      order_id: order.id,
      session_id: Number(sessionId),
      total_amount: order.totals.total_amount,
      subtotal_amount: order.totals.subtotal_amount,
      discount_amount: order.totals.discount_amount,
      bonus_amount: order.totals.bonus_amount,
      currency: order.totals.currency,
      items: order.items ?? [],
    },
  }
}