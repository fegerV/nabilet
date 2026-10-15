<script setup lang="ts">
/**
 * Результаты розыгрыша — публичная страница «кто выиграл» (фаза 5).
 *
 * ПОЧЕМУ ПУБЛИЧНО И БЕЗ АККАУНТА
 *   «Смотрите, кто выиграл» — часть механики: розыгрыш существует, только
 *   если его видят. Поэтому маршрут объявлен БЕЗ `requiresCustomer`, а
 *   `GET /raffles/{public_id}` отдаёт данные без токена. Страницу открывают
 *   из письма победителю, из письма рассылки и просто из клуба.
 *
 * МАСКА ВМЕСТО ИМЕНИ
 *   Сервер намеренно не присылает ни полное имя, ни полный адрес победителя:
 *   витрина показывает ровно то, что получила (`display` — «Иван П.» или
 *   «i***@mail.ru»). Додумывать масску на клиенте нельзя и не надо: полные
 *   координаты есть у организатора в админке, и их утечка в открытый доступ
 *   делала бы страницу страницей утечки ПДн.
 *
 * ЧТО ПОКАЗЫВАЕТСЯ ДО ТИРАЖА
 *   Призы видны сразу с масками-заглушками: состав призовых мест — не
 *   секрета, и покупатель решает участвовать, зная, что разыгрывается. Сам
 *   победитель появляется только после тиража.
 */
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NButton from '@/components/ui/NButton.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { ApiError } from '@/lib/api'
import { dateTime, plural } from '@/lib/format'
import { RAFFLE_STATUS_LABELS, fetchRaffleResults, raffleStatusClass, type RaffleResults } from '@/lib/raffles'

const route = useRoute()

const publicId = String(route.params.publicId ?? '')
const raffle = ref<RaffleResults | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref<string | null>(null)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  notFound.value = false
  error.value = null
  try {
    raffle.value = await fetchRaffleResults(publicId)
    document.title = raffle.value.title ? `Итоги розыгрыша «${raffle.value.title}» — NABILET` : 'Итоги розыгрыша — NABILET'
  } catch (e) {
    // 404 для гостя — не сбой: ссылка на черновик, отменённый или
    // несуществующий розыгрыш. Показываем «не найден», а не ошибку.
    if (e instanceof ApiError && e.status === 404) {
      notFound.value = true
    } else {
      error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить результаты.'
    }
  } finally {
    loading.value = false
  }
}

function windowLabel(): string {
  const from = raffle.value?.starts_at ? dateTime(raffle.value.starts_at) : 'с начала'
  const to = raffle.value?.ends_at ? dateTime(raffle.value.ends_at) : 'до тиража'
  return `${from} — ${to}`
}
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <!-- Загрузка -->
    <p v-if="loading" class="py-16 text-center text-sm text-subtle">Загружаем результаты…</p>

    <!-- Ошибка -->
    <div v-else-if="error" class="surface-card border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      <p>{{ error }}</p>
      <NButton variant="ghost" size="sm" class="mt-2" @click="load">Повторить</NButton>
    </div>

    <!-- Розыгрыша нет (или он ещё не виден гостям) -->
    <NEmptyState
      v-else-if="notFound"
      icon="🎟"
      title="Розыгрыш не найден"
      description="Возможно, тираж ещё не проведён или розыгрыш отменён — итоги появятся здесь позже."
    />

    <!-- Результаты -->
    <template v-else-if="raffle">
      <header>
        <p class="text-2xs font-semibold uppercase tracking-[0.18em] text-subtle">Розыгрыш</p>
        <div class="mt-1 flex flex-wrap items-center gap-3">
          <h1 class="font-display text-2xl font-bold tracking-tight text-content">{{ raffle.title }}</h1>
          <NBadge :class="raffleStatusClass(raffle.status)">{{ RAFFLE_STATUS_LABELS[raffle.status] }}</NBadge>
        </div>
        <p v-if="raffle.description" class="mt-2 text-sm text-muted">{{ raffle.description }}</p>
      </header>

      <!-- Сводка: сколько билетов, окно, дата тиража -->
      <section class="surface-card mt-6 grid gap-4 p-5 sm:grid-cols-3">
        <div>
          <p class="text-2xs font-semibold uppercase tracking-wide text-subtle">Билетов в розыгрыше</p>
          <p class="mt-1 text-lg font-bold tabular-nums text-content">
            {{ raffle.entries_count }} {{ plural(raffle.entries_count, 'билет', 'билета', 'билетов') }}
          </p>
        </div>
        <div>
          <p class="text-2xs font-semibold uppercase tracking-wide text-subtle">Окно</p>
          <p class="mt-1 text-sm text-content">{{ windowLabel() }}</p>
        </div>
        <div>
          <p class="text-2xs font-semibold uppercase tracking-wide text-subtle">Тираж</p>
          <p class="mt-1 text-sm text-content">
            {{ raffle.drawn_at ? dateTime(raffle.drawn_at) : 'ещё не проведён' }}
          </p>
        </div>
      </section>

      <!-- Призовые места и победители -->
      <section class="mt-8">
        <h2 class="font-display text-xl font-bold tracking-tight text-content">Призовые места</h2>

        <ul class="mt-4 space-y-3">
          <li
            v-for="prize in raffle.prizes"
            :key="prize.place"
            class="surface-card flex flex-wrap items-center gap-3 p-4"
          >
            <span
              class="grid h-8 w-8 flex-none place-items-center rounded-full bg-brand-500/15 text-sm font-bold text-brand-300"
              aria-hidden="true"
            >{{ prize.place }}</span>

            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-content">{{ prize.title }}</p>
              <p v-if="prize.description" class="mt-0.5 text-xs text-subtle">{{ prize.description }}</p>
            </div>

            <span v-if="prize.winner" class="flex items-center gap-2 text-sm">
              <span class="text-muted">победитель</span>
              <span class="font-medium text-content">{{ prize.winner.display }}</span>
            </span>
            <span v-else class="text-xs text-subtle">
              {{ raffle.drawn_at ? 'билет не выиграл' : 'ждёт тиража' }}
            </span>
          </li>
        </ul>
      </section>

      <p class="mt-6 text-xs text-subtle">
        Каждый оплаченный заказ из окна розыгрыша становился билетом. Имена победителей показаны
        сокращённо — полные данные организатор видит в админке.
      </p>
    </template>
  </div>
</template>
