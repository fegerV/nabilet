<script setup lang="ts">
/**
 * Карточка события.
 *
 * Постер организован так, чтобы карточка читалась даже без изображения:
 * градиент, крупный заголовок, цена и статус. Когда организатор загрузит
 * постер, он ложится под тот же текст — вёрстка от этого не ломается.
 */
import { computed } from 'vue'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { money, dateFull, time } from '@/lib/format'
import type { EventCard as EventCardType } from '@/lib/types'

const props = defineProps<{ event: EventCardType }>()

const posterStyle = computed(() => ({
  background: `linear-gradient(145deg, ${props.event.posterFrom} 0%, ${props.event.posterTo} 100%)`,
}))

const nextSession = computed(() => props.event.sessions[0])
const soldOut = computed(() => props.event.status === 'sold_out')
</script>

<template>
  <article
    class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-surface transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-brand-500/40 hover:shadow-lg"
  >
    <!-- Постер -->
    <div class="relative aspect-[16/10] overflow-hidden" :style="posterStyle">
      <span
        class="pointer-events-none absolute -right-6 -top-8 h-32 w-32 rounded-full opacity-40 blur-2xl"
        :style="{ background: event.posterAccent }"
        aria-hidden="true"
      />

      <div class="absolute inset-x-0 top-0 flex items-start justify-between p-3">
        <NBadge tone="brand" class="backdrop-blur">{{ event.category }}</NBadge>
        <NStatusBadge kind="event" :status="event.status" />
      </div>

      <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-overlay/85 to-transparent p-3 pt-10">
        <h3 class="text-balance text-base font-semibold leading-snug text-white">{{ event.title }}</h3>
        <p class="mt-0.5 truncate text-xs text-white/70">{{ event.subtitle }}</p>
      </div>
    </div>

    <!-- Метаданные -->
    <div class="flex flex-1 flex-col gap-2.5 p-3.5">
      <div class="flex items-center gap-2 text-xs text-muted">
        <span aria-hidden="true">◷</span>
        <span v-if="nextSession">{{ dateFull(nextSession.startsAt) }} · {{ time(nextSession.startsAt) }}</span>
        <span v-else>Расписание уточняется</span>
      </div>

      <div class="flex items-center gap-2 text-xs text-muted">
        <span aria-hidden="true">⌖</span>
        <span class="truncate">{{ event.venue }}, {{ event.city }}</span>
      </div>

      <div class="mt-auto flex items-end justify-between gap-3 border-t border-line pt-3">
        <div>
          <p class="text-2xs uppercase tracking-wide text-subtle">от</p>
          <p class="text-base font-semibold tabular-nums text-content">{{ money(event.priceFromMinor) }}</p>
        </div>

        <span
          class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
          :class="
            soldOut
              ? 'cursor-not-allowed bg-surface-3 text-subtle'
              : 'bg-brand-500 text-white group-hover:bg-brand-400'
          "
        >
          {{ soldOut ? 'Нет мест' : 'Выбрать' }}
        </span>
      </div>
    </div>

    <!-- Вся карточка — ссылка: кликабельная область не должна быть только текстом -->
    <RouterLink
      :to="`/event/${event.id}`"
      class="absolute inset-0 rounded-xl"
      :aria-label="`Открыть событие ${event.title}`"
    />
  </article>
</template>
