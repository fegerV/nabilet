<script setup lang="ts">
/**
 * Подборка: карусель или сетка выбранных событий.
 *
 * Два источника: «ближайшие» (события, у которых сеанс ещё впереди) или
 * «вручную» (список slug/id из конструктора). Ручной нужен, когда у площадки
 * есть премьера, которую надо держать на первом экране независимо от даты.
 */
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import EventCard from '@/components/storefront/EventCard.vue'
import { useCatalogStore } from '@/stores/catalog'
import { money, dateFull, time } from '@/lib/format'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()
const router = useRouter()

const source = computed(() => setting<'upcoming' | 'manual'>(props.section.settings, 'source', 'upcoming'))
const limit = computed(() => setting<number>(props.section.settings, 'limit', 6))
const layout = computed(() => setting<'carousel' | 'grid'>(props.section.settings, 'layout', 'carousel'))
const ids = computed(() => setting<string[]>(props.section.settings, 'ids', []))

onMounted(() => catalog.load())

const items = computed(() => {
  if (source.value === 'manual') {
    const wanted = ids.value
    const picked = catalog.events.filter((e) => wanted.includes(e.slug ?? '') || wanted.includes(e.id))
    return picked.length ? picked : catalog.upcoming.slice(0, limit.value)
  }
  return catalog.upcoming.slice(0, limit.value)
})

function open(slug: string | undefined, id: string): void {
  router.push(`/event/${slug || id}`)
}
</script>

<template>
  <div>
    <p v-if="catalog.loading" class="py-8 text-sm text-subtle">Загрузка подборки…</p>

    <div v-else-if="!items.length" class="surface-card px-4 py-6 text-sm text-subtle">
      Нет событий для подборки. Опубликуйте мероприятие или выберите их вручную в конструкторе.
    </div>

    <!-- Карусель: скролл со snap. Стрелки не нужны — на мобильном жест есть,
         на десктопе скролл колесом работает так же. -->
    <div
      v-else-if="layout === 'carousel'"
      class="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 no-scrollbar sm:-mx-6 sm:px-6"
    >
      <div
        v-for="event in items"
        :key="event.id"
        class="w-[78%] flex-none snap-start sm:w-[46%] lg:w-[31%]"
      >
        <EventCard :event="event" />
      </div>
    </div>

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <button
        v-for="event in items"
        :key="event.id"
        type="button"
        class="group overflow-hidden rounded-xl border border-line bg-surface text-left transition-all duration-200 hover:-translate-y-0.5 hover:border-brand-500/40 hover:shadow-md"
        @click="open(event.slug, event.id)"
      >
        <span
          class="block aspect-[16/9] bg-cover bg-center"
          :style="
            event.poster
              ? { backgroundImage: `url(${event.poster})` }
              : { background: `linear-gradient(145deg, ${event.posterFrom ?? 'rgb(var(--brand-500))'} 0%, ${event.posterTo ?? 'rgb(var(--accent-500))'} 100%)` }
          "
          aria-hidden="true"
        />
        <span class="block p-3.5">
          <span class="block truncate text-sm font-semibold text-content">{{ event.title }}</span>
          <span class="mt-1 block text-xs text-muted">
            <template v-if="event.sessions?.length">
              {{ dateFull(event.sessions[0]?.startsAt ?? event.sessions[0]?.starts_at ?? '') }} ·
              {{ time(event.sessions[0]?.startsAt ?? event.sessions[0]?.starts_at ?? '') }}
            </template>
          </span>
          <span class="mt-2 block text-sm font-semibold tabular-nums text-content">
            от {{ money(catalog.priceFrom(event)) }}
          </span>
        </span>
      </button>
    </div>
  </div>
</template>
