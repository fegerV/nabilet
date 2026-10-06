<script setup lang="ts">
/**
 * Платежи (админка).
 *
 * Здесь смотрят, дошёл ли платёж: провайдер, сумма, статус, время оплаты.
 * Отдельная колонка «Создан → Оплачен» показывает задержку — по ней видно
 * зависший платёж, который покупатель уже считает оплаченным.
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

interface ApiPayment {
  id: string
  public_id?: string
  order_id?: number | null
  provider?: string | null
  provider_payment_id?: string | null
  amount?: number | null
  currency?: string | null
  status?: string | null
  payment_url?: string | null
  idempotency_key?: string | null
  created_at?: string | null
  paid_at?: string | null
  order?: { order_number?: string | null; customer_name?: string | null; customer_email?: string | null } | null
  transactions?: Array<{ id?: number; status?: string; amount?: number; created_at?: string }>
}

const payments = ref<ApiPayment[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const status = ref('all')
const search = ref('')

const STATUS_SEGMENTS = [
  { value: 'all', label: 'Все' },
  { value: 'succeeded', label: 'Успешные' },
  { value: 'pending', label: 'В обработке' },
  { value: 'failed', label: 'Ошибки' },
  { value: 'refunded', label: 'Возвраты' },
]

const detail = ref<ApiPayment | null>(null)
const detailOpen = ref(false)

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return payments.value
    .filter((p) => {
      const byStatus = status.value === 'all' || p.status === status.value
      const byQuery =
        !q ||
        (p.order?.order_number ?? '').toLowerCase().includes(q) ||
        (p.provider_payment_id ?? '').toLowerCase().includes(q) ||
        (p.order?.customer_email ?? '').toLowerCase().includes(q)
      return byStatus && byQuery
    })
    .map((p) => ({
      id: p.id,
      order: p.order?.order_number ?? '—',
      provider: p.provider ?? '—',
      amount: p.amount ?? 0,
      createdAt: p.created_at ?? '',
      paidAt: p.paid_at ?? '',
      status: p.status ?? 'pending',
      raw: p,
    }))
})

const COLUMNS: Column[] = [
  { key: 'order', label: 'Заказ', sortable: true, width: '210px' },
  { key: 'provider', label: 'Провайдер', sortable: true, width: '140px' },
  { key: 'amount', label: 'Сумма', align: 'right', sortable: true, width: '130px' },
  { key: 'createdAt', label: 'Создан → оплачен', hideOnMobile: true },
  { key: 'status', label: 'Статус', align: 'right', width: '150px' },
]

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: ApiPayment[] }>('/payments?per_page=100')
    const inner = res.data as unknown as { data?: ApiPayment[] } | ApiPayment[]
    payments.value = Array.isArray(inner) ? inner : (inner as { data: ApiPayment[] }).data ?? []
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openDetail(row: { raw: ApiPayment }): void {
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
    <h1 class="text-2xl font-bold tracking-tight text-content">Платежи</h1>
    <p class="mt-1 text-sm text-muted">
      <template v-if="loading">Загрузка…</template>
      <template v-else>{{ rows.length }} из {{ payments.length }}</template>
    </p>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить платежи: {{ error }}
    </div>

    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="status" :segments="STATUS_SEGMENTS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Заказ, e-mail или ID провайдера" icon="⌕" class="max-w-xs" aria-label="Поиск по платежам" />
      <NButton variant="ghost" size="sm" @click="reset">Сбросить</NButton>
    </div>

    <div class="mt-4">
      <NDataTable
        v-if="rows.length"
        :columns="COLUMNS"
        :rows="rows"
        sort="createdAt"
        sort-dir="desc"
        :loading="loading"
        @row="openDetail"
      >
        <template #cell-order="{ row }">
          <span class="block font-mono text-xs text-brand-400">{{ row.order }}</span>
          <span class="block text-2xs text-subtle">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-provider="{ row }">
          <span class="text-sm capitalize text-muted">{{ row.provider }}</span>
        </template>
        <template #cell-amount="{ row }">
          <span class="tabular-nums text-content">{{ money(row.amount) }}</span>
        </template>
        <template #cell-createdAt="{ row }">
          <span class="text-sm text-muted">{{ dateTime(row.createdAt) }}</span>
          <span v-if="row.paidAt" class="ml-1 text-subtle">→ {{ dateTime(row.paidAt) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="payment" :status="row.status" />
        </template>
        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.order }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ money(row.amount) }}</span>
          <NStatusBadge kind="payment" :status="row.status" />
        </template>
      </NDataTable>

      <NEmptyState
        v-else-if="!loading"
        class="surface-card"
        icon="◊"
        title="Платежей пока нет"
        description="Платежи появляются при оплате заказа на витрине."
      />
    </div>

    <NModal
      :open="detailOpen"
      title="Платёж"
      :description="detail ? `${detail.provider ?? '—'} · ${detail.order?.order_number ?? ''}` : ''"
      size="lg"
      @update:open="detailOpen = false"
    >
      <div v-if="detail" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Сумма</p>
            <p class="mt-0.5 text-lg font-semibold tabular-nums text-content">{{ money(detail.amount ?? 0) }}</p>
            <p class="mt-0.5 text-xs text-muted">{{ detail.currency ?? 'RUB' }} · {{ detail.provider ?? '—' }}</p>
          </div>
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Статус</p>
            <p class="mt-0.5"><NStatusBadge kind="payment" :status="detail.status ?? 'pending'" size="md" /></p>
            <p class="mt-0.5 text-xs text-muted">Создан: {{ dateTime(detail.created_at ?? '') }}</p>
            <p v-if="detail.paid_at" class="text-xs text-muted">Оплачен: {{ dateTime(detail.paid_at) }}</p>
          </div>
        </div>

        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between gap-3">
            <dt class="text-subtle">Покупатель</dt>
            <dd class="truncate text-content">{{ detail.order?.customer_name || detail.order?.customer_email || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-subtle">ID провайдера</dt>
            <dd class="truncate font-mono text-xs text-content">{{ detail.provider_payment_id ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-subtle">Ключ идемпотентности</dt>
            <dd class="truncate font-mono text-xs text-content">{{ detail.idempotency_key ?? '—' }}</dd>
          </div>
        </dl>

        <div v-if="detail.transactions?.length" class="rounded-lg border border-line bg-surface-2 p-3">
          <p class="mb-2 text-2xs uppercase tracking-wide text-subtle">Транзакции</p>
          <div v-for="(tx, i) in detail.transactions" :key="i" class="flex items-center justify-between gap-2 py-1 text-sm">
            <span class="text-content">{{ tx.status ?? '—' }}</span>
            <span class="text-xs text-subtle">{{ money(tx.amount ?? 0) }} · {{ dateTime(tx.created_at ?? '') }}</span>
          </div>
        </div>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="detailOpen = false">Закрыть</NButton>
      </template>
    </NModal>
  </div>
</template>
