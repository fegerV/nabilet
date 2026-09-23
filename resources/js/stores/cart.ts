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

export const useCartStore = defineStore('cart', () => {
  const seats = ref<CartSeat[]>([])
  const selectedIds = ref<Set<string>>(new Set())
  const holdSecondsLeft = ref(0)
  const promoCode = ref<string | null>(null)
  const promoDiscountMinor = ref(0)
  const loading = ref(false)

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
    if (next.size > 0 && holdSecondsLeft.value === 0) startHold()
    if (next.size === 0) stopHold()
  }

  function clear(): void {
    selectedIds.value = new Set()
    seats.value = []
    promoCode.value = null
    promoDiscountMinor.value = 0
    stopHold()
  }

  function startHold(seconds = HOLD_SECONDS): void {
    holdSecondsLeft.value = seconds
    stopTicker()
    ticker = window.setInterval(() => {
      holdSecondsLeft.value -= 1
      if (holdSecondsLeft.value <= 0) release()
    }, 1000)
  }

  function extendHold(): void {
    startHold(HOLD_SECONDS)
  }

  function release(): void {
    stopHold()
    // Места освобождены на сервере: намерение пользователя тоже снимается.
    selectedIds.value = new Set()
    seats.value = []
  }

  function stopHold(): void {
    holdSecondsLeft.value = 0
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
    holdSecondsLeft,
    promoCode,
    promoDiscountMinor,
    loading,
    count,
    subtotalMinor,
    discountMinor,
    totalMinor,
    holdWarning,
    isSelected,
    toggle,
    clear,
    startHold,
    extendHold,
    release,
    stopHold,
    applyPromo,
    setLoading,
  }
})
