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
  seat?: {
    id: number
    row_id?: number
    number?: number
    label?: string
    type?: string
    x?: number | string
    y?: number | string
  } | null
}

/** Результат загрузки инвентаря: места + честная информация о пагинации. */
export interface InventoryResult {
  items: InventoryItem[]
  /** Всего мест на сервере (meta.total). Может быть больше items.length. */
  total: number
  /** True, если сервер отдал не все места (мы дошли до лимита страниц). */
  truncated: boolean
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

  for (;;) {
    const res = await get<InventoryItem[], { current_page: number; per_page: number; total: number; last_page: number }>(
      `/inventory?session_id=${sessionId}&per_page=${INVENTORY_PAGE_SIZE}&page=${page}`,
    )
    // Laravel paginator: { data: [...], meta: {...} }. Иначе data — уже массив.
    const maybe = res as unknown as { data: InventoryItem[] | { data?: InventoryItem[] }; meta?: { total?: number; last_page?: number } }
    const inner = maybe.data
    const list = Array.isArray(inner) ? inner : ((inner as { data?: InventoryItem[] })?.data ?? [])
    if (!Array.isArray(list)) break
    items.push(...list)
    total = Number(maybe.meta?.total ?? items.length) || items.length
    lastPage = Number(maybe.meta?.last_page ?? 1) || 1
    if (list.length === 0 || page >= lastPage || page >= INVENTORY_MAX_PAGES) break
    page += 1
  }

  return { items, total, truncated: items.length < total }
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
    /** public_id (ULID) из CartItemResource — НЕ годится для DELETE-роута. */
    id?: string
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
  currency: string
  items: Array<{ inventory_item_id: number; quantity: number; unit_price: number; total_price: number }>
}

/**
 * Оформить заказ: сервер создаёт Order из удержанных мест корзины.
 * Контактные данные (ТЗ §66) обязательны для e-mail с билетом — сервер их
 * валидирует (customer_email required), поэтому шлём то, что ввёл покупатель.
 */
export async function checkoutSession(
  sessionId: number | string,
  customer: { customer_name?: string; customer_email: string; customer_phone?: string },
): Promise<{ data: CheckoutResult }> {
  return send<CheckoutResult>('/cart/checkout', 'POST', { session_id: Number(sessionId), ...customer })
}