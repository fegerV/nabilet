/**
 * Корзина и удержание мест.
 *
 * Главное правило продукта: фронтенд не является источником истины о наличии
 * и о цене (инварианты 5 и 6 из ТЗ). Поэтому стор хранит только намерение
 * пользователя — идентификаторы мест — и таймер удержания. Цена приходит вместе
 * с местом из данных сессии, а сервер при подтверждении считает её заново.
 *
 * Таймер удержания — не «красивая игрушка»: это прямой перенос hold на 10 минут
 * из ТЗ §7 в интерфейс. За минуту до истечения пользователю предлагают
 * продлить, а не просто выбрасывают его из сценария.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { extendCartHold } from '@/lib/inventory'
import { ApiError } from '@/lib/api'

export interface CartSeat {
  id: string
  sector: string
  row: number
  number: number
  priceMinor: number
  kind: 'standard' | 'vip' | 'accessible'
}

const HOLD_SECONDS = 10 * 60
const WARN_SECONDS = 60

/**
 * Ключ sessionStorage, под которым заказ переживает уход на платёжную страницу.
 *
 * Редирект на оплату — это ПОЛНАЯ навигация (в реальном режиме — на домен
 * провайдера), поэтому in-memory состояние стора умирает, а страница результата
 * после возврата обязана показать сумму и номер заказа. `sessionStorage`, а не
 * `localStorage`: срок жизни заказа — текущая вкладка, и чужой вкладке он не
 * нужен.
 */
const ORDER_STORAGE_KEY = 'nabilet_last_order'

interface StoredOrder {
  id: string | null
  totalMinor: number
}

function readStoredOrder(): StoredOrder {
  try {
    const raw = sessionStorage.getItem(ORDER_STORAGE_KEY)
    if (!raw) return { id: null, totalMinor: 0 }
    const parsed = JSON.parse(raw) as Partial<StoredOrder>
    return {
      id: typeof parsed.id === 'string' && parsed.id !== '' ? parsed.id : null,
      totalMinor: Number(parsed.totalMinor) || 0,
    }
  } catch {
    return { id: null, totalMinor: 0 } // приватный режим / битый JSON — не критично
  }
}

function writeStoredOrder(order: StoredOrder): void {
  try {
    sessionStorage.setItem(ORDER_STORAGE_KEY, JSON.stringify(order))
  } catch {
    /* ignore — страница результата просто не покажет сумму */
  }
}

export const useCartStore = defineStore('cart', () => {
  const seats = ref<CartSeat[]>([])
  const selectedIds = ref<Set<string>>(new Set())
  const holdSecondsLeft = ref(0)
  const promoCode = ref<string | null>(null)
  const promoDiscountMinor = ref(0)
  const loading = ref(false)
  /** cart_item_id по id места — для снятия холда на сервере. */
  const meta = ref<Record<string, string>>({})
  /** Сеанс, к которому относится корзина — нужен для продления холда на сервере. */
  const sessionId = ref<string | null>(null)
  /** Сообщение об ошибке продления: честный провал вместо имитации успеха (B5). */
  const holdExtendError = ref<string | null>(null)
  /** Момент серверного expires_at (мс). Источник истины по таймеру — сервер. */
  const holdExpiresAtMs = ref<number | null>(null)

  /**
   * Заказ, созданный POST /cart/checkout (order_id в ответе сервера). Оплата
   * ходит на сервер именно по нему; без него страница результата не может
   * подтвердить статус и шлёт payment_fail вместо выдуманного успеха.
   *
   * Значения читаются из sessionStorage: уход на оплату — полная навигация,
   * и после возврата страница результата обязана помнить сумму и номер заказа.
   */
  const storedOrder = readStoredOrder()
  const orderId = ref<string | null>(storedOrder.id)
  /** Итог в минимальных единицах из ответа checkout (серверная цена, инвариант 6). */
  const orderTotalMinor = ref<number>(storedOrder.totalMinor)

  let ticker: number | null = null

  const count = computed(() => selectedIds.value.size)
  const subtotalMinor = computed(() =>
    [...selectedIds.value].reduce((sum, id) => sum + (seats.value.find((s) => s.id === id)?.priceMinor ?? 0), 0),
  )
  const discountMinor = computed(() => Math.min(promoDiscountMinor.value, subtotalMinor.value))
  const totalMinor = computed(() => Math.max(0, subtotalMinor.value - discountMinor.value))
  const holdWarning = computed(() => holdSecondsLeft.value > 0 && holdSecondsLeft.value <= WARN_SECONDS)

  function isSelected(id: string): boolean {
    return selectedIds.value.has(id)
  }

  function toggle(seat: CartSeat): void {
    const next = new Set(selectedIds.value)
    if (next.has(seat.id)) {
      next.delete(seat.id)
    } else {
      next.add(seat.id)
      if (!seats.value.some((s) => s.id === seat.id)) seats.value.push(seat)
    }
    selectedIds.value = next

    // Удержание стартует с первого выбранного места — как в ТЗ: hold
    // создаётся на момент выбора, а не на момент перехода в корзину.
    // Если сервер уже сообщил expires_at (см. setHoldExpiry), локальный
    // отсчёт НЕ переписывает серверное время.
    if (next.size > 0 && holdSecondsLeft.value === 0 && holdExpiresAtMs.value === null) startHold()
    if (next.size === 0) stopHold()
  }

  function clear(): void {
    selectedIds.value = new Set()
    seats.value = []
    meta.value = {}
    holdExtendError.value = null
    promoCode.value = null
    promoDiscountMinor.value = 0
    // orderId/orderTotalMinor НЕ сбрасываем: они нужны странице результата
    // оплаты, которая идёт сразу после успешного checkout/clear холда.
    stopHold()
  }

  /**
   * Восстановить выбор из серверной корзины.
   *
   * Нужен при полной перезагрузке на шаге оформления: стор живёт в памяти, а
   * холды на сервере — нет, поэтому без восстановления F5 на `/checkout` рисует
   * «корзина пуста» поверх реально удержанных мест. Идентификаторы здесь —
   * публичные id инвентаря (как их отдаёт `CartItemResource`); для оформления
   * этого достаточно: заказ строит сервер по своей корзине, а не по нашему списку.
   */
  function hydrate(items: CartSeat[]): void {
    if (items.length === 0) return
    seats.value = [...items]
    selectedIds.value = new Set(items.map((s) => s.id))
    if (holdSecondsLeft.value === 0 && holdExpiresAtMs.value === null) startHold()
  }

  /** Результат POST /cart/checkout: серверный заказ и итог (истина — сервер). */
  function setOrder(id: string | null, totalMinor: number): void {
    orderId.value = id
    orderTotalMinor.value = Number.isFinite(totalMinor) ? Math.max(0, Math.round(totalMinor)) : 0
    // Переживаем редирект на платёжную страницу и обратно.
    writeStoredOrder({ id: orderId.value, totalMinor: orderTotalMinor.value })
  }

  /** Тик: остаток всегда пересчитывается от дедлайна, а не вычитанием секунды. */
  function tick(): void {
    if (holdExpiresAtMs.value !== null) {
      const left = Math.max(0, Math.ceil((holdExpiresAtMs.value - Date.now()) / 1000))
      holdSecondsLeft.value = left
      if (left <= 0) onHoldExpired()
      return
    }
    holdSecondsLeft.value -= 1
    if (holdSecondsLeft.value <= 0) onHoldExpired()
  }

  /**
   * Серверный дедлайн холда (expires_at из ответа addItem / GET /cart).
   * Инвариант: фронтенд не источник истины — и про время удержания тоже.
   * Локальные 600 c из ТЗ расходились с серверными 15 минью из конфига;
   * теперь UI живёт ровно до серверного expires_at.
   */
  function setHoldExpiry(expiresAt: string | number | Date | null | undefined): void {
    if (!expiresAt) return
    const ms = expiresAt instanceof Date ? expiresAt.getTime() : typeof expiresAt === 'number' ? expiresAt : Date.parse(expiresAt)
    if (!Number.isFinite(ms)) return
    holdExpiresAtMs.value = ms
    stopTicker()
    tick()
    if (holdSecondsLeft.value > 0) ticker = window.setInterval(tick, 1000)
  }

  function startHold(seconds = HOLD_SECONDS): void {
    // Без серверного дедлайна — локальная страховка (демо/ошибка ответа).
    holdExpiresAtMs.value = null
    holdSecondsLeft.value = seconds
    stopTicker()
    ticker = window.setInterval(tick, 1000)
  }

  /** Запомнить сеанс корзины (вызывается со страницы оформления). */
  function setSession(id: string | null): void {
    sessionId.value = id ? String(id) : null
  }

  /**
   * «Продлить» — реально продлевает серверный холд (B5).
   *
   * Раньше кнопка лишь перерисовывала локальный таймер (endpoint расширения
   * отсутствовал) — серверный холд истекал, места уходили, а UI показывал
   * ложные «ещё 10 минут». Теперь вызываем POST /cart/extend и синхронизируем
   * таймер с возвращённым expires_at. При ошибке — честное сообщение, без
   * имитации успеха.
   */
  async function extendHold(): Promise<void> {
    holdExtendError.value = null
    if (!sessionId.value) {
      holdExtendError.value = 'Не удалось определить сеанс для продления холда.'
      return
    }
    try {
      const expiresAt = await extendCartHold(sessionId.value)
      setHoldExpiry(expiresAt)
    } catch (e) {
      holdExtendError.value =
        e instanceof ApiError
          ? e.message
          : 'Не удалось продлить удержание. Попробуйте оформить заказ заново.'
    }
  }

  /** Холд истёк: выбор снимается, но серверные item'ы могут ещё жить — помечаем. */
  function onHoldExpired(): void {
    stopHold()
    expired.value = true
    selectedIds.value = new Set()
    seats.value = []
    meta.value = {}
  }

  /** True, когда локальный таймер умер, а мы не уверены, что сервер отпустил места. */
  const expired = ref(false)

  /** Местом завладел другой покупатель (409): синхронизируем схему без перезагрузки. */
  function markSeatHeld(inventoryItemId: string | number): void {
    heldExternally.value = new Set([...heldExternally.value, String(inventoryItemId)])
  }

  const heldExternally = ref<Set<string>>(new Set())

  function release(): void {
    stopHold()
    // Места освобождены на сервере: намерение пользователя тоже снимается.
    selectedIds.value = new Set()
    seats.value = []
    meta.value = {}
  }

  function stopHold(): void {
    holdSecondsLeft.value = 0
    holdExpiresAtMs.value = null
    stopTicker()
  }

  function stopTicker(): void {
    if (ticker !== null) {
      window.clearInterval(ticker)
      ticker = null
    }
  }

  function applyPromo(code: string, discountMinor: number): void {
    promoCode.value = code
    promoDiscountMinor.value = discountMinor
  }

  function setLoading(value: boolean): void {
    loading.value = value
  }

  return {
    seats,
    selectedIds,
    meta,
    holdSecondsLeft,
    holdExpiresAtMs,
    expired,
    heldExternally,
    orderId,
    orderTotalMinor,
    promoCode,
    promoDiscountMinor,
    loading,
    sessionId,
    holdExtendError,
    count,
    subtotalMinor,
    discountMinor,
    totalMinor,
    holdWarning,
    isSelected,
    toggle,
    hydrate,
    clear,
    setOrder,
    startHold,
    setHoldExpiry,
    setSession,
    extendHold,
    markSeatHeld,
    release,
    stopHold,
    applyPromo,
    setLoading,
  }
})
