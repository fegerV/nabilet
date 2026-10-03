<script setup lang="ts">
/**
 * Обзор организатора.
 *
 * Экран отвечает на три вопроса, и только на них: сколько продано, что требует
 * действий прямо сейчас, где деньги. Поэтому сверху — четыре метрики, затем —
 * долги и проблемы, и лишь потом график. Администратор не «любуется данными»,
 * он решает, что делать дальше.
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NButton from '@/components/ui/NButton.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { ORDERS } from '@/lib/mock'
import { money, relative, ticketsLabel } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

const router = useRouter()

const KPIS = [
  { label: 'Продажи за 7 дней', value: money(4218000), delta: '+18%', up: true },
  { label: 'Билетов продано', value: '312', delta: '+9%', up: true },
  { label: 'Средний чек', value: money(1352000), delta: '−3%', up: false },
  { label: 'Возвраты', value: money(294000), delta: '+2%', up: false },
]

/* График рисуется руками, без библиотеки: одна линия и подсветка под ней
   не стоят 40 КБ зависимости в админке. */
const SERIES = [18, 24, 21, 32, 29, 41, 38, 46, 43, 52, 61, 58]
const CHART_W = 720
const CHART_H = 180
const maxValue = Math.max(...SERIES) * 1.15

const points = computed(() =>
  SERIES.map((value, i) => {
    const x = (i / (SERIES.length - 1)) * CHART_W
    const y = CHART_H - (value / maxValue) * CHART_H
    return `${x.toFixed(1)},${y.toFixed(1)}`
  }),
)
const linePath = computed(() => `M ${points.value.join(' L ')}`)
const areaPath = computed(() => `M 0,${CHART_H} L ${points.value.join(' L ')} L ${CHART_W},${CHART_H} Z`)

const recent = computed(() => ORDERS.slice(0, 6))

const COLUMNS: Column[] = [
  { key: 'number', label: 'Заказ', sortable: true },
  { key: 'customer', label: 'Покупатель', sortable: true },
  { key: 'eventTitle', label: 'Событие', hideOnMobile: true },
  { key: 'seats', label: 'Билеты', align: 'right' },
  { key: 'totalMinor', label: 'Сумма', align: 'right', sortable: true },
  { key: 'status', label: 'Статус', align: 'right' },
]

const TASKS = [
  { id: 't1', text: 'Схема зала «Малый зал» не опубликована', action: 'Опубликовать', to: '/admin/halls', urgent: true },
  { id: 't2', text: 'Два заказа ждут оплаты больше часа', action: 'Проверить', to: '/admin/orders', urgent: false },
  { id: 't3', text: 'У сеанса 12 октября не заданы цены', action: 'Заполнить', to: '/admin/sessions', urgent: true },
  { id: 't4', text: 'Не подключён приём платежей', action: 'Настроить', to: '/admin/integrations', urgent: false },
]

function openOrder(): void {
  router.push('/admin/orders')
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Обзор</h1>
        <p class="mt-1 text-sm text-muted">Театр «Кукольный дом» · сентябрь 2026</p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary">Экспорт</NButton>
        <NButton variant="primary">Новое событие</NButton>
      </div>
    </div>

    <!-- Метрики -->
    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="kpi in KPIS" :key="kpi.label" class="surface-card p-4">
        <p class="text-xs uppercase tracking-wide text-subtle">{{ kpi.label }}</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums text-content">{{ kpi.value }}</p>
        <p class="mt-1 flex items-center gap-1 text-xs">
          <span :class="kpi.up ? 'text-mint-400' : 'text-rose-400'" aria-hidden="true">{{ kpi.up ? '↑' : '↓' }}</span>
          <span :class="kpi.up ? 'text-mint-400' : 'text-rose-400'">{{ kpi.delta }}</span>
          <span class="text-subtle">к прошлой неделе</span>
        </p>
      </div>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_380px]">
      <!-- График + последние заказы -->
      <div class="min-w-0 space-y-5">
        <section class="surface-card p-4">
          <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-content">Продажи по дням</h2>
            <NBadge tone="brand">Последние 12 дней</NBadge>
          </div>

          <svg
            :viewBox="`0 0 ${CHART_W} ${CHART_H}`"
            class="mt-4 h-44 w-full"
            preserveAspectRatio="none"
            role="img"
            aria-label="График продаж по дням: рост с 18 до 58 тысяч рублей"
          >
            <defs>
              <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#6D4AFF" stop-opacity="0.45" />
                <stop offset="100%" stop-color="#6D4AFF" stop-opacity="0" />
              </linearGradient>
            </defs>
            <path :d="areaPath" fill="url(#areaFill)" />
            <path :d="linePath" fill="none" stroke="#8E74FF" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />
          </svg>

          <div class="mt-2 flex justify-between text-2xs text-subtle">
            <span>10 сен</span><span>15 сен</span><span>20 сен</span><span>22 сен</span>
          </div>
        </section>

        <section>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-content">Последние заказы</h2>
            <NButton variant="ghost" size="sm" @click="router.push('/admin/orders')">Все заказы →</NButton>
          </div>

          <NDataTable :columns="COLUMNS" :rows="recent" @row="openOrder">
            <template #cell-number="{ row }">
              <span class="font-mono text-xs text-brand-400">{{ row.number }}</span>
              <span class="ml-2 text-2xs text-subtle">{{ relative(row.createdAt) }}</span>
            </template>
            <template #cell-customer="{ row }">
              <span class="block truncate text-content">{{ row.customer }}</span>
              <span class="block truncate text-2xs text-subtle">{{ row.email }}</span>
            </template>
            <template #cell-seats="{ row }">{{ ticketsLabel(row.seats) }}</template>
            <template #cell-totalMinor="{ row }">
              <span class="tabular-nums text-content">{{ money(row.totalMinor) }}</span>
            </template>
            <template #cell-status="{ row }">
              <NStatusBadge kind="order" :status="row.status" />
            </template>

            <template #mobile-title="{ row }">
              <span class="block text-sm font-medium text-content">{{ row.customer }}</span>
            </template>
            <template #mobile-meta="{ row }">
              <span>{{ row.number }}</span>
              <span>{{ ticketsLabel(row.seats) }}</span>
              <NStatusBadge kind="order" :status="row.status" />
            </template>
          </NDataTable>
        </section>
      </div>

      <!-- Что требует действий -->
      <aside class="min-w-0">
        <section class="surface-card overflow-hidden">
          <div class="border-b border-line px-4 py-3">
            <h2 class="text-sm font-semibold text-content">Требует внимания</h2>
            <p class="mt-0.5 text-xs text-subtle">То, что мешает продажам прямо сейчас</p>
          </div>
          <ul class="divide-y divide-line">
            <li v-for="task in TASKS" :key="task.id" class="flex items-center gap-3 px-4 py-3">
              <span
                :class="[
                  'h-1.5 w-1.5 flex-none rounded-full',
                  task.urgent ? 'bg-accent-500' : 'bg-sun-500',
                ]"
                aria-hidden="true"
              />
              <p class="min-w-0 flex-1 text-sm text-muted">{{ task.text }}</p>
              <NButton variant="ghost" size="sm" class="flex-none" @click="router.push(task.to)">
                {{ task.action }}
              </NButton>
            </li>
          </ul>
        </section>

        <section class="surface-card mt-4 p-4">
          <h2 class="text-sm font-semibold text-content">Каналы продаж</h2>
          <ul class="mt-3 space-y-2.5">
            <li v-for="ch in [
              { name: 'Сайт', share: 62, tone: 'bg-brand-500' },
              { name: 'Telegram', share: 24, tone: 'bg-sky-500' },
              { name: 'Embed на партнёре', share: 11, tone: 'bg-accent-500' },
              { name: 'Вручную', share: 3, tone: 'bg-mint-500' },
            ]" :key="ch.name">
              <div class="flex items-center justify-between text-xs">
                <span class="text-muted">{{ ch.name }}</span>
                <span class="tabular-nums text-content">{{ ch.share }}%</span>
              </div>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-3">
                <div :class="['h-full rounded-full', ch.tone]" :style="{ width: `${ch.share}%` }" />
              </div>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>
