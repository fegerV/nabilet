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
 *
 * РАЗДЕЛЕНИЕ (P2). Файл был 706 строк и совмещал три обязанности. Логика
 * вынесена в два композабла, а страница осталась тонким оркестратором:
 *  - `useSeatInventory` — загрузка инвентаря, контекст события, раскладка зала;
 *  - `useSeatHolds` — холды, восстановление после F5, сообщения об ошибках.
 * Здесь остаётся только то, что действительно про этот экран: разметка,
 * нижняя панель и переход к оформлению.
 */
import { computed, ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import SeatMap from '@/components/seat/SeatMap.vue'
import CoordSeatMap from '@/components/seat/CoordSeatMap.vue'
import SeatLegend from '@/components/seat/SeatLegend.vue'
import OrderSummary from '@/components/seat/OrderSummary.vue'
import NBottomSheet from '@/components/ui/NBottomSheet.vue'
import NButton from '@/components/ui/NButton.vue'
import { useCartStore } from '@/stores/cart'
import { money } from '@/lib/format'
import { useSeatInventory } from './useSeatInventory'
import { useSeatHolds } from './useSeatHolds'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()

const sessionId = computed(() => String(route.query.session ?? ''))

const {
  inventory,
  loading,
  loadError,
  truncated,
  totalSeats,
  maxTickets,
  eventTitle,
  sessionLabel,
  hall,
  useCoordMap,
  fetchSeats,
  loadEventContext,
} = useSeatInventory()

/** Идентификаторы выбранных мест — единственное, что передаётся в карту зала. */
const selectedIds = computed(() => [...cart.selectedIds])

const { hydrateCart, onToggle, onToggleCoord, onLimit, remove } = useSeatHolds({
  sessionId,
  inventory,
  maxTickets,
  // `loadSeats` объявлена ниже — ссылка ленивая, вызов происходит позже.
  reload: () => loadSeats(),
})

/**
 * Единственная точка загрузки: инвентарь → восстановление корзины → контекст
 * события. Порядок существенен: `hydrateCart` сопоставляет позиции серверной
 * корзины с уже загруженным инвентарём, поэтому обязан идти после `fetchSeats`.
 */
async function loadSeats(): Promise<void> {
  const sid = sessionId.value
  cart.setSession(sid || null)

  if (!sid) {
    loadError.value = 'Сеанс не указан'
    loading.value = false
    return
  }

  loading.value = true
  loadError.value = null

  try {
    await fetchSeats(sid)
    await hydrateCart(sid)
    await loadEventContext(sid)
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

watch(() => route.query.session, loadSeats, { immediate: true })

const sheetOpen = ref(true)

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
                :selected-count="cart.ticketsCount"
                :max-quantity="maxTickets"
                @toggle="onToggleCoord"
                @limit="onLimit"
              />
              <SeatMap
                v-else
                :selected="selectedIds"
                :sectors="hall"
                :max-selection="maxTickets"
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

      <p v-if="cart.holdExtendError" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400" role="alert">
        {{ cart.holdExtendError }}
      </p>

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
        <p class="text-xs text-subtle">{{ cart.ticketsCount }} выбрано</p>
        <p class="text-base font-semibold tabular-nums text-content">{{ money(cart.totalMinor) }}</p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" size="sm" @click="sheetOpen = true">Открыть</NButton>
        <NButton variant="accent" size="sm" @click="goCheckout">Оформить</NButton>
      </div>
    </div>
  </div>
</template>
