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
  status: 'available' | 'held' | 'sold' | 'unavailable'
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

/** Получить все места сессии. Ответ API пагинированный: { data: { data: [...], meta } }. */
export async function fetchInventory(sessionId: number | string): Promise<InventoryItem[]> {
  const res = await get<{ data: InventoryItem[]; meta?: unknown } | InventoryItem[]>(
    `/inventory?session_id=${sessionId}&per_page=500`,
  )
  // Laravel paginator: data.data — массив. Иначе (кастомный ответ) data — уже массив.
  const maybe = res as unknown as { data: { data?: InventoryItem[] } | InventoryItem[] }
  const inner = maybe.data
  if (Array.isArray(inner)) return inner
  if (inner && Array.isArray((inner as { data?: InventoryItem[] }).data)) {
    return (inner as { data: InventoryItem[] }).data
  }
  return []
}

/** Захолдить место. Возвращает id элемента корзины (cart item). */
export async function holdSeat(
  sessionId: number | string,
  inventoryItemId: number | string,
  quantity = 1,
): Promise<{ data: { id?: number | string } }> {
  return send<{ id?: number | string }>('/cart/items', 'POST', {
    session_id: Number(sessionId),
    inventory_item_id: Number(inventoryItemId),
    quantity,
  })
}

/** Снять холд. */
export async function releaseSeat(sessionId: number | string, cartItemId: number | string): Promise<void> {
  await request(`/cart/items/${cartItemId}`, {
    method: 'DELETE',
    body: { session_id: String(sessionId) },
  })
}

/** Оформить заказ (завершает холд → оплата). */
export async function checkoutSession(sessionId: number | string): Promise<{ data: unknown }> {
  return send<unknown>('/cart/checkout', 'POST', { session_id: Number(sessionId) })
}