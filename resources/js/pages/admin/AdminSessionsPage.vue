<script setup lang="ts">
/**
 * Сеансы (админка) — общий список по всем мероприятиям.
 *
 * CRUD через /api/v1/sessions (write-роуты под auth:api + admin).
 * Сеанс = привязка события к площадке и залу с датой и временем.
 *
 * Форма вынесена в `SessionFormModal.vue`: её же открывает карточка мероприятия,
 * чтобы «назначить дату и площадку» можно было и оттуда. Держать две копии формы
 * нельзя — они разъедутся ровно так же, как разъехались выбор зала и вывод
 * площадки на сервере.
 */
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import SessionFormModal from '@/components/admin/SessionFormModal.vue'
import { get } from '@/lib/api'
import { dateFull, time } from '@/lib/format'
import type { SessionRecord } from '@/lib/sessionForm'
import type { Column } from '@/components/ui/NDataTable.vue'

const router = useRouter()
const route = useRoute()

const sessions = ref<SessionRecord[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const modalOpen = ref(false)
const editing = ref<SessionRecord | null>(null)
/** Мероприятие, зафиксированное при открытии формы по ссылке `?event=`. */
const lockedEventId = ref<number | null>(null)
const lockedEventTitle = ref<string | null>(null)

function openCreate(): void {
  editing.value = null
  modalOpen.value = true
}

function openEdit(row: { raw: SessionRecord }): void {
  editing.value = row.raw
  lockedEventId.value = null
  lockedEventTitle.value = null
  modalOpen.value = true
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
  // Действие: переход к ценам рядов (PATCH /inventory/sessions/{id}/prices).
  { key: 'actions', label: '', align: 'right', width: '90px' },
]

function openPrices(evt: MouseEvent, rowId: string): void {
  // Клик по ячейке не должен открывать модалку редактирования (row-обработчик таблицы).
  evt.stopPropagation()
  router.push(`/admin/sessions/${rowId}/prices`)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<SessionRecord[]>('/sessions?per_page=100')
    // `/sessions` отдаёт пагинатор внутри `data`, а не плоский массив.
    const inner = res.data as unknown as { data?: SessionRecord[] } | SessionRecord[]
    sessions.value = Array.isArray(inner) ? inner : (inner.data ?? [])
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

/** Открыть форму сразу на нужном мероприятии (пришли по `?event=`). */
async function openForEvent(eventId: number): Promise<void> {
  try {
    const res = await get<{ id: number; title: string }>(`/events/${eventId}`)
    lockedEventTitle.value = res.data?.title ?? null
  } catch {
    // Название — украшение: без него форма всё равно откроется с id.
    lockedEventTitle.value = null
  }
  editing.value = null
  lockedEventId.value = eventId
  modalOpen.value = true
}

onMounted(async () => {
  await load()

  // Приход из формы события по кнопке «Создать и настроить сеанс →»
  // (/admin/sessions?event={id}): сразу открываем форму с этим мероприятием.
  const raw = String(route.query.event ?? '')
  if (/^\d+$/.test(raw)) {
    await openForEvent(Number(raw))
    // Убираем query, чтобы форма не открывалась повторно при навигации назад.
    void router.replace({ path: route.path })
  }
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

        <!-- Действие: переход к ценам рядов сеанса -->
        <template #cell-actions="{ row }">
          <NButton variant="ghost" size="sm" @click="openPrices($event, row.id)">Цены</NButton>
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

    <SessionFormModal
      v-model:open="modalOpen"
      :session="editing"
      :lock-event-id="lockedEventId"
      :lock-event-title="lockedEventTitle"
      @saved="load"
    />
  </div>
</template>
