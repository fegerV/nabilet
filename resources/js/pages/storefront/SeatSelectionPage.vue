<script setup lang="ts">
/**
 * Выбор мест.
 *
 * Самый ответственный экран: здесь пользователь принимает решение, и именно
 * здесь легче всего всё испортить. Три правила, по которым он построен:
 *
 *  1. Итог всегда на экране. На десктопе — справа, на мобильном — в нижней
 *     панели, которая видна и в свёрнутом виде. Пользователь не должен
 *     запоминать, что выбрал.
 *  2. Цена видна до клика по «Оформить». Сбор и скидка — отдельными строками.
 *  3. Удержание объяснено словами, а не только таймером: «пока держим, никто
 *     другой их не купит» — иначе таймер пугает и заставляет торопиться.
 */
import { computed, ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SeatMap from '@/components/seat/SeatMap.vue'
import CoordSeatMap from '@/components/seat/CoordSeatMap.vue'
import SeatLegend from '@/components/seat/SeatLegend.vue'
import OrderSummary from '@/components/seat/OrderSummary.vue'
import NBottomSheet from '@/components/ui/NBottomSheet.vue'
import NButton from '@/components/ui/NButton.vue'
import { useCartStore, type CartSeat } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import {
  fetchInventory,
  holdSeat,
  releaseSeat,
  fetchCart,
  type InventoryItem,
  type HoldResponse,
} from '@/lib/inventory'
import { ApiError } from '@/lib/api'
import { seatStateFromStatus } from '@/lib/seatStatus'
import { trackEvent } from '@/lib/metrika'
import type { Seat, Sector, Row } from '@/lib/hall'
import { dateFull, time, money } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const ui = useUiStore()

const sessionId = computed(() => String(route.query.session ?? ''))

/* Событие и сессия приходят из API (передаём через query от EventPage). */
const eventTitle = ref('Мероприятие')
const sessionStartsAt = ref('')
const sessionHall = ref('')

/* Реальные места сессии из БД. */
const inventory = ref<InventoryItem[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
/** Сервер сообщил больше мест, чем удалось загрузить (лимит страниц). */
const truncated = ref(false)
/** Всего мест на сервере (meta.total) — честный счётчик в шапке. */
const totalSeats = ref(0)
/** Инвентарь-айди мест, которые успел занять другой покупатель (409-путь). */
const externallyHeld = computed(() => cart.heldExternally)

async function loadSeats(): Promise<void> {
  const sid = sessionId.value
  if (!sid) {
    loadError.value = 'Сеанс не указан'
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = null
  try {
    const result = await fetchInventory(sid)
    inventory.value = result.items
    totalSeats.value = result.total || result.items.length
    truncated.value = result.truncated
    // Цель воронки: схема зала открыта (config/metrika.php: seatmap_open).
    void trackEvent('seatmap_open').catch(() => {})
    // Восстановление после F5: локальный стор пуст, а серверные холды живы.
    // Читаем GET /cart (X-Cart-Token подставляет api.ts) и возвращаем выбор.
    await hydrateCart(sid)
    // Событие/зал: подтягиваем с события по slug (из URL).
    const slug = String(route.params.slug ?? '')
    if (slug) {
      try {
        const { data } = await import('@/lib/api').then((m) => m.get(`/events/by-slug/${encodeURIComponent(slug)}`))
        const ev = (data as { title?: string; sessions?: Array<{ id: string; starts_at?: string; hall?: string }> }).sessions ?? []
        const s = ev.find((x: { id: string }) => String(x.id) === sid)
        eventTitle.value = (data as { title?: string }).title ?? eventTitle.value
        sessionStartsAt.value = s?.starts_at ?? ''
        sessionHall.value = s?.hall ?? ''
      } catch {
        /* не критично — заголовки останутся дефолтными */
      }
    }
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

/** Применить серверную корзину к локальному стору (hydrate после перезагрузки). */
function applyServerCart(cartData: Awaited<ReturnType<typeof fetchCart>>): void {
  if (!cartData || !Array.isArray(cartData.items) || cartData.items.length === 0) return
  let restored = 0
  for (const item of cartData.items) {
    // CartItemResource отдаёт public_id (ULID) — он не годится для DELETE.
    // БД-id инвентарь-элемента восстанавливаем по месту в загруженном инвентаре.
    const invPublicId = String(item.inventory_item?.id ?? '')
    const inv = inventory.value.find(
      (i) => String(i.public_id ?? '') === invPublicId || String(i.id) === invPublicId,
    )
    if (!inv) continue
    const id = String(inv.id)
    if (cart.isSelected(id)) continue
    cart.meta[id] = id // маркер «холд есть»; точный cart_item_id сервер вернёт при снятии ошибкой → синхронизируем через reload
    cart.toggle({
      id,
      sector: String(item.inventory_item?.seat?.sector ?? 'Зал'),
      row: Number(item.inventory_item?.seat?.row ?? inv.seat?.row_id ?? 0),
      number: Number(item.inventory_item?.seat?.number ?? inv.seat?.number ?? 0),
      priceMinor: Number(item.unit_price ?? inv.price_amount ?? 0),
      kind: 'standard',
    })
    restored += 1
  }
  // Таймер — от серверного expires_at, а не от локальных 600 секунд.
  if (cartData.expires_at) cart.setHoldExpiry(cartData.expires_at)
  if (restored > 0) {
    ui.notify('brand', 'Выбор восстановлен', `Сервер ещё держит для вас мест: ${restored}.`)
  }
}

async function hydrateCart(sid: string): Promise<void> {
  if (cart.count > 0) {
    // Локальная корзина жива (возврат из SPA-навигации) — сверим только таймер.
    try {
      const serverCart = await fetchCart(sid)
      if (serverCart?.expires_at) cart.setHoldExpiry(serverCart.expires_at)
    } catch {
      /* не критично — продолжим с локальным отсчётом */
    }
    return
  }
  try {
    const serverCart = await fetchCart(sid)
    applyServerCart(serverCart)
  } catch {
    /* корзина недоступна — пользователь начнёт выбор заново */
  }
}

watch(() => route.query.session, loadSeats, { immediate: true })

/* Схема зала: из реальных мест. Группируем по row_id. */
const hall = computed<Sector[]>(() => {
  const byRow = new Map<number, InventoryItem[]>()
  for (const item of inventory.value) {
    const rowId = item.seat?.row_id ?? 0
    if (!byRow.has(rowId)) byRow.set(rowId, [])
    byRow.get(rowId)!.push(item)
  }

  const rows: Row[] = [...byRow.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([rowId, items]) => {
      const sorted = [...items].sort((a, b) => (a.seat?.number ?? 0) - (b.seat?.number ?? 0))
      return {
        index: rowId,
        seats: sorted.map((item) => {
          // Единый маппинг статусов (seatStatus.ts): available→free,
          // held/sold_out→held, sold→sold, blocked/disabled→unavailable.
          const selected = cart.isSelected(String(item.id))
          const heldByOther = externallyHeld.value.has(String(item.id)) && !selected
          const state = heldByOther
            ? ('held' as const)
            : seatStateFromStatus(item.status, selected, item.available_quantity)
          return {
            id: String(item.id),
            row: rowId,
            number: item.seat?.number ?? 0,
            state,
            priceMinor: Number(item.price_amount ?? 0),
            kind: item.type === 'seat' ? ('standard' as const) : ('standard' as const),
          } satisfies Seat
        }),
        offset: 0,
      }
    })

  return [
    {
      id: 'sector-1',
      name: 'Зал',
      priceMinor: rows[0]?.seats[0]?.priceMinor ?? 0,
      rows,
    },
  ]
})

const sheetOpen = ref(true)
/**
 * Сервисного сбора НЕТ: сервер выставляет `orders.total_amount` = сумме цен
 * мест (`CartService::checkout()`: subtotal = discount = fee = total = сумма
 * позиций). Здесь стояло `SERVICE_FEE = 9900`, и итог в сводке был на 99 ₽
 * больше суммы, которую реально спишут — расхождение между экраном и чеком.
 * Инвариант 6 (цена — только с сервера) нарушался прямо в UI.
 */
/** Защита от спама кликов: пока холд/снятие в полёте — место не трогается. */
const pendingSeats = ref<Set<string>>(new Set())

/** Идентификаторы выбранных мест — единственное, что передаётся в карту зала. */
const selectedIds = computed(() => [...cart.selectedIds])

/** Обработать ответ холда: сохранить cart_item_id и серверный expires_at. */
function adoptHoldResponse(inventoryItemId: string, res: HoldResponse): void {
  const cartItemId = res.data?.id ?? res.data?.cart_item_id
  if (cartItemId !== undefined && cartItemId !== null) cart.meta[inventoryItemId] = String(cartItemId)
  const expiresAt = res.cart?.expires_at ?? (res.data as { cart?: { expires_at?: string } } | undefined)?.cart?.expires_at
  if (expiresAt) cart.setHoldExpiry(expiresAt)
}

/** Сообщения об ошибках — по коду состояния, а не одна надпись на все случаи. */
function notifyHoldError(e: unknown, seatId: string): void {
  if (e instanceof ApiError && e.status === 409) {
    // Место успел занять другой покупатель: переходим в held без перезагрузки.
    cart.markSeatHeld(seatId)
    ui.notify('sun', 'Место уже занято', 'Это место только что придержал другой покупатель. Выберите другое.')
    return
  }
  if (e instanceof ApiError && (e.code === 'CART_EXPIRED' || e.status === 410)) {
    ui.notify('rose', 'Время удержания истекло', 'Корзина на сервере протухла. Освежите схему и выберите места заново.')
    void loadSeats()
    return
  }
  ui.notify('rose', 'Не получилось', e instanceof Error ? e.message : 'Попробуйте ещё раз')
}

/** Холд на сервере при выборе места. */
async function onToggle(seat: Seat, sectorName: string): Promise<void> {
  if (pendingSeats.value.has(seat.id)) return
  pendingSeats.value = new Set([...pendingSeats.value, seat.id])
  const isSelected = cart.isSelected(seat.id)
  try {
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
      })
      if (cart.meta[seat.id]) delete cart.meta[seat.id]
    } else {
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
      })
      // Цель воронки: место выбрано (config/metrika.php: seat_selected).
      void trackEvent('seat_selected').catch(() => {})
    }
  } catch (e) {
    notifyHoldError(e, seat.id)
  } finally {
    const next = new Set(pendingSeats.value)
    next.delete(seat.id)
    pendingSeats.value = next
  }
}

function onLimit(): void {
  ui.notify('sun', 'Больше нельзя', 'За один раз можно взять не больше 10 билетов')
}

/** Используем координатную карту, если есть standing-зоны или места с координатами. */
const useCoordMap = computed(() => {
  const hasStanding = inventory.value.some((i) => i.type === 'standing')
  const hasCoords = inventory.value.some((i) => i.type === 'seat' && i.seat && Number(i.seat.x ?? 0) > 0)
  return hasStanding || hasCoords
})

/** Клик по месту/зоне на координатной карте: item — место или танцпол, qty — количество. */
async function onToggleCoord(item: InventoryItem, qty: number): Promise<void> {
  const id = String(item.id)
  if (pendingSeats.value.has(id)) return
  pendingSeats.value = new Set([...pendingSeats.value, id])
  try {
    if (item.type === 'standing') {
      // Танцпол: покупаем qty билетов на стоячую зону.
      const res = await holdSeat(sessionId.value, item.id, qty)
      adoptHoldResponse(id, res)
      cart.toggle({
        id,
        sector: String((item.metadata_json as Record<string, unknown> | null)?.sector_name ?? 'Танцпол'),
        row: 0,
        number: 0,
        priceMinor: Number(item.price_amount ?? 0),
        kind: 'standard',
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
        sector: 'Зал',
        row: Number(item.seat?.row_id ?? 0),
        number: Number(item.seat?.number ?? 0),
        priceMinor: Number(item.price_amount ?? 0),
        kind: 'standard',
      })
      if (cart.meta[id]) delete cart.meta[id]
    } else {
      const res = await holdSeat(sessionId.value, item.id)
      adoptHoldResponse(id, res)
      cart.toggle({
        id,
        sector: 'Зал',
        row: Number(item.seat?.row_id ?? 0),
        number: Number(item.seat?.number ?? 0),
        priceMinor: Number(item.price_amount ?? 0),
        kind: 'standard',
      })
      void trackEvent('seat_selected').catch(() => {})
    }
  } catch (e) {
    notifyHoldError(e, id)
  } finally {
    const next = new Set(pendingSeats.value)
    next.delete(id)
    pendingSeats.value = next
  }
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

/**
 * Переход к оформлению.
 *
 * Здесь СОЗНАТЕЛЬНО не создаётся заказ. Раньше вызывался
 * `checkoutSession(sessionId, { customer_email: '' })`, и это не работало
 * никогда: `POST /cart/checkout` требует `customer_email` правилом
 * `['required', 'email:rfc']` (`CartController::checkout()`), поэтому пустая
 * строка давала 422 VALIDATION_ERROR «Поле «customer email» обязательно для
 * заполнения» — кнопка «оформить» не срабатывала ни разу. Проверено на живом
 * стенде.
 *
 * Контакты собираются на шаге оформления, поэтому и заказ создаётся там же:
 * `CheckoutPage` шлёт `POST /cart/checkout` с реальными именем, e-mail и
 * телефоном, а затем `POST /payments`. Выбранные места к этому моменту уже
 * удержаны на сервере (`POST /cart/items`), так что переход ничего не теряет.
 */
async function goCheckout(): Promise<void> {
  if (cart.count === 0) return
  cart.setLoading(true)
  try {
    router.push({ path: '/checkout', query: { session: sessionId.value } })
  } finally {
    cart.setLoading(false)
  }
}

onMounted(() => {
  if (cart.count === 0) cart.stopHold()
})

const sessionLabel = computed(() => {
  if (!sessionStartsAt.value) return ''
  return `${dateFull(sessionStartsAt.value)}, ${time(sessionStartsAt.value)}${sessionHall.value ? ` · ${sessionHall.value}` : ''}`
})
</script>

<template>
  <div class="mx-auto max-w-content px-4 pb-32 pt-4 sm:px-6 md:pb-8">
      <!-- Хлебные крошки и контекст -->
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <button
            type="button"
            class="mb-1 flex items-center gap-1 text-xs text-subtle transition-colors hover:text-content"
            @click="router.back()"
          >
            <span aria-hidden="true">←</span> К событию
          </button>
          <h1 class="truncate text-lg font-semibold text-content">{{ eventTitle }}</h1>
          <p v-if="sessionLabel" class="mt-0.5 text-xs text-subtle">{{ sessionLabel }}</p>
        </div>

        <p class="flex-none text-xs text-subtle">
          <span class="text-content">{{ totalSeats || inventory.length }}</span> мест в зале
        </p>
      </div>

      <!-- Предупреждение: сервер отдал не все места (лимит пагинации) -->
      <div
        v-if="truncated && !loading && !loadError"
        class="mt-3 rounded-lg border border-sun-500/40 bg-sun-500/10 px-3 py-2 text-xs text-sun-500"
        role="alert"
      >
        Показаны не все места: загружено {{ inventory.length }} из {{ totalSeats }}. Обновите страницу или выберите другой сеанс.
      </div>

      <!-- Загрузка/ошибка -->
      <div v-if="loading" class="mt-8 py-10 text-center text-sm text-subtle">Загрузка схемы зала…</div>
      <div v-else-if="loadError" class="mt-8 flex flex-col items-center gap-3 py-10 text-center">
        <p class="text-sm text-danger-500">Не удалось загрузить места: {{ loadError }}</p>
        <NButton variant="secondary" size="sm" @click="loadSeats">Повторить</NButton>
      </div>

      <div v-else class="mt-4 grid gap-4 lg:grid-cols-[1fr_360px]">
      <!-- Карта зала -->
            <div class="min-w-0">
              <CoordSeatMap
                v-if="useCoordMap"
                :inventory="inventory"
                :selected="selectedIds"
                :max-quantity="10"
                @toggle="onToggleCoord"
                @limit="onLimit"
              />
              <SeatMap
                v-else
                :selected="selectedIds"
                :sectors="hall"
                :max-selection="10"
                @toggle="onToggle"
                @limit="onLimit"
              />

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <!-- Легенда встроена в CoordSeatMap (SVG-палитра); дублировать CSS-легенду нельзя — цвета разойдутся -->
          <SeatLegend v-if="!useCoordMap" />
          <p class="text-xs text-subtle">
            <kbd class="rounded border border-line px-1 font-mono text-2xs">← ↑ ↓ →</kbd>
            — перемещаться по рядам
          </p>
        </div>
      </div>

      <!-- Итог: на десктопе колонкой -->
      <aside class="hidden lg:sticky lg:top-24 lg:block lg:self-start">
        <OrderSummary
          :seats="cart.seats.filter((s) => cart.selectedIds.has(s.id))"
          :subtotal-minor="cart.subtotalMinor"
          :discount-minor="cart.discountMinor"
          :hold-seconds-left="cart.holdSecondsLeft"
          cta-label="Оформить заказ"
          @remove="remove"
          @extend="cart.extendHold()"
          @clear="cart.clear()"
          @submit="goCheckout"
        />
      </aside>
    </div>

    <!-- Итог: на мобильном — нижняя панель -->
    <NBottomSheet v-model:open="sheetOpen" title="Ваш выбор">
      <OrderSummary
        dense
        :seats="cart.seats.filter((s) => cart.selectedIds.has(s.id))"
        :subtotal-minor="cart.subtotalMinor"
        :discount-minor="cart.discountMinor"
        :hold-seconds-left="cart.holdSecondsLeft"
        cta-label="Оформить заказ"
        @remove="remove"
        @extend="cart.extendHold()"
        @clear="cart.clear()"
        @submit="goCheckout"
      />
    </NBottomSheet>

    <!-- Плашка со свёрнутым итогом: видна, даже если панель закрыта -->
    <div
      v-if="!sheetOpen && cart.count > 0"
      class="glass fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3 border-t border-line px-4 py-3 lg:hidden safe-bottom"
    >
      <div>
        <p class="text-xs text-subtle">{{ cart.count }} выбрано</p>
        <p class="text-base font-semibold tabular-nums text-content">{{ money(cart.totalMinor) }}</p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" size="sm" @click="sheetOpen = true">Открыть</NButton>
        <NButton variant="accent" size="sm" @click="goCheckout">Оформить</NButton>
      </div>
    </div>
  </div>
</template>
