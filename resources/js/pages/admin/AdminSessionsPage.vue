<script setup lang="ts">
/**
 * Сеансы (админка).
 *
 * CRUD через /api/v1/sessions (write-роуты под auth:sanctum + admin).
 * Сеанс = привязка события к залу с датой и временем.
 */
import { computed, ref, onMounted } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NModal from '@/components/ui/NModal.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { dateFull, time } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

const ui = useUiStore()

interface ApiSession {
  id: number
  event_id: number
  hall_id: number
  schema_version_id?: number | null
  starts_at: string
  ends_at?: string | null
  sales_start_at?: string | null
  sales_end_at?: string | null
  status: string
  event?: { id: number; title: string } | null
  hall?: { id: number; name: string } | null
  schema_version?: { id: number; name?: string } | null
}

const sessions = ref<ApiSession[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

/* Для формы: события и залы */
const events = ref<Array<{ id: number; title: string }>>([])
const halls = ref<Array<{ id: number; name: string; public_id?: string }>>([])
/* Опубликованные версии схем выбранного зала (для явного выбора схемы сеанса) */
const schemaVersions = ref<Array<{ id: number; version: number; status: string }>>([])
const schemaVersionsLoading = ref(false)

/* Форма */
const modalOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const editId = ref<number | null>(null)

interface SessionForm {
  event_id: string
  hall_id: string
  schema_version_id: string
  starts_at: string
  starts_time: string
  sales_start_at: string
  sales_start_time: string
  sales_end_at: string
  sales_end_time: string
  status: string
}

function emptyForm(): SessionForm {
  return {
    event_id: '',
    hall_id: '',
    schema_version_id: '',
    starts_at: '',
    starts_time: '19:00',
    sales_start_at: '',
    sales_start_time: '00:00',
    sales_end_at: '',
    sales_end_time: '23:59',
    status: 'scheduled',
  }
}

const form = ref<SessionForm>(emptyForm())

const STATUS_OPTIONS = [
  { value: 'scheduled', label: 'Запланирован' },
  { value: 'on_sale', label: 'В продаже' },
  { value: 'held', label: 'Проведён' },
  { value: 'completed', label: 'Завершён' },
  { value: 'cancelled', label: 'Отменён' },
]

function openCreate(): void {
  editId.value = null
  form.value = emptyForm()
  schemaVersions.value = []
  formError.value = null
  modalOpen.value = true
}

/** «2026-10-05 19:00:00» → { date: '2026-10-05', time: '19:00' } */
function splitDateTime(value?: string | null): { date: string; time: string } {
  if (!value) return { date: '', time: '' }
  const iso = value.replace(' ', 'T')
  return { date: iso.slice(0, 10), time: iso.slice(11, 16) }
}

/** { date, time } → «YYYY-MM-DD HH:MM» или null, если дата не заполнена */
function joinDateTime(date: string, timeValue: string): string | null {
  if (!date) return null
  return `${date} ${timeValue || '00:00'}`
}

async function loadSchemaVersions(hallId: string): Promise<void> {
  schemaVersions.value = []
  if (!hallId) return
  const hall = halls.value.find((h) => String(h.id) === hallId)
  if (!hall?.public_id) return
  schemaVersionsLoading.value = true
  try {
    const res = await get<{ data: Array<{ id: number; version: number; status: string }> }>(
      `/halls/${hall.public_id}/schema-versions`,
    )
    const list = Array.isArray(res.data) ? res.data : []
    // Для сеанса подходит только опубликованная схема (с неё генерируются места).
    schemaVersions.value = list.filter((v) => v.status === 'published')
  } catch {
    schemaVersions.value = []
  } finally {
    schemaVersionsLoading.value = false
  }
}

function onHallChange(): void {
  form.value.schema_version_id = ''
  void loadSchemaVersions(form.value.hall_id)
}

function openEdit(row: { raw: ApiSession }): void {
  const s = row.raw
  editId.value = s.id
  const start = splitDateTime(s.starts_at)
  const salesStart = splitDateTime(s.sales_start_at)
  const salesEnd = splitDateTime(s.sales_end_at)
  form.value = {
    event_id: String(s.event_id ?? ''),
    hall_id: String(s.hall_id ?? ''),
    schema_version_id: s.schema_version_id != null ? String(s.schema_version_id) : '',
    starts_at: start.date,
    starts_time: start.time || '19:00',
    sales_start_at: salesStart.date,
    sales_start_time: salesStart.time || '00:00',
    sales_end_at: salesEnd.date,
    sales_end_time: salesEnd.time || '23:59',
    status: s.status ?? 'scheduled',
  }
  formError.value = null
  modalOpen.value = true
  void loadSchemaVersions(form.value.hall_id)
}

function closeModal(): void {
  modalOpen.value = false
}

async function loadOptions(): Promise<void> {
  const [evRes, hallRes] = await Promise.all([
    get<{ data: Array<{ id: number; title: string }> }>('/events?per_page=100'),
    get<{ data: Array<{ id: number; name: string }> }>('/halls'),
  ])
  events.value = Array.isArray(evRes.data) ? evRes.data : (evRes.data as { data: Array<{ id: number; title: string }> }).data ?? []
  halls.value = Array.isArray(hallRes.data) ? hallRes.data : (hallRes.data as { data: Array<{ id: number; name: string }> }).data ?? []
}

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  formError.value = null
  try {
    const startsAt = joinDateTime(form.value.starts_at, form.value.starts_time)
    const salesStartAt = joinDateTime(form.value.sales_start_at, form.value.sales_start_time)
    const salesEndAt = joinDateTime(form.value.sales_end_at, form.value.sales_end_time)
    if (form.value.status === 'on_sale' && !salesStartAt) {
      formError.value = 'Для статуса «В продаже» укажите дату и время старта продаж.'
      return
    }
    const payload: Record<string, unknown> = {
      event_id: Number(form.value.event_id),
      hall_id: Number(form.value.hall_id),
      starts_at: startsAt,
      status: form.value.status,
      sales_start_at: salesStartAt,
      sales_end_at: salesEndAt,
    }
    // Пустая строка — «авто» (бэкенд возьмёт последнюю published-схему зала);
    // null в PATCH означает «не менять», поэтому отправляем только выбранное значение.
    if (form.value.schema_version_id !== '') {
      payload.schema_version_id = Number(form.value.schema_version_id)
    }
    if (editId.value) {
      await send<unknown>(`/sessions/${editId.value}`, 'PATCH', payload)
      ui.notify('mint', 'Сеанс обновлён', `${startsAt}`)
    } else {
      await send<unknown>('/sessions', 'POST', payload)
      ui.notify('mint', 'Сеанс создан', `${startsAt}`)
    }
    modalOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

const rows = computed(() =>
  sessions.value.map((s) => ({
    id: String(s.id),
    title: s.event?.title ?? `#${s.event_id}`,
    hall: s.hall?.name ?? '—',
    starts_at: s.starts_at,
    status: s.status,
    raw: s,
  })),
)

const COLUMNS: Column[] = [
  { key: 'title', label: 'Мероприятие', sortable: true },
  { key: 'hall', label: 'Зал', hideOnMobile: true },
  { key: 'starts_at', label: 'Начало', sortable: true, hideOnMobile: true },
  { key: 'status', label: 'Статус', align: 'right', width: '130px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiSession[] }>('/sessions?per_page=100')
    const inner = res.data as unknown as { data?: ApiSession[] } | ApiSession[]
    sessions.value = Array.isArray(inner) ? inner : (inner as { data: ApiSession[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([load(), loadOptions()])
})
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Сеансы</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} сеансов</template>
        </p>
      </div>
      <NButton variant="primary" @click="openCreate">Новый сеанс</NButton>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить сеансы: {{ loadError }}
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="starts_at" sort-dir="desc" :loading="loading" @row="openEdit">
        <template #cell-title="{ row }">
          <span class="block truncate font-medium text-content">{{ row.title }}</span>
          <span class="block truncate text-2xs text-subtle">ID {{ row.id }}</span>
        </template>
        <template #cell-hall="{ row }">
          <span class="truncate text-muted">{{ row.hall }}</span>
        </template>
        <template #cell-starts_at="{ row }">
          <span class="truncate text-muted tabular-nums">{{ dateFull(row.starts_at) }}, {{ time(row.starts_at) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="session" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.title }}</span>
          <span class="block text-2xs text-subtle">{{ row.hall }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ dateFull(row.starts_at) }}, {{ time(row.starts_at) }}</span>
          <NStatusBadge kind="session" :status="row.status" />
        </template>
      </NDataTable>
    </div>

    <NModal
      :open="modalOpen"
      :title="editId ? 'Редактировать сеанс' : 'Новый сеанс'"
      size="md"
      @update:open="closeModal"
    >
      <div v-if="formError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
        {{ formError }}
      </div>

      <form class="space-y-3" @submit.prevent="save">
        <NSelect v-model="form.event_id" label="Мероприятие" :options="events.map(e => ({ value: String(e.id), label: e.title }))" required />
        <NSelect v-model="form.hall_id" label="Зал" :options="halls.map(h => ({ value: String(h.id), label: h.name }))" required @update:model-value="onHallChange" />
        <NSelect
          v-model="form.schema_version_id"
          :label="schemaVersionsLoading ? 'Загрузка схем…' : 'Схема зала'"
          :options="[
            { value: '', label: schemaVersionsLoading ? 'Загрузка…' : 'Авто (последняя опубликованная)' },
            ...schemaVersions.map(v => ({ value: String(v.id), label: `Версия ${v.version}` })),
          ]"
          :disabled="schemaVersionsLoading"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="form.starts_at" label="Дата" type="date" required />
          <NInput v-model="form.starts_time" label="Время" placeholder="19:00" required />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="form.sales_start_at" label="Старт продаж (дата)" type="date" />
          <NInput v-model="form.sales_start_time" label="Старт продаж (время)" placeholder="00:00" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="form.sales_end_at" label="Окончание продаж (дата)" type="date" />
          <NInput v-model="form.sales_end_time" label="Окончание продаж (время)" placeholder="23:59" />
        </div>
        <NSelect v-model="form.status" label="Статус" :options="STATUS_OPTIONS" />

        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="closeModal">Отмена</NButton>
          <NButton type="submit" variant="accent" :loading="saving">
            {{ editId ? 'Сохранить' : 'Создать' }}
          </NButton>
        </div>
      </form>
    </NModal>
  </div>
</template>