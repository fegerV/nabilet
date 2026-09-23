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
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SeatMap from '@/components/seat/SeatMap.vue'
import SeatLegend from '@/components/seat/SeatLegend.vue'
import OrderSummary from '@/components/seat/OrderSummary.vue'
import NBottomSheet from '@/components/ui/NBottomSheet.vue'
import NButton from '@/components/ui/NButton.vue'
import { useCartStore, type CartSeat } from '@/stores/cart'
import { useUiStore } from '@/stores/ui'
import { buildHall, type Seat, type Sector } from '@/lib/hall'
import { EVENTS } from '@/lib/mock'
import { dateFull, time, money } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const ui = useUiStore()

const event = computed(() => EVENTS.find((e) => e.id === route.params.id) ?? EVENTS[0])
const session = computed(
  () => event.value.sessions.find((s) => s.id === route.query.session) ?? event.value.sessions[0],
)

const hall = computed<Sector[]>(() => buildHall())
const sheetOpen = ref(true)
const SERVICE_FEE = 9900

/** Идентификаторы выбранных мест — единственное, что передаётся в карту зала. */
const selectedIds = computed(() => [...cart.selectedIds])

function onToggle(seat: Seat, sectorName: string): void {
  cart.toggle({
    id: seat.id,
    sector: sectorName,
    row: seat.row,
    number: seat.number,
    priceMinor: seat.priceMinor,
    kind: seat.kind,
  })
}

function onLimit(): void {
  ui.notify('sun', 'Больше нельзя', 'За один раз можно взять не больше 10 билетов')
}

function remove(id: string): void {
  const seat = cart.seats.find((s: CartSeat) => s.id === id)
  if (seat) cart.toggle(seat)
}

function goCheckout(): void {
  if (cart.count === 0) return
  router.push('/checkout')
}

onMounted(() => {
  // Пришли без выбранных мест — начинаем «с чистого листа», но не сбрасываем
  // выбор, если пользователь вернулся назад из корзины.
  if (cart.count === 0) cart.stopHold()
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
        <h1 class="truncate text-lg font-semibold text-content">{{ event.title }}</h1>
        <p v-if="session" class="mt-0.5 text-xs text-subtle">
          {{ dateFull(session.startsAt) }}, {{ time(session.startsAt) }} · {{ session.hall }}
        </p>
      </div>

      <p class="flex-none text-xs text-subtle">
        <span class="text-content">{{ session?.availableSeats ?? 0 }}</span> мест свободно
      </p>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_360px]">
      <!-- Карта зала -->
      <div class="min-w-0">
        <SeatMap
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
