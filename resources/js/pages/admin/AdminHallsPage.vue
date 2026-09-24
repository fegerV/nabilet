<script setup lang="ts">
/**
 * Залы (админка).
 *
 * Список залов из /api/v1/halls; клик по строке → редактор схемы зала
 * (/#/admin/halls/{publicId}/editor).
 */
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import NDataTable from '@/components/ui/NDataTable.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NButton from '@/components/ui/NButton.vue'
import { get } from '@/lib/api'
import type { Column } from '@/components/ui/NDataTable.vue'

const router = useRouter()

interface ApiHall {
  id: number
  public_id: string
  name: string
  venue_id?: number
  status?: string
  capacity?: number | null
  venue?: { id: number; name: string } | null
}

const halls = ref<ApiHall[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const rows = computed(() =>
  halls.value.map((h) => ({
    id: String(h.id),
    publicId: h.public_id,
    name: h.name,
    venue: h.venue?.name ?? '—',
    capacity: h.capacity ?? '—',
    status: h.status ?? 'active',
    raw: h,
  })),
)

const COLUMNS: Column[] = [
  { key: 'name', label: 'Зал', sortable: true },
  { key: 'venue', label: 'Площадка', hideOnMobile: true },
  { key: 'capacity', label: 'Вместимость', align: 'right', width: '120px' },
  { key: 'status', label: 'Статус', align: 'right', width: '110px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiHall[] }>('/halls')
    const inner = res.data
    halls.value = Array.isArray(inner) ? inner : (inner as { data: ApiHall[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openEditor(row: { raw: ApiHall }): void {
  router.push(`/admin/halls/${row.raw.public_id}/editor`)
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Залы</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} залов</template>
        </p>
      </div>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить залы: {{ loadError }}
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="name" sort-dir="asc" :loading="loading" @row="openEditor">
        <template #cell-name="{ row }">
          <span class="block truncate font-medium text-content">{{ row.name }}</span>
          <span class="block truncate text-2xs text-subtle">ID {{ row.id }}</span>
        </template>
        <template #cell-venue="{ row }">
          <span class="truncate text-muted">{{ row.venue }}</span>
        </template>
        <template #cell-capacity="{ row }">
          <span class="tabular-nums text-muted">{{ row.capacity }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="venue" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.name }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.venue }}</span>
          <NStatusBadge kind="venue" :status="row.status" />
        </template>
      </NDataTable>
    </div>
  </div>
</template>