<script setup lang="ts">
/**
 * Мероприятия.
 *
 * У события есть единственный показатель, ради которого экран существует —
 * заполняемость. Поэтому процент проданных мест показан полосой прямо в строке:
 * администратор сканирует таблицу по полосам, а не по числам.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { useUiStore } from '@/stores/ui'
import { EVENTS } from '@/lib/mock'
import { money, dateFull, time, plural } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'
import { cn } from '@/lib/cn'

const router = useRouter()
const ui = useUiStore()

const filter = ref('all')
const search = ref('')

const FILTERS = [
  { value: 'all', label: 'Все' },
  { value: 'published', label: 'В продаже' },
  { value: 'draft', label: 'Черновики' },
  { value: 'sold_out', label: 'Без мест' },
]

/** Заполняемость считаем по первому сеансу: это и есть «как идёт продажа». */
function occupancy(event: (typeof EVENTS)[number]): number {
  const session = event.sessions[0]
  if (!session) return 0
  const capacity = Math.max(session.availableSeats, 1) * 4
  return Math.min(100, Math.round(((capacity - session.availableSeats) / capacity) * 100))
}

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return EVENTS.filter((event) => {
    const byStatus = filter.value === 'all' || event.status === filter.value
    const byQuery = !q || event.title.toLowerCase().includes(q) || event.venue.toLowerCase().includes(q)
    return byStatus && byQuery
  }).map((event) => ({
    id: event.id,
    title: event.title,
    category: event.category,
    venue: event.venue,
    sessionsCount: event.sessionsCount,
    priceFromMinor: event.priceFromMinor,
    status: event.status,
    occupancy: occupancy(event),
    firstSession: event.sessions[0],
  }))
})

const COLUMNS: Column[] = [
  { key: 'title', label: 'Мероприятие', sortable: true },
  { key: 'venue', label: 'Площадка', hideOnMobile: true },
  { key: 'sessionsCount', label: 'Сеансы', align: 'center', width: '90px' },
  { key: 'occupancy', label: 'Заполняемость', width: '180px' },
  { key: 'priceFromMinor', label: 'Цена от', align: 'right', sortable: true, width: '110px' },
  { key: 'status', label: 'Статус', align: 'right', width: '140px' },
]

function open(row: { id: string }): void {
  ui.notify('brand', 'Открываем мероприятие', 'Детальная страница события появится в следующем срезе')
  void row
}

function publish(): void {
  ui.notify('mint', 'Мероприятие опубликовано', 'Оно уже видно в афише')
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Мероприятия</h1>
        <p class="mt-1 text-sm text-muted">
          {{ rows.length }} {{ plural(rows.length, 'мероприятие', 'мероприятия', 'мероприятий') }}
        </p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" @click="router.push('/admin/halls')">Схемы залов</NButton>
        <NButton variant="primary" @click="publish">Новое мероприятие</NButton>
      </div>
    </div>

    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="filter" :segments="FILTERS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Название или площадка" icon="⌕" class="max-w-xs" aria-label="Поиск мероприятий" />
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="title" sort-dir="asc" @row="open">
        <template #cell-title="{ row }">
          <span class="block truncate font-medium text-content">{{ row.title }}</span>
          <span class="block truncate text-2xs text-subtle">
            {{ row.category }}
            <template v-if="row.firstSession"> · {{ dateFull(row.firstSession.startsAt) }}, {{ time(row.firstSession.startsAt) }}</template>
          </span>
        </template>
        <template #cell-venue="{ row }">
          <span class="truncate text-muted">{{ row.venue }}</span>
        </template>
        <template #cell-sessionsCount="{ row }">{{ row.sessionsCount }}</template>
        <template #cell-occupancy="{ row }">
          <div class="flex items-center gap-2">
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
              <div
                :class="
                  cn(
                    'h-full rounded-full transition-all duration-320',
                    row.occupancy >= 90 ? 'bg-accent-500' : row.occupancy >= 50 ? 'bg-brand-500' : 'bg-mint-500',
                  )
                "
                :style="{ width: `${row.occupancy}%` }"
              />
            </div>
            <span class="w-8 flex-none text-right text-2xs tabular-nums text-muted">{{ row.occupancy }}%</span>
          </div>
        </template>
        <template #cell-priceFromMinor="{ row }">
          <span class="tabular-nums text-content">{{ money(row.priceFromMinor) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="event" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.title }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.venue }}</span>
          <span>{{ row.sessionsCount }} сеансов</span>
          <span>{{ row.occupancy }}% продано</span>
          <NStatusBadge kind="event" :status="row.status" />
        </template>
      </NDataTable>
    </div>
  </div>
</template>
