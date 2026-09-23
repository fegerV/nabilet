<script setup lang="ts">
/**
 * Карточка события.
 *
 * Ключевой экран пути: здесь пользователь выбирает не «билет», а конкретный
 * сеанс. Поэтому сеансы — не список текста, а крупные кнопки с датой, временем
 * и остатком мест: «куда я иду и во сколько» должно считываться за секунду.
 * Кнопка покупки прилипает к низу экрана — на мобильном её не нужно искать.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { EVENTS } from '@/lib/mock'
import { money, dateFull, time, seatsLabel } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { SeatState } from '@/lib/types'
import { SEAT_LEGEND } from '@/lib/hall'

const route = useRoute()
const router = useRouter()

const event = computed(() => EVENTS.find((e) => e.id === route.params.id) ?? EVENTS[0])
const activeSessionId = ref(event.value.sessions[0]?.id ?? '')
const activeSession = computed(
  () => event.value.sessions.find((s) => s.id === activeSessionId.value) ?? event.value.sessions[0],
)

const soldOut = computed(() => (activeSession.value?.availableSeats ?? 0) === 0)
const fewLeft = computed(() => {
  const left = activeSession.value?.availableSeats ?? 0
  return left > 0 && left <= 50
})

/* Предпросмотр зала: не интерактивная копия карты, а намёк на неё.
   Пользователь должен понять, что выбирать придётся на схеме. */
const previewRows = Array.from({ length: 7 }, (_, r) =>
  Array.from({ length: 20 }, (_, n) => {
    const roll = (r * 31 + n * 17) % 10
    const state: SeatState = roll < 3 ? 'sold' : roll === 3 ? 'held' : 'free'
    return { key: `${r}-${n}`, state }
  }),
)

const PREVIEW_STATE: Record<SeatState, string> = {
  free: 'seat',
  selected: 'seat seat--selected',
  held: 'seat seat--held',
  sold: 'seat seat--sold',
  unavailable: 'seat seat--unavailable',
  vip: 'seat seat--vip',
  accessible: 'seat seat--accessible',
}

const legend = SEAT_LEGEND.filter((l) => ['free', 'sold', 'held'].includes(l.state))

function buy(): void {
  if (soldOut.value || !activeSession.value) return
  router.push({ path: `/event/${event.value.id}/seats`, query: { session: activeSession.value.id } })
}
</script>

<template>
  <div class="pb-28 md:pb-12">
    <!-- Постер -->
    <div
      class="relative h-56 overflow-hidden sm:h-72"
      :style="{ background: `linear-gradient(145deg, ${event.posterFrom} 0%, ${event.posterTo} 100%)` }"
    >
      <span
        class="pointer-events-none absolute -left-10 top-10 h-56 w-56 rounded-full opacity-40 blur-3xl"
        :style="{ background: event.posterAccent }"
        aria-hidden="true"
      />
      <div class="absolute inset-0 bg-gradient-to-t from-canvas via-canvas/20 to-transparent" />
    </div>

    <div class="mx-auto -mt-20 max-w-content px-4 sm:px-6">
      <div class="flex flex-wrap items-center gap-2">
        <NBadge tone="brand">{{ event.category }}</NBadge>
        <NStatusBadge kind="event" :status="event.status" size="md" />
      </div>

      <h1 class="mt-3 max-w-3xl text-balance text-3xl font-bold leading-tight tracking-tight text-content sm:text-4xl">
        {{ event.title }}
      </h1>
      <p class="mt-2 text-base text-muted">{{ event.subtitle }}</p>

      <dl class="mt-5 grid gap-4 sm:grid-cols-3">
        <div class="flex items-start gap-2.5">
          <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">◷</span>
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Начало</dt>
            <dd v-if="activeSession" class="text-sm text-content">
              {{ dateFull(activeSession.startsAt) }}, {{ time(activeSession.startsAt) }}
            </dd>
          </div>
        </div>
        <div class="flex items-start gap-2.5">
          <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">⌖</span>
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Площадка</dt>
            <dd class="text-sm text-content">{{ event.venue }}, {{ event.city }}</dd>
          </div>
        </div>
        <div class="flex items-start gap-2.5">
          <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">◈</span>
          <div>
            <dt class="text-2xs uppercase tracking-wide text-subtle">Билеты от</dt>
            <dd class="text-sm font-semibold tabular-nums text-content">{{ money(event.priceFromMinor) }}</dd>
          </div>
        </div>
      </dl>

      <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_360px]">
        <!-- Описание -->
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-content">О событии</h2>
          <p class="mt-2 max-w-prose text-pretty text-base leading-relaxed text-muted">
            {{ event.title }} — {{ event.subtitle }}. Продолжительность сеанса зависит от программы;
            вход на площадку открывается за час до начала. Билеты с местами на схеме зала:
            ряд и место вы выбираете сами, а не получаете «лучшее из свободных».
          </p>

          <h2 class="mt-8 text-lg font-semibold text-content">Схема зала</h2>
          <p class="mt-1 text-sm text-muted">{{ activeSession?.hall }}</p>

          <div class="surface-card mt-3 p-4">
            <div class="stage-bar mx-auto mb-5 h-8 w-52 rounded-b-xl rounded-t-sm text-center text-2xs font-semibold uppercase tracking-[0.2em] leading-8 text-white">
              Сцена
            </div>
            <div class="flex flex-col items-center gap-1.5">
              <div v-for="(row, r) in previewRows" :key="r" class="flex gap-1.5">
                <span
                  v-for="seat in row"
                  :key="seat.key"
                  :class="PREVIEW_STATE[seat.state]"
                  class="h-3.5 w-3.5 !rounded-[3px] hover:transform-none"
                  aria-hidden="true"
                />
              </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-3 border-t border-line pt-3 text-2xs text-subtle">
              <span v-for="item in legend" :key="item.state" class="flex items-center gap-1.5">
                <span :class="PREVIEW_STATE[item.state]" class="h-3 w-3 !rounded-[3px] hover:transform-none" aria-hidden="true" />
                {{ item.label }}
              </span>
            </div>
          </div>
        </div>

        <!-- Выбор сеанса -->
        <aside class="lg:sticky lg:top-24 lg:self-start">
          <div class="surface-card overflow-hidden">
            <div class="border-b border-line px-4 py-3">
              <h2 class="text-sm font-semibold text-content">Сеансы</h2>
              <p class="mt-0.5 text-xs text-subtle">Выберите дату и время</p>
            </div>

            <div class="space-y-2 p-3">
              <button
                v-for="session in event.sessions"
                :key="session.id"
                type="button"
                :disabled="session.availableSeats === 0"
                :class="
                  cn(
                    'w-full rounded-lg border p-3 text-left transition-all duration-120',
                    session.id === activeSessionId
                      ? 'border-brand-500 bg-brand-500/10'
                      : 'border-line hover:border-brand-500/40 hover:bg-surface-2',
                    session.availableSeats === 0 && 'cursor-not-allowed opacity-45',
                  )
                "
                @click="activeSessionId = session.id"
              >
                <div class="flex items-baseline justify-between gap-2">
                  <p class="text-sm font-medium text-content">
                    {{ dateFull(session.startsAt) }}, {{ time(session.startsAt) }}
                  </p>
                  <span
                    :class="
                      cn(
                        'flex-none text-xs',
                        session.availableSeats === 0
                          ? 'text-subtle'
                          : session.availableSeats <= 50
                            ? 'text-accent-400'
                            : 'text-mint-400',
                      )
                    "
                  >
                    {{ session.availableSeats === 0 ? 'нет мест' : seatsLabel(session.availableSeats) }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs text-subtle">{{ session.hall }}</p>
              </button>
            </div>
          </div>

        </aside>
      </div>
    </div>

    <!-- Прилипающая кнопка покупки -->
    <div class="glass fixed inset-x-0 bottom-0 z-30 border-t border-line px-4 py-3 md:hidden safe-bottom">
      <button
        type="button"
        :disabled="soldOut"
        :class="
          cn(
            'w-full rounded-lg py-3.5 text-base font-semibold transition-colors',
            soldOut ? 'cursor-not-allowed bg-surface-3 text-subtle' : 'bg-accent-gradient text-white shadow-accent',
          )
        "
        @click="buy"
      >
        {{ soldOut ? 'Билетов нет' : 'Выбрать места' }}
      </button>
    </div>

    <div class="mx-auto mt-8 hidden max-w-content px-6 md:block">
      <button
        type="button"
        :disabled="soldOut"
        :class="
          cn(
            'rounded-lg px-8 py-3.5 text-base font-semibold transition-colors',
            soldOut ? 'cursor-not-allowed bg-surface-3 text-subtle' : 'bg-accent-gradient text-white shadow-accent',
          )
        "
        @click="buy"
      >
        {{ soldOut ? 'Билетов нет' : 'Выбрать места' }}
      </button>
      <p v-if="fewLeft" class="mt-2 text-xs text-accent-400">
        Осталось мало мест — лучше не откладывать
      </p>
    </div>
  </div>
</template>
