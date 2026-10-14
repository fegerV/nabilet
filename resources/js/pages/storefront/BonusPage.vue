<script setup lang="ts">
/**
 * «Бонусы» — баланс бонусного счёта и история движений.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ЭКРАН, А НЕ РАЗДЕЛ КАБИНЕТА
 *   Как и «Избранное», это экран для просмотра, а не для управления аккаунтом:
 *   покупатель приходит сюда посмотреть, «сколько у меня накопилось и за что».
 *   В кабинете он был бы ещё одним блоком под профилем и паролем — то есть
 *   ровно там, куда за балансом не заходят.
 *
 * БАЛАНС БЕРЁТСЯ ИЗ СТОРА, А ИСТОРИЯ — ОТСЮДА
 *   Баланс показывает не только эта страница (чип в шапке, плитка в кабинете),
 *   поэтому он лежит в `stores/loyalty.ts` и грузится один раз. История же
 *   нужна ровно здесь и пагинируется, поэтому её состояние локально: держать
 *   в сторе список, который никто больше не читает, — это лишний способ
 *   показать устаревшую страницу после возврата на экран.
 *
 * ЧЕГО ЭТОТ ЭКРАН НЕ ОБЕЩАЕТ
 *   Фаза 2a начисляет бонусы и показывает счёт, но ещё НЕ списывает их в
 *   корзине (это фаза 2b). Поэтому подпись говорит «оплата бонусами появится
 *   позже», а не «списывайте при оформлении»: второе было бы обещанием кнопки,
 *   которой в корзине нет.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { ApiError } from '@/lib/api'
import { dateTime, money, plural } from '@/lib/format'
import {
  bonusReasonLabel,
  fetchBonusTransactions,
  type BonusTransaction,
} from '@/lib/loyalty'
import { useLoyaltyStore } from '@/stores/loyalty'

const loyalty = useLoyaltyStore()

const rows = ref<BonusTransaction[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const loadingMore = ref(false)
const error = ref<string | null>(null)

const hasMore = computed(() => page.value < lastPage.value)

onMounted(() => {
  // Баланс: идемпотентно — оболочка витрины уже грузит его при навигации.
  void loyalty.load()
  void loadFirst()
})

async function loadFirst(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const history = await fetchBonusTransactions({ perPage: 25 })
    rows.value = history.items
    page.value = history.page
    lastPage.value = history.lastPage
    total.value = history.total
  } catch (e) {
    // Список очищаем: показывать половину истории рядом с ошибкой — значит
    // выдавать неполный журнал за полный.
    rows.value = []
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить историю.'
  } finally {
    loading.value = false
  }
}

/**
 * Догрузить следующую страницу.
 *
 * Строки ДОБАВЛЯЮТСЯ, а не заменяются: это продолжение того же списка.
 * Ошибка уходит тостом и НЕ стирает уже показанное — иначе неудачная догрузка
 * выглядела бы как «история исчезла».
 */
async function loadMore(): Promise<void> {
  if (loadingMore.value || !hasMore.value) return

  loadingMore.value = true
  try {
    const history = await fetchBonusTransactions({ page: page.value + 1, perPage: 25 })
    rows.value = [...rows.value, ...history.items]
    page.value = history.page
    lastPage.value = history.lastPage
    total.value = history.total
  } catch {
    /* показываем уже загруженное; кнопка остаётся — повтор возможен */
  } finally {
    loadingMore.value = false
  }
}

/** Знаковая сумма: плюс — начислили, минус — списали, вернули или сожгли. */
function signedDelta(delta: number): string {
  return delta < 0 ? `−${money(-delta)}` : `+${money(delta)}`
}

/** `orders.public_id` — ULID; в строке показываем начало, целиком — в title. */
function shortId(id: string): string {
  return id.length > 8 ? `${id.slice(0, 8)}…` : id
}
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Бонусы</h1>
    <p class="mt-1.5 text-sm text-muted">
      Кэшбэк за покупки: 5 % от суммы каждого оплаченного заказа возвращается бонусами.
    </p>

    <!-- Баланс -->
    <section class="surface-card mt-6 p-5 sm:p-6">
      <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">На счёте</h2>

      <p class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span class="font-display text-4xl font-bold tabular-nums text-content">{{ loyalty.bonusesCount }}</span>
        <span class="text-sm text-muted">
          {{ plural(loyalty.bonusesCount, 'бонус', 'бонуса', 'бонусов') }}
        </span>
        <span class="text-sm text-subtle">· {{ money(loyalty.bonus?.balance ?? 0) }} скидки</span>
      </p>

      <p class="mt-3 text-xs text-subtle">
        Курс — 1 бонус = 1 ₽. Бонусы действуют 12 месяцев с даты начисления.
        Оплата бонусами в корзине появится в следующем обновлении.
      </p>
    </section>

    <!-- История -->
    <section class="mt-8">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-subtle">История начислений</h2>
        <span v-if="total > 0" class="text-xs text-subtle">
          {{ total }} {{ plural(total, 'движение', 'движения', 'движений') }}
        </span>
      </div>

      <p v-if="loading" class="mt-4 text-sm text-subtle">Загружаем историю…</p>

      <p
        v-else-if="error"
        class="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400"
      >
        <span class="min-w-0">{{ error }}</span>
        <NButton variant="ghost" size="sm" @click="loadFirst">Повторить</NButton>
      </p>

      <NEmptyState
        v-else-if="rows.length === 0"
        class="mt-4"
        icon="✦"
        title="Движений пока нет"
        description="Бонусы появятся здесь после первого оплаченного заказа."
      />

      <template v-else>
        <ul class="surface-card mt-4 divide-y divide-line">
          <li
            v-for="row in rows"
            :key="row.id"
            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
          >
            <div class="min-w-0">
              <p class="truncate text-sm text-content">{{ bonusReasonLabel(row.reason) }}</p>
              <p class="mt-0.5 text-xs text-subtle">
                {{ dateTime(row.createdAt ?? '') }}
                <template v-if="row.orderId">
                  · <span :title="row.orderId">заказ {{ shortId(row.orderId) }}</span>
                </template>
              </p>
            </div>
            <span
              class="flex-none text-sm font-semibold tabular-nums"
              :class="row.delta < 0 ? 'text-rose-400' : 'text-mint-400'"
            >{{ signedDelta(row.delta) }}</span>
          </li>
        </ul>

        <NButton
          v-if="hasMore"
          variant="secondary"
          block
          class="mt-4"
          :loading="loadingMore"
          @click="loadMore"
        >Показать ещё</NButton>
      </template>
    </section>
  </div>
</template>
