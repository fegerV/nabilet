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
import { fetchInventory, holdSeat, releaseSeat, checkoutSession, type InventoryItem } from '@/lib/inventory'
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
    inventory.value = await fetchInventory(sid)
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
          const state =
            item.status === 'available'
              ? 'free'
              : item.status === 'sold'
                ? 'sold'
                : item.status === 'held'
                  ? 'held'
                  : 'unavailable'
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
const SERVICE_FEE = 9900

/** Идентификаторы выбранных мест — единственное, что передаётся в карту зала. */
const selectedIds = computed(() => [...cart.selectedIds])

/** Холд на сервере при выборе места. */
async function onToggle(seat: Seat, sectorName: string): Promise<void> {
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
      cart.meta[seat.id] = String(res.data?.id ?? seat.id)
      cart.toggle({
        id: seat.id,
        sector: sectorName,
        row: seat.row,
        number: seat.number,
        priceMinor: seat.priceMinor,
        kind: seat.kind,
      })
    }
  } catch (e) {
    ui.notify('rose', 'Не получилось', e instanceof Error ? e.message : 'Попробуйте ещё раз')
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
async function onToggleCoord(item: import('@/lib/inventory').InventoryItem, qty: number): Promise<void> {
  const id = String(item.id)
  try {
    if (item.type === 'standing') {
      // Танцпол: покупаем qty билетов на стоячую зону.
      const res = await holdSeat(sessionId.value, item.id, qty)
      cart.meta[id] = String(res.data?.id ?? item.id)
      cart.toggle({
        id,
        sector: String((item.metadata_json as Record<string, unknown> | null)?.sector_name ?? 'Танцпол'),
        row: 0,
        number: 0,
        priceMinor: Number(item.price_amount ?? 0),
        kind: 'standard',
      })
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
      cart.meta[id] = String(res.data?.id ?? item.id)
      cart.toggle({
        id,
        sector: 'Зал',
        row: Number(item.seat?.row_id ?? 0),
        number: Number(item.seat?.number ?? 0),
        priceMinor: Number(item.price_amount ?? 0),
        kind: 'standard',
      })
    }
  } catch (e) {
    ui.notify('rose', 'Не получилось', e instanceof Error ? e.message : 'Попробуйте ещё раз')
  }
}

function remove(id: string): void {
  const seat = cart.seats.find((s: CartSeat) => s.id === id)
  if (seat) {
    cart.toggle(seat)
    const cartItem = cart.meta[id]
    if (cartItem) {
      releaseSeat(sessionId.value, cartItem).catch(() => {})
      delete cart.meta[id]
    }
  }
}

async function goCheckout(): Promise<void> {
  if (cart.count === 0) return
  cart.setLoading(true)
  try {
    await checkoutSession(sessionId.value)
    router.push('/checkout')
  } catch (e) {
    ui.notify('rose', 'Оформление не прошло', e instanceof Error ? e.message : 'Попробуйте ещё раз')
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
          <span class="text-content">{{ inventory.length }}</span> мест в зале
        </p>
      </div>

      <!-- Загрузка/ошибка -->
      <div v-if="loading" class="mt-8 py-10 text-center text-sm text-subtle">Загрузка схемы зала…</div>
      <div v-else-if="loadError" class="mt-8 py-10 text-center text-sm text-danger-500">
        Не удалось загрузить места: {{ loadError }}
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
          <SeatLegend />
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
          :fee-minor="SERVICE_FEE"
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
        :fee-minor="SERVICE_FEE"
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
        <p class="text-base font-semibold tabular-nums text-content">{{ money(cart.totalMinor + SERVICE_FEE) }}</p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" size="sm" @click="sheetOpen = true">Открыть</NButton>
        <NButton variant="accent" size="sm" @click="goCheckout">Оформить</NButton>
      </div>
    </div>
  </div>
</template>
