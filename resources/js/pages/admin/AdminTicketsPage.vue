<script setup lang="ts">
/**
 * Билеты (админка).
 *
 * Раздел отвечает на вопрос «что с конкретным билетом»: выдан, погашен,
 * возвращён. Поэтому номер и статус видны сразу, а деталка показывает
 * историю сканов — на входе спорят именно по ней.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NModal from '@/components/ui/NModal.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { get } from '@/lib/api'
import { money, dateTime } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

interface Scan {
  id?: number
  scanned_at?: string
  result?: string
  device?: string | null
}

interface ApiTicket {
  id: string
  ticket_number?: string | null
  status: string
  holder_name?: string | null
  qr_payload?: string | null
  issued_at?: string | null
  used_at?: string | null
  created_at?: string | null
  order?: { order_number?: string | null; total_amount?: number | null; customer_name?: string | null } | null
  scans?: Scan[]
}

const tickets = ref<ApiTicket[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const status = ref('all')
const search = ref('')

const STATUS_SEGMENTS = [
  { value: 'all', label: 'Все' },
  { value: 'issued', label: 'Действительны' },
  { value: 'used', label: 'Погашены' },
  { value: 'cancelled', label: 'Отменены' },
  { value: 'refunded', label: 'Возвраты' },
]

const detail = ref<ApiTicket | null>(null)
const detailOpen = ref(false)

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return tickets.value
    .filter((t) => {
      const byStatus = status.value === 'all' || t.status === status.value
      const byQuery =
        !q ||
        (t.ticket_number ?? '').toLowerCase().includes(q) ||
        (t.holder_name ?? '').toLowerCase().includes(q) ||
        (t.order?.order_number ?? '').toLowerCase().includes(q)
      return byStatus && byQuery
    })
    .map((t) => ({
      id: t.id,
      number: t.ticket_number ?? t.id,
      holder: t.holder_name ?? t.order?.customer_name ?? '—',
      order: t.order?.order_number ?? '—',
      issuedAt: t.issued_at ?? t.created_at ?? '',
      status: t.status,
      raw: t,
    }))
})

const COLUMNS: Column[] = [
  { key: 'number', label: 'Билет', sortable: true, width: '210px' },
  { key: 'holder', label: 'Владелец', sortable: true },
  { key: 'order', label: 'Заказ', hideOnMobile: true },
  { key: 'issuedAt', label: 'Выдан', hideOnMobile: true },
  { key: 'status', label: 'Статус', align: 'right', width: '150px' },
]

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: ApiTicket[] }>('/tickets?per_page=100')
    const inner = res.data as unknown as { data?: ApiTicket[] } | ApiTicket[]
    tickets.value = Array.isArray(inner) ? inner : (inner as { data: ApiTicket[] }).data ?? []
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openDetail(row: { raw: ApiTicket }): void {
  detail.value = row.raw
  detailOpen.value = true
}

function reset(): void {
  status.value = 'all'
  search.value = ''
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Билеты</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} из {{ tickets.length }}</template>
        </p>
      </div>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить билеты: {{ error }}
    </div>

    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="status" :segments="STATUS_SEGMENTS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Номер билета, владелец или заказ" icon="⌕" class="max-w-xs" aria-label="Поиск по билетам" />
      <NButton variant="ghost" size="sm" @click="reset">Сбросить</NButton>
    </div>

    <div class="mt-4">
      <NDataTable
        v-if="rows.length"
        :columns="COLUMNS"
        :rows="rows"
        sort="issuedAt"
        sort-dir="desc"
        :loading="loading"
        @row="openDetail"
      >
        <template #cell-number="{ row }">
          <span class="block font-mono text-xs text-brand-400">{{ row.number }}</span>
          <span class="block text-2xs text-subtle">{{ dateTime(row.issuedAt) }}</span>
        </template>
        <template #cell-order="{ row }">
          <span class="font-mono text-xs text-muted">{{ row.order }}</span>
        </template>
        <template #cell-issuedAt="{ row }">
          <span class="text-sm text-muted">{{ dateTime(row.issuedAt) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="ticket" :status="row.status" />
        </template>
        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.holder }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span class="font-mono">{{ row.number }}</span>
          <NStatusBadge kind="ticket" :status="row.status" />
        </template>
      </NDataTable>

      <NEmptyState
        v-else-if="!loading"
        class="surface-card"
        icon="◨"
        title="Билетов пока нет"
        description="Билеты появляются после оплаты заказа — по одному на каждое место."
      />
    </div>

    <NModal
      :open="detailOpen"
      :title="`Билет ${detail?.ticket_number ?? ''}`"
      description="История билета и контроль на входе"
      size="lg"
      @update:open="detailOpen = false"
    >
      <div v-if="detail" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Владелец</p>
            <p class="mt-0.5 text-sm text-content">{{ detail.holder_name || '—' }}</p>
            <p class="mt-0.5 text-xs text-muted">Заказ {{ detail.order?.order_number ?? '—' }}</p>
          </div>
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Статус</p>
            <p class="mt-0.5"><NStatusBadge kind="ticket" :status="detail.status" size="md" /></p>
            <p class="mt-0.5 text-xs text-muted">Выдан: {{ dateTime(detail.issued_at ?? detail.created_at ?? '') }}</p>
            <p v-if="detail.used_at" class="text-xs text-muted">Погашен: {{ dateTime(detail.used_at) }}</p>
          </div>
        </div>

        <div v-if="detail.qr_payload" class="rounded-lg border border-line bg-surface-2 p-3">
          <p class="mb-1 text-2xs uppercase tracking-wide text-subtle">QR-полезная нагрузка</p>
          <p class="break-all font-mono text-xs text-muted">{{ detail.qr_payload }}</p>
        </div>

        <div v-if="detail.scans?.length" class="rounded-lg border border-line bg-surface-2 p-3">
          <p class="mb-2 text-2xs uppercase tracking-wide text-subtle">Сканирования</p>
          <div v-for="(scan, i) in detail.scans" :key="i" class="flex items-center justify-between gap-2 py-1 text-sm">
            <span class="text-content">{{ scan.result ?? '—' }}</span>
            <span class="text-xs text-subtle">{{ dateTime(scan.scanned_at ?? '') }}</span>
          </div>
        </div>

        <div v-if="detail.order?.total_amount" class="flex justify-between text-sm">
          <span class="font-medium text-content">Сумма заказа</span>
          <span class="tabular-nums">{{ money(detail.order.total_amount) }}</span>
        </div>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="detailOpen = false">Закрыть</NButton>
      </template>
    </NModal>
  </div>
</template>
