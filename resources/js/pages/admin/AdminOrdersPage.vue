<script setup lang="ts">
/**
 * Заказы (админка).
 *
 * Реальные данные: GET /api/v1/orders (пагинация). Фильтр по статусу,
 * поиск по номеру/имени/e-mail, деталка заказа (модалка), отмена заказа
 * (POST /{order}/cancel — админский endpoint).
 *
 * ФОРМА ОТВЕТА — СХЕМА `Order` КОНТРАКТА
 *   `GET /orders` отдаёт `OrderResource`: суммы вложены в `totals`, покупатель —
 *   плоскими `customer_email` / `customer_name` / `customer_phone`, позиции несут
 *   `event_title` (без суффикса `_snapshot`). Раньше страница читала
 *   `o.customer.email`, то есть форму ПРЕЖНЕГО `OrderResource`, которой сервер
 *   никогда не отдавал, — и колонка «Покупатель» показывала «—» в каждой строке.
 *   Ошибка была в форме ответа, а не в вёрстке (см. §3.28).
 */
import { computed, ref, onMounted } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NModal from '@/components/ui/NModal.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { money, dateTime } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

const ui = useUiStore()

/** Денежная часть заказа — схема `MoneyTotals` контракта (минорные единицы). */
interface ApiTotals {
  subtotal_amount?: number
  discount_amount?: number
  fee_amount?: number
  total_amount?: number
  currency?: string
}

interface ApiOrderItem {
  id?: string
  quantity?: number
  unit_price?: number
  total_amount?: number
  event_title?: string | null
}

/** Строка `GET /orders` — схема `Order` (`OrderResource`). */
interface ApiOrder {
  id: string
  order_number?: string | null
  status: string
  payment_status?: string | null
  totals?: ApiTotals | null
  customer_email?: string | null
  customer_name?: string | null
  customer_phone?: string | null
  items?: ApiOrderItem[]
  created_at?: string | null
  paid_at?: string | null
}

const orders = ref<ApiOrder[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const status = ref<'all' | string>('all')
const search = ref('')

const STATUS_SEGMENTS = [
  { value: 'all', label: 'Все' },
  { value: 'awaiting_payment', label: 'Ждут оплаты' },
  { value: 'paid', label: 'Оплаченные' },
  { value: 'payment_failed', label: 'Проблемы' },
  { value: 'refunded', label: 'Возвраты' },
]

const detail = ref<ApiOrder | null>(null)
const detailOpen = ref(false)
const cancelling = ref(false)

/** Имя покупателя. В таблице ОДНО `customer_name`, а не имя и фамилия отдельно. */
function customerName(o: ApiOrder): string {
  return o.customer_name || o.customer_email || o.customer_phone || '—'
}

function customerContact(o: ApiOrder): string {
  return o.customer_email || o.customer_phone || ''
}

function totalMinor(o: ApiOrder): number {
  return o.totals?.total_amount ?? 0
}

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return orders.value
    .filter((o) => {
      const byStatus = status.value === 'all' || o.status === status.value
      const byQuery =
        !q ||
        (o.order_number ?? '').toLowerCase().includes(q) ||
        customerName(o).toLowerCase().includes(q) ||
        customerContact(o).toLowerCase().includes(q)
      return byStatus && byQuery
    })
    .map((o) => ({
      id: o.id,
      number: o.order_number ?? o.id.slice(0, 8),
      customer: customerName(o),
      contact: customerContact(o),
      total: totalMinor(o),
      status: o.status,
      createdAt: o.created_at ?? '',
      raw: o,
    }))
})

const COLUMNS: Column[] = [
  { key: 'number', label: 'Заказ', sortable: true, width: '200px' },
  { key: 'customer', label: 'Покупатель', sortable: true },
  { key: 'total', label: 'Сумма', align: 'right', sortable: true, width: '120px' },
  { key: 'createdAt', label: 'Создан', hideOnMobile: true },
  { key: 'status', label: 'Статус', align: 'right', width: '150px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiOrder[] }>('/orders?per_page=100')
    const inner = res.data as unknown as { data?: ApiOrder[] } | ApiOrder[]
    orders.value = Array.isArray(inner) ? inner : (inner as { data: ApiOrder[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openDetail(row: { raw: ApiOrder }): void {
  detail.value = row.raw
  detailOpen.value = true
}

function reset(): void {
  status.value = 'all'
  search.value = ''
}

async function cancelOrder(): Promise<void> {
  if (!detail.value || cancelling.value) return
  if (!window.confirm('Отменить заказ? Если он оплачен — будет инициирован возврат.')) return
  cancelling.value = true
  try {
    await send<unknown>(`/orders/${detail.value.id}/cancel`, 'POST')
    ui.notify('sun', 'Заказ отменён', detail.value.order_number ?? detail.value.id)
    detailOpen.value = false
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось отменить', e instanceof Error ? e.message : String(e))
  } finally {
    cancelling.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Заказы</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} из {{ orders.length }}</template>
        </p>
      </div>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить заказы: {{ loadError }}
    </div>

    <!-- Фильтры -->
    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="status" :segments="STATUS_SEGMENTS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Номер, имя или e-mail" icon="⌕" class="max-w-xs" aria-label="Поиск по заказам" />
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
        <template #cell-number="{ row }">
          <span class="block font-mono text-xs text-brand-400">{{ row.number }}</span>
          <span class="block text-2xs text-subtle">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-customer="{ row }">
          <span class="block truncate text-content">{{ row.customer }}</span>
          <span class="block truncate text-2xs text-subtle">{{ row.contact }}</span>
        </template>
        <template #cell-total="{ row }">
          <span class="tabular-nums text-content">{{ money(row.total) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="order" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.customer }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span class="font-mono">{{ row.number }}</span>
          <span>{{ money(row.total) }}</span>
          <NStatusBadge kind="order" :status="row.status" />
        </template>
      </NDataTable>

      <NEmptyState
        v-else-if="!loading"
        class="surface-card"
        icon="⌕"
        title="Заказов пока нет"
        description="Как только покупатель оформит заказ на витрине, он появится здесь."
      />
    </div>

    <!-- Деталка заказа -->
    <NModal
      :open="detailOpen"
      :title="`Заказ ${detail?.order_number ?? detail?.id ?? ''}`"
      :description="detail?.payment_status ? `Оплата: ${detail.payment_status}` : ''"
      size="lg"
      @update:open="detailOpen = false"
    >
      <div v-if="detail" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Покупатель</p>
            <p class="mt-0.5 text-sm text-content">{{ customerName(detail) }}</p>
            <p class="mt-0.5 text-xs text-muted">{{ customerContact(detail) }}</p>
          </div>
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Статус</p>
            <p class="mt-0.5"><NStatusBadge kind="order" :status="detail.status" size="md" /></p>
            <p class="mt-0.5 text-xs text-muted">Создан: {{ dateTime(detail.created_at ?? '') }}</p>
          </div>
        </div>

        <div v-if="detail.items?.length" class="rounded-lg border border-line bg-surface-2 p-3">
          <p class="mb-1 text-2xs uppercase tracking-wide text-subtle">Состав заказа</p>
          <div v-for="(item, i) in detail.items" :key="i" class="flex items-baseline justify-between gap-2 py-1 text-sm">
            <span class="text-content">{{ item.event_title ?? `Позиция #${item.id ?? ''}` }}</span>
            <span class="tabular-nums text-muted">{{ item.quantity ?? 1 }} × {{ money(item.unit_price ?? 0) }}</span>
          </div>
        </div>

        <dl class="space-y-2 text-sm">
          <div class="flex justify-between">
            <dt class="font-medium text-content">Итого</dt>
            <dd class="text-lg font-semibold tabular-nums text-content">{{ money(totalMinor(detail)) }}</dd>
          </div>
        </dl>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="detailOpen = false">Закрыть</NButton>
        <NButton
          v-if="detail && !['cancelled', 'refunded', 'expired'].includes(detail.status)"
          variant="danger"
          :loading="cancelling"
          @click="cancelOrder"
        >
          Отменить заказ
        </NButton>
      </template>
    </NModal>
  </div>
</template>