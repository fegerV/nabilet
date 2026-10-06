<script setup lang="ts">
/**
 * Афиша — главный виджет витрины.
 *
 * Что настраивается: раскладка (сетка/список), число колонок, лимит,
 * сортировка, фильтр по категории и показ чипсов фильтров. Плюс виджет
 * слушает ?q= — чипсы «Категории» выше ведут сюда же, поэтому фильтр
 * работает как навигация, а не как локальное состояние одной секции.
 *
 * Карточка отвечает на вопрос «что, где, когда и почём» без перехода:
 * цена и дата видны сразу (первый экран — не каталог для чтения, а витрина).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import EventCard from '@/components/storefront/EventCard.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useCatalogStore } from '@/stores/catalog'
import { money, dateFull, time } from '@/lib/format'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()
const route = useRoute()
const router = useRouter()

const layout = computed(() => setting<'grid' | 'list'>(props.section.settings, 'layout', 'grid'))
const columns = computed(() => setting<number>(props.section.settings, 'columns', 3))
const limit = computed(() => setting<number>(props.section.settings, 'limit', 12))
const sort = computed(() => setting<'date' | 'price' | 'title'>(props.section.settings, 'sort', 'date'))
const fixedCategory = computed(() => setting<string>(props.section.settings, 'category', ''))
const showFilters = computed(() => setting<boolean>(props.section.settings, 'showFilters', true))

/** Категория из адреса: её выставляет виджет «Категории». */
const queryCategory = computed(() => String(route.query.cat ?? route.query.q ?? ''))
const activeCategory = ref('')

watch(
  queryCategory,
  (value) => {
    activeCategory.value = value || fixedCategory.value
  },
  { immediate: true },
)

watch(fixedCategory, (value) => {
  if (!queryCategory.value) activeCategory.value = value
})

onMounted(() => catalog.load())

const GRID_COLS: Record<number, string> = {
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-2 lg:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4',
}

const filtered = computed(() => {
  const q = String(route.query.q ?? '').toLowerCase()
  let list = catalog.events

  if (activeCategory.value) {
    list = list.filter((e) => catalog.categoryName(e) === activeCategory.value)
  }
  if (q) {
    list = list.filter(
      (e) =>
        (e.title ?? '').toLowerCase().includes(q) ||
        catalog.venueName(e).toLowerCase().includes(q) ||
        catalog.venueCity(e).toLowerCase().includes(q),
    )
  }

  const sorted = [...list]
  if (sort.value === 'date') sorted.sort((a, b) => catalog.startsAt(a) - catalog.startsAt(b))
  if (sort.value === 'price') sorted.sort((a, b) => catalog.priceFrom(a) - catalog.priceFrom(b))
  if (sort.value === 'title') sorted.sort((a, b) => (a.title ?? '').localeCompare(b.title ?? '', 'ru'))

  return sorted.slice(0, limit.value)
})

const chips = computed(() => {
  const own = catalog.categories
  const base = activeCategory.value && !own.includes(activeCategory.value) ? [activeCategory.value] : []
  return ['Все', ...base, ...own]
})

function pickCategory(name: string): void {
  activeCategory.value = name === 'Все' ? '' : name
  // Чип — это навигация: состояние фильтра должно выжить при «назад».
  router.push({ path: '/', query: name === 'Все' ? {} : { cat: name } })
}

function openEvent(slug: string | undefined, id: string): void {
  router.push(`/event/${slug || id}`)
}

function sessionStartsAt(event: { sessions?: Array<{ startsAt?: string; starts_at?: string }> }): string {
  return event.sessions?.[0]?.startsAt ?? event.sessions?.[0]?.starts_at ?? ''
}
</script>

<template>
  <div>
    <!-- Фильтры: сегменты, а не выпадающий список — выбрать одним касанием -->
    <div v-if="showFilters && chips.length > 2" class="mb-5 overflow-x-auto pb-1 no-scrollbar">
      <div class="flex gap-2">
        <button
          v-for="chip in chips"
          :key="chip"
          type="button"
          :class="[
            'flex-none rounded-full border px-3.5 py-1.5 text-sm transition-colors duration-120',
            (chip === 'Все' ? !activeCategory : activeCategory === chip)
              ? 'border-brand-500 bg-brand-500/12 text-brand-300'
              : 'border-line bg-surface text-muted hover:border-brand-500/40 hover:text-content',
          ]"
          @click="pickCategory(chip)"
        >
          {{ chip }}
        </button>
      </div>
    </div>

    <p v-if="catalog.loading" class="py-8 text-sm text-subtle">Загрузка афиши…</p>

    <div v-else-if="catalog.error" class="surface-card px-4 py-6 text-sm text-rose-400">
      Не удалось загрузить мероприятия: {{ catalog.error }}
    </div>

    <!-- Сетка -->
    <div
      v-else-if="filtered.length && layout === 'grid'"
      :class="['grid gap-4', GRID_COLS[columns] ?? GRID_COLS[3]]"
    >
      <EventCard v-for="event in filtered" :key="event.id" :event="event" />
    </div>

    <!-- Список: плотнее, удобнее для десятка событий одного формата -->
    <ul v-else-if="filtered.length" class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
      <li v-for="event in filtered" :key="event.id">
        <button
          type="button"
          class="flex w-full items-center gap-4 p-3.5 text-left transition-colors hover:bg-surface-2"
          @click="openEvent(event.slug, event.id)"
        >
          <span
            class="h-16 w-16 flex-none rounded-lg bg-cover bg-center"
            :style="
              event.poster
                ? { backgroundImage: `url(${event.poster})` }
                : { background: `linear-gradient(145deg, ${event.posterFrom ?? 'rgb(var(--brand-500))'} 0%, ${event.posterTo ?? 'rgb(var(--accent-500))'} 100%)` }
            "
            aria-hidden="true"
          />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold text-content">{{ event.title }}</span>
            <span class="mt-0.5 block truncate text-xs text-muted">
              {{ catalog.venueName(event) }}<template v-if="catalog.venueCity(event)"> · {{ catalog.venueCity(event) }}</template>
            </span>
            <span v-if="sessionStartsAt(event)" class="mt-0.5 block text-xs text-subtle">
              {{ dateFull(sessionStartsAt(event)) }} · {{ time(sessionStartsAt(event)) }}
            </span>
          </span>
          <span class="flex-none text-right">
            <span class="block text-2xs uppercase tracking-wide text-subtle">от</span>
            <span class="block text-sm font-semibold tabular-nums text-content">{{ money(catalog.priceFrom(event)) }}</span>
          </span>
        </button>
      </li>
    </ul>

    <NEmptyState
      v-else
      class="surface-card"
      icon="⌕"
      title="В афише пока пусто"
      description="Опубликуйте мероприятие в админке — оно сразу появится в афише."
      action-label="Сбросить фильтр"
      @action="pickCategory('Все')"
    />
  </div>
</template>
