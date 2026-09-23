<script setup lang="ts">
/**
 * Афиша.
 *
 * Первый экран отвечает на вопрос «что происходит рядом и сколько это стоит»,
 * поэтому цена и дата видны сразу, без перехода. Категории — сегменты, а не
 * выпадающий список: выбрать «Кино» одним касанием быстрее, чем открыть список.
 */
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import EventCard from '@/components/storefront/EventCard.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import { EVENTS, CATEGORIES } from '@/lib/mock'

const route = useRoute()
const category = ref('Все')
const query = computed(() => String(route.query.q ?? '').toLowerCase())

const segments = computed(() => CATEGORIES.map((c) => ({ value: c, label: c })))

const events = computed(() => {
  const q = query.value
  return EVENTS.filter((event) => {
    const byCategory = category.value === 'Все' || event.category === category.value
    const byQuery =
      !q ||
      event.title.toLowerCase().includes(q) ||
      event.venue.toLowerCase().includes(q) ||
      event.city.toLowerCase().includes(q)
    return byCategory && byQuery
  })
})

function resetFilters(): void {
  category.value = 'Все'
}
</script>

<template>
  <div>
    <!-- Герой: задаёт тон витрине, но не отнимает место у афиши -->
    <section class="relative overflow-hidden border-b border-line">
      <div class="pointer-events-none absolute inset-0 bg-stage" aria-hidden="true" />
      <div class="relative mx-auto max-w-content px-4 py-10 sm:px-6 sm:py-14">
        <p class="mb-3 inline-flex items-center gap-2 rounded-full border border-brand-500/30 bg-brand-500/10 px-3 py-1 text-xs font-medium text-brand-300">
          <span class="h-1.5 w-1.5 rounded-full bg-brand-400 animate-pulse-ring" aria-hidden="true" />
          Билеты без наценки за кассу
        </p>
        <h1 class="max-w-2xl text-balance text-4xl font-bold leading-tight tracking-tight text-content sm:text-5xl">
          Выберите событие —<br />
          <span class="bg-brand-gradient bg-clip-text text-transparent">место найдём на схеме зала</span>
        </h1>
        <p class="mt-4 max-w-xl text-pretty text-base text-muted">
          Реальная рассадка, честные цены и билет с QR, который контролёр считает даже без интернета.
        </p>
      </div>
    </section>

    <!-- Фильтры -->
    <div class="sticky top-16 z-30 border-b border-line bg-canvas/85 backdrop-blur">
      <div class="mx-auto max-w-content overflow-x-auto px-4 py-3 sm:px-6 no-scrollbar">
        <NSegmented v-model="category" :segments="segments" aria-label="Категории событий" size="sm" />
      </div>
    </div>

    <!-- Сетка -->
    <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
      <p class="mb-4 text-sm text-subtle">
        {{ events.length }} {{ events.length === 1 ? 'событие' : 'событий' }}
        <template v-if="query"> по запросу «{{ query }}»</template>
      </p>

      <div v-if="events.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <EventCard v-for="event in events" :key="event.id" :event="event" />
      </div>

      <NEmptyState
        v-else
        class="surface-card mt-2"
        icon="⌕"
        title="Ничего не нашлось"
        description="Попробуйте другую категорию или сбросьте фильтры — возможно, событие ещё в черновике."
        action-label="Сбросить фильтры"
        @action="resetFilters"
      />
    </div>
  </div>
</template>
