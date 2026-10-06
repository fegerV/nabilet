/**
 * Холды мест: постановка, снятие, восстановление после перезагрузки и ошибки.
 *
 * Выделено из `SeatSelectionPage.vue` (P2). Здесь всё, что разговаривает с
 * сервером про удержание места и синхронизирует локальный стор с сервером:
 *
 *  - `onToggle` / `onToggleCoord` — поставить или снять холд;
 *  - `hydrateCart` — восстановить выбор после F5 (локальный стор пуст, серверные
 *    холды живы);
 *  - `remove` — снятие из сводки;
 *  - `notifyHoldError` — человеческие сообщения по КОДУ ошибки.
 *
 * Композабл не знает, какой картой рисуется зал: `onToggle` принимает `Seat`
 * (рядная карта), `onToggleCoord` — `InventoryItem` (координатная). Так страница
 * остаётся тонким оркестратором.
 */
import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { useCartStore, type CartSeat } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { holdSeat, releaseSeat, fetchCart, type InventoryItem, type HoldResponse } from '@/lib/inventory'
import { ApiError } from '@/lib/api'
import { trackEvent } from '@/lib/metrika'
import type { Seat } from '@/lib/hall'
import { rowNumberOf, seatNumberOf, sectorNameOf, toNumber } from './useSeatInventory'

export interface UseSeatHoldsOptions {
  sessionId: ComputedRef<string>
  /** Загруженный инвентарь — нужен, чтобы сопоставить public_id позиции корзины. */
  inventory: Ref<InventoryItem[]>
  maxTickets: Ref<number>
  /** Перезагрузить схему зала: вызывается, когда состояние клиента разошлось с сервером. */
  reload: () => Promise<void>
}

export function useSeatHolds(options: UseSeatHoldsOptions) {
  const { sessionId, inventory, maxTickets, reload } = options
  const cart = useCartStore()
  const ui = useUiStore()

  /** Защита от спама кликов: пока холд/снятие в полёте — место не трогается. */
  const pendingSeats = ref<Set<string>>(new Set())

  /**
   * БД-id инвентарь-элемента по позиции серверной корзины.
   *
   * `CartItemResource` отдаёт `inventory_item.id` = public_id (ULID), а локальная
   * корзина ключуется числовым `inventory_items.id` (для DELETE). Поэтому public_id
   * сопоставляем с загруженным инвентарём. `null` — позиция не опознана.
   */
  function resolveInventoryId(item: { inventory_item?: { id?: string | number } | null }): string | null {
    const invPublicId = String(item.inventory_item?.id ?? '')
    if (invPublicId === '') return null
    const inv = inventory.value.find(
      (i) => String(i.public_id ?? '') === invPublicId || String(i.id) === invPublicId,
    )
    return inv ? String(inv.id) : null
  }

  /** Применить серверную корзину к локальному стору (hydrate после перезагрузки). */
  function applyServerCart(cartData: Awaited<ReturnType<typeof fetchCart>>): void {
    if (!cartData || !Array.isArray(cartData.items) || cartData.items.length === 0) return
    let restored = 0
    for (const item of cartData.items) {
      const id = resolveInventoryId(item)
      if (id === null) continue
      if (cart.isSelected(id)) continue
      const inv = inventory.value.find((i) => String(i.id) === id)
      // `item.id` — это `cart_items.id`, ровно то, что ждёт `DELETE /cart/items/{id}`.
      // Раньше сюда писался id ИНВЕНТАРЯ, и после F5 снятие места уходило на
      // `DELETE /cart/items/{inventory_id}` → 404 CART_ITEM_NOT_FOUND: место
      // оставалось удержанным, хотя пользователь считал, что снял его.
      if (item.id !== undefined && item.id !== null) cart.meta[id] = String(item.id)
      cart.toggle({
        id,
        // CartItemResource уже отдаёт настоящие номер ряда и сектор; инвентарь — фолбэк.
        sector: String(item.inventory_item?.seat?.sector ?? (inv ? sectorNameOf(inv) : 'Зал')),
        row: Number(item.inventory_item?.seat?.row ?? (inv ? rowNumberOf(inv) : 0)),
        number: Number(item.inventory_item?.seat?.number ?? (inv ? seatNumberOf(inv) : 0)),
        priceMinor: Number(item.unit_price ?? inv?.price_amount ?? 0),
        kind: 'standard',
        // `quantity` серверной позиции обязателен: без него танцпол qty=3 после F5
        // восстанавливался как один билет — сводка и итог занижались втрое.
        quantity: Math.max(1, Number(item.quantity ?? 1) || 1),
      })
      restored += 1
    }
    // Таймер — от серверного expires_at, а не от локальных 600 секунд.
    if (cartData.expires_at) cart.setHoldExpiry(cartData.expires_at)
    if (restored > 0) {
      ui.notify('brand', 'Выбор восстановлен', `Сервер ещё держит для вас мест: ${restored}.`)
    }
  }

  /**
   * Восстановление после F5: локальный стор пуст, а серверные холды живы.
   * Читаем `GET /cart` (X-Cart-Token подставляет api.ts) и возвращаем выбор.
   */
  async function hydrateCart(sid: string): Promise<void> {
    let serverCart: Awaited<ReturnType<typeof fetchCart>> = null
    try {
      serverCart = await fetchCart(sid)
    } catch {
      // Корзина недоступна (сеть/500) — не трогаем локальное состояние, работаем
      // от локального отсчёта. Ошибочно снимать выбор из-за сбоя нельзя.
      return
    }

    if (cart.count > 0) {
      // Локальная корзина жива (возврат из SPA-навигации). Сверяем СОСТАВ, а не
      // только таймер: место могло истечь или уйти другому покупателю, и тогда
      // локально оно осталось бы «выбранным» призраком — с ним checkout упал бы.
      const serverIds = new Set(
        (serverCart?.items ?? [])
          .map((it) => resolveInventoryId(it))
          .filter((x): x is string => x !== null),
      )
      const serverCartEmpty = !serverCart || (serverCart.items?.length ?? 0) === 0

      let dropped = 0
      for (const id of [...cart.selectedIds]) {
        if (serverIds.has(id)) continue
        // Пустая серверная корзина = холдов нет вовсе → все локальные выборы призраки.
        // Иначе снимаем только опознанные места (неопознанные не судим — инвентарь
        // мог быть обрезан пагинацией).
        const known = inventory.value.some((i) => String(i.id) === id)
        if (!serverCartEmpty && !known) continue
        const seat = cart.seats.find((s) => s.id === id)
        if (seat) cart.toggle(seat)
        delete cart.meta[id]
        dropped += 1
      }
      if (dropped > 0) {
        ui.notify(
          'sun',
          'Выбор обновлён',
          `Сервер больше не держит ${dropped} мест(а) — они сняты с выбора.`,
        )
      }
      if (cart.count > 0 && serverCart?.expires_at) cart.setHoldExpiry(serverCart.expires_at)
      return
    }

    applyServerCart(serverCart)
  }

  /** Обработать ответ холда: сохранить cart_item_id и серверный expires_at. */
  function adoptHoldResponse(inventoryItemId: string, res: HoldResponse): void {
    const cartItemId = res.data?.id ?? res.data?.cart_item_id
    if (cartItemId !== undefined && cartItemId !== null) cart.meta[inventoryItemId] = String(cartItemId)
    const expiresAt = res.cart?.expires_at ?? (res.data as { cart?: { expires_at?: string } } | undefined)?.cart?.expires_at
    if (expiresAt) cart.setHoldExpiry(expiresAt)
  }

  /**
   * Сообщения об ошибках — по КОДУ и статусу, а не одна надпись на все случаи.
   *
   * Порядок важен: `CART_EXPIRED` — это тоже 409 (`ConflictError`), поэтому
   * проверка кода обязана идти ДО общей ветки 409, иначе «корзина протухла»
   * показывалась бы как «место занял другой покупатель».
   */
  function notifyHoldError(e: unknown, seatId: string): void {
    if (!(e instanceof ApiError)) {
      ui.notify('rose', 'Не получилось', e instanceof Error ? e.message : 'Попробуйте ещё раз')
      return
    }

    // 409 CART_EXPIRED / 410: серверный холд корзины истёк.
    if (e.code === 'CART_EXPIRED' || e.status === 410) {
      ui.notify('rose', 'Время удержания истекло', 'Корзина на сервере протухла. Освежите схему и выберите места заново.')
      void reload()
      return
    }

    // 409 ITEM_SESSION_MISMATCH: место принадлежит другому сеансу — состояние
    // клиента разошлось с сервером, перезагружаем схему.
    if (e.code === 'ITEM_SESSION_MISMATCH') {
      ui.notify('rose', 'Место из другого сеанса', 'Это место принадлежит другому сеансу. Схема обновлена — выберите заново.')
      void reload()
      return
    }

    // 422 SALES_CLOSED: организатор остановил продажи.
    if (e.code === 'SALES_CLOSED') {
      ui.notify('rose', 'Продажи закрыты', 'Организатор остановил продажи по этому сеансу.')
      void reload()
      return
    }

    // Прочие 409 (SEAT_UNAVAILABLE и т.п.): место только что занял другой покупатель.
    if (e.status === 409) {
      cart.markSeatHeld(seatId)
      ui.notify('sun', 'Место уже занято', 'Это место только что придержал другой покупатель. Выберите другое.')
      return
    }

    // 422: ошибка валидации запроса — показываем, что именно не так.
    if (e.status === 422) {
      const first = e.details ? Object.values(e.details)[0]?.[0] : null
      ui.notify('rose', 'Запрос отклонён', first ?? e.message)
      return
    }

    // 5xx: сбой сервиса — не вина пользователя, предлагаем повторить.
    if (e.status >= 500) {
      ui.notify('rose', 'Сервис недоступен', 'Не удалось удержать место из-за сбоя сервиса. Обновите схему и попробуйте снова.')
      void reload()
      return
    }

    ui.notify('rose', 'Не получилось', e.message || 'Попробуйте ещё раз')
  }

  async function withPending(id: string, action: () => Promise<void>): Promise<void> {
    if (pendingSeats.value.has(id)) return
    pendingSeats.value = new Set([...pendingSeats.value, id])
    try {
      await action()
    } catch (e) {
      notifyHoldError(e, id)
    } finally {
      const next = new Set(pendingSeats.value)
      next.delete(id)
      pendingSeats.value = next
    }
  }

  /** Холд на сервере при выборе места в РЯДНОЙ карте. */
  async function onToggle(seat: Seat, sectorName: string): Promise<void> {
    await withPending(seat.id, async () => {
      const isSelected = cart.isSelected(seat.id)

      if (isSelected) {
        // Снимаем холд на сервере
        const cartItem = cart.meta[seat.id]
        if (cartItem) await releaseSeat(sessionId.value, cartItem)
        cart.toggle({
          id: seat.id,
          sector: sectorName,
          row: seat.row,
          number: seat.number,
          priceMinor: seat.priceMinor,
          kind: seat.kind,
          // Рядная карта показывает только `type = 'seat'`: одна позиция = один билет.
          quantity: 1,
        })
        if (cart.meta[seat.id]) delete cart.meta[seat.id]
        return
      }

      // Холдим на сервере
      const res = await holdSeat(sessionId.value, seat.id)
      adoptHoldResponse(seat.id, res)
      cart.toggle({
        id: seat.id,
        sector: sectorName,
        row: seat.row,
        number: seat.number,
        priceMinor: seat.priceMinor,
        kind: seat.kind,
        // Рядная карта показывает только `type = 'seat'`: одна позиция = один билет.
        quantity: 1,
      })
      // Цель воронки: место выбрано (config/metrika.php: seat_selected).
      void trackEvent('seat_selected').catch(() => {})
    })
  }

  /**
   * Клик по месту/зоне на КООРДИНАТНОЙ карте: item — место или танцпол, qty — количество.
   *
   * Танцпол снимается ТЕМ ЖЕ кликом, что и ставится: раньше ветка `standing`
   * безусловно вызывала `holdSeat()` и `cart.toggle()`, поэтому повторный клик по
   * уже выбранной зоне локально снимал выбор, а серверный холд оставался висеть —
   * зона числилась занятой за покупателем до истечения TTL. Теперь снятие идёт
   * через `releaseSeat`, как у обычного места.
   */
  async function onToggleCoord(item: InventoryItem, qty: number): Promise<void> {
    const id = String(item.id)

    await withPending(id, async () => {
      if (item.type === 'standing') {
        if (cart.isSelected(id)) {
          const cartItem = cart.meta[id]
          if (cartItem) await releaseSeat(sessionId.value, cartItem)
          const seat = cart.seats.find((s) => s.id === id)
          if (seat) cart.toggle(seat)
          if (cart.meta[id]) delete cart.meta[id]
          return
        }
        // Покупка qty билетов на стоячую зону: одна позиция корзины, N билетов.
        const res = await holdSeat(sessionId.value, item.id, qty)
        adoptHoldResponse(id, res)
        cart.toggle({
          id,
          sector: String((item.metadata_json as Record<string, unknown> | null)?.sector_name ?? 'Танцпол'),
          row: 0,
          number: 0,
          priceMinor: Number(item.price_amount ?? 0),
          kind: 'standard',
          quantity: Math.max(1, qty),
        })
        void trackEvent('seat_selected').catch(() => {})
        return
      }

      // Обычное место
      if (cart.isSelected(id)) {
        const cartItem = cart.meta[id]
        if (cartItem) await releaseSeat(sessionId.value, cartItem)
        cart.toggle({
          id,
          sector: sectorNameOf(item),
          row: rowNumberOf(item),
          number: seatNumberOf(item),
          priceMinor: toNumber(item.price_amount),
          kind: 'standard',
          quantity: 1,
        })
        if (cart.meta[id]) delete cart.meta[id]
        return
      }

      const res = await holdSeat(sessionId.value, item.id)
      adoptHoldResponse(id, res)
      cart.toggle({
        id,
        sector: sectorNameOf(item),
        row: rowNumberOf(item),
        number: seatNumberOf(item),
        priceMinor: toNumber(item.price_amount),
        kind: 'standard',
        quantity: 1,
      })
      void trackEvent('seat_selected').catch(() => {})
    })
  }

  function onLimit(): void {
    ui.notify('sun', 'Больше нельзя', `За один раз можно взять не больше ${maxTickets.value} билетов`)
  }

  /** Снятие места из сводки. Ошибка DELETE больше не глотается молча: если сервер
   *  не отпустил холд, предупреждаем и перезагружаем схему — иначе место «висит»
   *  занятым, а пользователь думает, что оно свободно. */
  function remove(id: string): void {
    const seat = cart.seats.find((s: CartSeat) => s.id === id)
    if (!seat) return
    const cartItem = cart.meta[id]
    if (cartItem) {
      releaseSeat(sessionId.value, cartItem)
        .then(() => {
          cart.toggle(seat)
          delete cart.meta[id]
        })
        .catch(() => {
          ui.notify(
            'sun',
            'Место могло остаться занятым',
            'Сервер не подтвердил снятие холда. Обновите схему — возможно, место ещё держится за вами.',
          )
        })
      return
    }
    cart.toggle(seat)
  }

  return {
    pendingSeats: computed(() => pendingSeats.value),
    hydrateCart,
    onToggle,
    onToggleCoord,
    onLimit,
    remove,
  }
}
