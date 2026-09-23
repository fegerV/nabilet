<script setup lang="ts">
/**
 * Заказы.
 *
 * Рабочая лошадь админки. Три вещи делают её удобной:
 *  - фильтр по статусу — сегментами, потому что «покажи проблемные» — самый
 *    частый запрос, а он должен стоять одного клика;
 *  - строка открывает заказ целиком, а не только по ссылке в ячейке;
 *  - массовые действия появляются только когда что-то выбрано: плавающая
 *    панель не должна заслонять таблицу без дела.
 */
import { computed, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NModal from '@/components/ui/NModal.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { ORDERS } from '@/lib/mock'
import { money, relative, ticketsLabel, dateFull, time } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'
import type { OrderRow, OrderStatus } from '@/lib/types'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const status = ref<'all' | OrderStatus>('all')
const search = ref('')
const channel = ref('all')

const STATUS_SEGMENTS = [
  { value: 'all', label: 'Все' },
  { value: 'awaiting_payment', label: 'Ждут оплаты' },
  { value: 'paid', label: 'Оплаченные' },
  { value: 'payment_failed', label: 'Проблемы' },
  { value: 'refunded', label: 'Возвраты' },
]

const CHANNEL_OPTIONS = [
  { value: 'all', label: 'Все каналы' },
  { value: 'site', label: 'Сайт' },
  { value: 'telegram', label: 'Telegram' },
  { value: 'embed', label: 'Embed' },
  { value: 'admin', label: 'Вручную' },
]

const CHANNEL_LABEL: Record<OrderRow['channel'], string> = {
  site: 'Сайт',
  telegram: 'Telegram',
  embed: 'Embed',
  admin: 'Вручную',
}

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return ORDERS.filter((order) => {
    const byStatus = status.value === 'all' || order.status === status.value
    const byChannel = channel.value === 'all' || order.channel === channel.value
    const byQuery =
      !q ||
      order.number.toLowerCase().includes(q) ||
      order.customer.toLowerCase().includes(q) ||
      order.email.toLowerCase().includes(q)
    return byStatus && byChannel && byQuery
  })
})

const COLUMNS: Column[] = [
  { key: 'number', label: 'Заказ', sortable: true, width: '200px' },
  { key: 'customer', label: 'Покупатель', sortable: true },
  { key: 'eventTitle', label: 'Событие', hideOnMobile: true },
  { key: 'seats', label: 'Билеты', align: 'right', width: '90px' },
  { key: 'totalMinor', label: 'Сумма', align: 'right', sortable: true, width: '120px' },
  { key: 'channel', label: 'Канал', align: 'center', hideOnMobile: true, width: '110px' },
  { key: 'status', label: 'Статус', align: 'right', width: '150px' },
]

const detail = ref<OrderRow | null>(null)
const detailOpen = ref(false)

function openDetail(row: OrderRow): void {
  detail.value = row
  detailOpen.value = true
}

function refund(): void {
  detailOpen.value = false
  ui.notify('sun', 'Возврат запущен', 'Средства вернутся на карту в течение 3–10 дней')
}

function reset(): void {
  status.value = 'all'
  channel.value = 'all'
  search.value = ''
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Заказы</h1>
        <p class="mt-1 text-sm text-muted">{{ rows.length }} из {{ ORDERS.length }}</p>
      </div>
      <NButton variant="secondary">Выгрузить CSV</NButton>
    </div>

    <!-- Фильтры -->
    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NSegmented v-model="status" :segments="STATUS_SEGMENTS" size="sm" aria-label="Фильтр по статусу" />
      <NInput v-model="search" placeholder="Номер, имя или e-mail" icon="⌕" class="max-w-xs" aria-label="Поиск по заказам" />
      <NSelect v-model="channel" :options="CHANNEL_OPTIONS" size="sm" class="max-w-[11rem]" aria-label="Канал продаж" />
      <NButton variant="ghost" size="sm" @click="reset">Сбросить</NButton>
    </div>

    <div class="mt-4">
      <NDataTable
        v-if="rows.length"
        :columns="COLUMNS"
        :rows="rows"
        sort="createdAt"
        empty-title="Заказов не найдено"
        empty-description="Измените фильтры или сбросьте их"
        @row="openDetail"
      >
        <template #cell-number="{ row }">
          <span class="block font-mono text-xs text-brand-400">{{ row.number }}</span>
          <span class="block text-2xs text-subtle">{{ relative(row.createdAt) }}</span>
        </template>
        <template #cell-customer="{ row }">
          <span class="block truncate text-content">{{ row.customer }}</span>
          <span class="block truncate text-2xs text-subtle">{{ row.email }}</span>
        </template>
        <template #cell-eventTitle="{ row }">
          <span class="block truncate text-muted">{{ row.eventTitle }}</span>
          <span class="block truncate text-2xs text-subtle">{{ dateFull(row.sessionAt) }}, {{ time(row.sessionAt) }}</span>
        </template>
        <template #cell-seats="{ row }">{{ ticketsLabel(row.seats) }}</template>
        <template #cell-totalMinor="{ row }">
          <span class="tabular-nums text-content">{{ money(row.totalMinor) }}</span>
        </template>
        <template #cell-channel="{ row }">
          <span class="text-xs text-muted">{{ CHANNEL_LABEL[row.channel] }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="order" :status="row.status" />
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.customer }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span class="font-mono">{{ row.number }}</span>
          <span>{{ ticketsLabel(row.seats) }}</span>
          <span>{{ money(row.totalMinor) }}</span>
          <NStatusBadge kind="order" :status="row.status" />
        </template>
      </NDataTable>

      <NEmptyState
        v-else
        class="surface-card"
        icon="⌕"
        title="Ничего не найдено"
        description="Под текущие фильтры не подходит ни один заказ."
        action-label="Сбросить фильтры"
        @action="reset"
      />
    </div>

    <!-- Деталка заказа -->
    <NModal
      v-model:open="detailOpen"
      :title="detail ? `Заказ ${detail.number}` : 'Заказ'"
      :description="detail ? `${detail.eventTitle} · ${dateFull(detail.sessionAt)}, ${time(detail.sessionAt)}` : ''"
      size="lg"
    >
      <div v-if="detail" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Покупатель</p>
            <p class="mt-0.5 text-sm text-content">{{ detail.customer }}</p>
            <p class="mt-0.5 text-xs text-muted">{{ detail.email }}</p>
          </div>
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-2xs uppercase tracking-wide text-subtle">Канал</p>
            <p class="mt-0.5 text-sm text-content">{{ CHANNEL_LABEL[detail.channel] }}</p>
            <p class="mt-0.5 text-xs text-muted">{{ relative(detail.createdAt) }}</p>
          </div>
        </div>

        <dl class="space-y-2 text-sm">
          <div class="flex justify-between border-b border-line pb-2">
            <dt class="text-muted">{{ ticketsLabel(detail.seats) }}</dt>
            <dd class="tabular-nums text-content">{{ money(detail.totalMinor) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="font-medium text-content">Итого</dt>
            <dd class="text-lg font-semibold tabular-nums text-content">{{ money(detail.totalMinor) }}</dd>
          </div>
        </dl>

        <div class="flex items-center gap-2">
          <span class="text-xs text-subtle">Статус:</span>
          <NStatusBadge kind="order" :status="detail.status" size="md" />
        </div>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="detailOpen = false">Закрыть</NButton>
        <NButton variant="secondary">Перевыпустить билеты</NButton>
        <NButton variant="danger" @click="refund">Оформить возврат</NButton>
      </template>
    </NModal>
  </div>
</template>
