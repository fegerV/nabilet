<script setup lang="ts">
/**
 * Мероприятия (админка).
 *
 * Реальные данные из API: GET /api/v1/events?per_page=...&status=...
 * У события единственный показатель, ради которого экран существует —
 * заполняемость. Поэтому процент проданных мест показан полосой прямо в строке.
 */
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { useUiStore } from '@/stores/ui'
import { get } from '@/lib/api'
import { money, dateFull, time } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'
import { cn } from '@/lib/cn'

const router = useRouter()
const ui = useUiStore()

interface ApiEvent {
  id: number
  slug: string
  title: string
  status: string
  category?: { name?: string } | string | null
  venue?: { name?: string; city?: string } | string | null
  sessions?: Array<{ id: number; starts_at?: string; hall?: string; available_seats?: number }>
  short_description?: string | null
}

const filter = ref('all')
const search = ref('')
const events = ref<ApiEvent[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const FILTERS = [
  { value: 'all', label: 'Все' },
  { value: 'published', label: 'В продаже' },
  { value: 'draft', label: 'Черновики' },
  { value: 'on_sale', label: 'Без мест' },
]

/** Заполняемость считаем по первому сеансу: это и есть «как идёт продажа». */
function occupancy(event: ApiEvent): number {
  const session = event.sessions?.[0]
  if (!session) return 0
  const capacity = Math.max(session.available_seats ?? 1, 1) * 4
  return Math.min(100, Math.round(((capacity - (session.available_seats ?? capacity)) / capacity) * 100))
}

function venueName(venue: ApiEvent['venue']): string {
  if (!venue) return '—'
  return typeof venue === 'string' ? venue : venue.name ?? ''
}

function categoryName(cat: ApiEvent['category']): string {
  if (!cat) return '—'
  return typeof cat === 'string' ? cat : cat.name ?? '—'
}

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return events.value
    .filter((event) => {
      const byStatus = filter.value === 'all' || event.status === filter.value
      const byQuery = !q || event.title.toLowerCase().includes(q) || venueName(event.venue).toLowerCase().includes(q)
      return byStatus && byQuery
    })
    .map((event) => ({
      id: String(event.id),
      slug: event.slug,
      title: event.title,
      category: categoryName(event.category),
      venue: venueName(event.venue),
      sessionsCount: event.sessions?.length ?? 0,
      status: event.status,
      occupancy: occupancy(event),
      firstSession: event.sessions?.[0],
    }))
})

const COLUMNS: Column[] = [
  { key: 'title', label: 'Мероприятие', sortable: true },
  { key: 'venue', label: 'Площадка', hideOnMobile: true },
  { key: 'sessionsCount', label: 'Сеансы', align: 'center', width: '90px' },
  { key: 'occupancy', label: 'Заполняемость', width: '180px' },
  { key: 'status', label: 'Статус', align: 'right', width: '140px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiEvent[] }>('/events?per_page=100')
    const inner = res.data as unknown as { data?: ApiEvent[] } | ApiEvent[]
    events.value = Array.isArray(inner) ? inner : (inner as { data: ApiEvent[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function open(row: { id: string; slug: string }): void {
  router.push(`/admin/events/${row.id}`)
}

function create(): void {
  router.push('/admin/events/new')
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Мероприятия</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} мероприятий</template>
        </p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" @click="router.push('/admin/halls')">Схемы залов</NButton>
        <NButton variant="primary" @click="create">Новое мероприятие</NButton>
      </div>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить мероприятия: {{ loadError }}
    </div>

    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="filter" :segments="FILTERS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Название или площадка" icon="⌕" class="max-w-xs" aria-label="Поиск мероприятий" />
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="title" sort-dir="asc" :loading="loading" @row="open">
        <template #cell-title="{ row }">
          <span class="block truncate font-medium text-content">{{ row.title }}</span>
          <span class="block truncate text-2xs text-subtle">
            {{ row.category }}
            <template v-if="row.firstSession"> · {{ dateFull(row.firstSession.starts_at) }}, {{ time(row.firstSession.starts_at) }}</template>
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
        <template #cell-status="{ row }">
          <NStatusBadge kind="event" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.title }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.venue }}</span>
          <span>{{ row.sessionsCount }} сеансов</span>
          <NStatusBadge kind="event" :status="row.status" />
        </template>
      </NDataTable>
    </div>
  </div>
</template>