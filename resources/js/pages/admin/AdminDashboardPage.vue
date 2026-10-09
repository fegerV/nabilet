<script setup lang="ts">
/**
 * Обзор организатора.
 *
 * Экран отвечает на три вопроса и только на них: сколько продано, что требует
 * действий прямо сейчас, где деньги. Поэтому сверху — метрики, затем проблемы,
 * и лишь потом график. Администратор не «любуется данными», он решает, что
 * делать дальше.
 *
 * Все цифры считаются из реальных заказов, платежей, билетов и мероприятий.
 * Раньше здесь лежали демонстрационные данные — на первом экране админки это
 * хуже, чем их отсутствие: по ним принимают решения о деньгах.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NButton from '@/components/ui/NButton.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import NCard from '@/components/ui/NCard.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { get } from '@/lib/api'
import { money, relative, dateLong, ticketsLabel } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

const router = useRouter()

interface ApiOrder {
  id: string
  order_number?: string | null
  status: string
  total_amount?: number | null
  customer_name?: string | null
  customer_email?: string | null
  created_at?: string | null
  items?: Array<{ quantity?: number; event_title_snapshot?: string | null; total_amount?: number | null }>
}

interface ApiPayment {
  id: string
  status?: string | null
  amount?: number | null
}

interface ApiEvent {
  id: string
  status?: string | null
  title?: string
}

interface ApiTicket {
  id: string
  status?: string | null
}

const loading = ref(true)
const error = ref<string | null>(null)
const orders = ref<ApiOrder[]>([])
const payments = ref<ApiPayment[]>([])
const events = ref<ApiEvent[]>([])
const tickets = ref<ApiTicket[]>([])

async function unwrap<T>(path: string): Promise<T[]> {
  const res = await get<{ data: T[] }>(path)
  const inner = res.data as unknown as { data?: T[] } | T[]
  return Array.isArray(inner) ? inner : (inner as { data: T[] }).data ?? []
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    // Независимые запросы — параллельно: обзор не должен ждать их по очереди.
    const [o, p, e, t] = await Promise.all([
      unwrap<ApiOrder>('/orders?per_page=200'),
      unwrap<ApiPayment>('/payments?per_page=200'),
      unwrap<ApiEvent>('/events?per_page=100'),
      unwrap<ApiTicket>('/tickets?per_page=200'),
    ])
    orders.value = o
    payments.value = p
    events.value = e
    tickets.value = t
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const DAY = 86400000
const now = Date.now()

function isPaid(order: ApiOrder): boolean {
  return order.status === 'paid'
}

/** Заказы в окне [from, to) дней от сегодня. */
function inWindow(order: ApiOrder, fromDaysAgo: number, toDaysAgo: number): boolean {
  const at = new Date(order.created_at ?? '').getTime()
  if (Number.isNaN(at)) return false
  const ageDays = (now - at) / DAY
  return ageDays >= toDaysAgo && ageDays < fromDaysAgo
}

function sumWindow(fromDaysAgo: number, toDaysAgo: number): number {
  return orders.value
    .filter((o) => isPaid(o) && inWindow(o, fromDaysAgo, toDaysAgo))
    .reduce((sum, o) => sum + (o.total_amount ?? 0), 0)
}

function countWindow(fromDaysAgo: number, toDaysAgo: number): number {
  return orders.value.filter((o) => isPaid(o) && inWindow(o, fromDaysAgo, toDaysAgo)).length
}

/**
 * Прирост в процентах: «к прошлой неделе» считаем по тому же окну.
 * `percent: false` — сравнивать не с чем (первая неделя продаж) либо
 * сравнение бессмысленно: стрелка «↓» рядом с «за всё время» врёт.
 */
function delta(current: number, previous: number): { text: string; up: boolean; percent: boolean } {
  if (previous === 0) {
    return current === 0
      ? { text: 'нет данных', up: true, percent: false }
      : { text: 'впервые', up: true, percent: false }
  }
  const pct = Math.round(((current - previous) / previous) * 100)
  return { text: `${pct >= 0 ? '+' : '−'}${Math.abs(pct)}%`, up: pct >= 0, percent: true }
}

const revenue7 = computed(() => sumWindow(7, 0))
const revenuePrev7 = computed(() => sumWindow(14, 7))
const paid7 = computed(() => countWindow(7, 0))
const paidPrev7 = computed(() => countWindow(14, 7))

const ticketsSold = computed(
  () => tickets.value.filter((t) => t.status === 'issued' || t.status === 'used').length,
)

const avgCheck = computed(() => (paid7.value ? revenue7.value / paid7.value : 0))

const refunds = computed(() =>
  orders.value
    .filter((o) => o.status === 'refunded' || o.status === 'partially_refunded')
    .reduce((sum, o) => sum + (o.total_amount ?? 0), 0),
)

const KPIS = computed(() => [
  {
    label: 'Продажи за 7 дней',
    value: money(revenue7.value),
    delta: delta(revenue7.value, revenuePrev7.value),
  },
  { label: 'Билетов продано', value: String(ticketsSold.value), delta: delta(paid7.value, paidPrev7.value) },
  { label: 'Средний чек', value: money(avgCheck.value), delta: delta(revenue7.value, revenuePrev7.value) },
  { label: 'Возвраты', value: money(refunds.value), delta: { text: 'за всё время', up: false, percent: false } },
])

/* ── График ──────────────────────────────────────────────────────────────
 * Рисуем руками, без библиотеки: одна линия и подсветка под ней не стоят
 * 40 КБ зависимости в админке. */
const CHART_W = 720
const CHART_H = 180

const series = computed(() => {
  const days: Array<{ label: string; total: number }> = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  for (let i = 11; i >= 0; i -= 1) {
    const day = new Date(today)
    day.setDate(day.getDate() - i)
    days.push({ label: dateLong(day.toISOString()), total: 0 })
  }
  // Границы суток считаем один раз: пересчитывать их на каждый заказ —
  // 200 лишних Date на рендер.
  const bounds = days.map((_, i) => {
    const start = new Date(today)
    start.setDate(start.getDate() - (11 - i))
    const end = new Date(start)
    end.setDate(end.getDate() + 1)
    return { from: start.getTime(), to: end.getTime() }
  })

  for (const order of orders.value) {
    if (!isPaid(order)) continue
    const at = new Date(order.created_at ?? '').getTime()
    if (Number.isNaN(at)) continue
    const index = bounds.findIndex((b) => at >= b.from && at < b.to)
    if (index >= 0) days[index]!.total += order.total_amount ?? 0
  }
  return days
})

const maxValue = computed(() => Math.max(1, ...series.value.map((d) => d.total)) * 1.15)

const points = computed(() =>
  series.value.map((day, i) => {
    const x = (i / Math.max(1, series.value.length - 1)) * CHART_W
    const y = CHART_H - (day.total / maxValue.value) * CHART_H
    return `${x.toFixed(1)},${y.toFixed(1)}`
  }),
)

const linePath = computed(() => `M ${points.value.join(' L ')}`)
const areaPath = computed(() => `M 0,${CHART_H} L ${points.value.join(' L ')} L ${CHART_W},${CHART_H} Z`)

/* ── Последние заказы ──────────────────────────────────────────────────── */

const recent = computed(() =>
  [...orders.value]
    .sort((a, b) => new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime())
    .slice(0, 6)
    .map((o) => ({
      id: o.id,
      number: o.order_number ?? o.id.slice(0, 8),
      customer: o.customer_name || o.customer_email || '—',
      email: o.customer_email ?? '',
      eventTitle: o.items?.[0]?.event_title_snapshot ?? '—',
      seats: (o.items ?? []).reduce((sum, item) => sum + (item.quantity ?? 1), 0),
      totalMinor: o.total_amount ?? 0,
      status: o.status,
      createdAt: o.created_at ?? '',
    })),
)

const COLUMNS: Column[] = [
  { key: 'number', label: 'Заказ', sortable: true },
  { key: 'customer', label: 'Покупатель', sortable: true },
  { key: 'eventTitle', label: 'Событие', hideOnMobile: true },
  { key: 'seats', label: 'Билеты', align: 'right' },
  { key: 'totalMinor', label: 'Сумма', align: 'right', sortable: true },
  { key: 'status', label: 'Статус', align: 'right' },
]

/* ── Требует внимания: считается из данных, а не выдумано ──────────────── */

const tasks = computed(() => {
  const list: Array<{ id: string; text: string; action: string; to: string; urgent: boolean }> = []

  const staleAwaiting = orders.value.filter((o) => {
    if (o.status !== 'awaiting_payment') return false
    const at = new Date(o.created_at ?? '').getTime()
    return !Number.isNaN(at) && now - at > 3600000
  }).length
  if (staleAwaiting) {
    list.push({
      id: 'awaiting',
      text: `${staleAwaiting} ${staleAwaiting === 1 ? 'заказ ждёт' : 'заказов ждут'} оплаты больше часа`,
      action: 'Проверить',
      to: '/admin/orders',
      urgent: true,
    })
  }

  const drafts = events.value.filter((e) => e.status === 'draft').length
  if (drafts) {
    list.push({
      id: 'drafts',
      text: `${drafts} ${drafts === 1 ? 'мероприятие' : 'мероприятий'} ещё в черновиках`,
      action: 'Опубликовать',
      to: '/admin/events',
      urgent: true,
    })
  }

  const failed = payments.value.filter((p) => p.status === 'failed').length
  if (failed) {
    list.push({
      id: 'failed-payments',
      text: `${failed} ${failed === 1 ? 'неудачный платёж' : 'неудачных платежей'}`,
      action: 'Разобрать',
      to: '/admin/payments',
      urgent: true,
    })
  }

  if (!payments.value.some((p) => p.status === 'succeeded')) {
    list.push({
      id: 'no-payments',
      text: 'Ни одного успешного платежа — проверьте приём денег',
      action: 'Настроить',
      to: '/admin/integrations',
      urgent: false,
    })
  }

  return list
})

/* ── Топ мероприятий по выручке ────────────────────────────────────────── */

const topEvents = computed(() => {
  const map = new Map<string, number>()
  for (const order of orders.value) {
    if (!isPaid(order)) continue
    for (const item of order.items ?? []) {
      const title = item.event_title_snapshot ?? 'Без названия'
      map.set(title, (map.get(title) ?? 0) + (item.total_amount ?? 0))
    }
  }
  const total = [...map.values()].reduce((sum, v) => sum + v, 0) || 1
  return [...map.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 5)
    .map(([title, value]) => ({ title, value, share: Math.round((value / total) * 100) }))
})

/** «октябрь 2026» — подпись к обзору, чтобы цифры были привязаны к моменту. */
const period = computed(() =>
  new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(new Date()),
)

/** Подпись к обзору: привязывает цифры к моменту, а в загрузке не врёт числом. */
const headerSubtitle = computed(() =>
  loading.value ? 'Загрузка данных…' : `${period.value} · ${orders.value.length} заказов в системе`,
)
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <NPageHeader title="Обзор" :subtitle="headerSubtitle">
      <template #actions>
        <NButton variant="secondary" @click="router.push('/admin/analytics')">Аналитика</NButton>
        <NButton variant="primary" @click="router.push('/admin/events/new')">Новое событие</NButton>
      </template>
    </NPageHeader>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить данные обзора: {{ error }}
    </div>

    <!-- Метрики -->
    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="kpi in KPIS" :key="kpi.label" class="surface-card p-4">
        <p class="text-xs uppercase tracking-wide text-subtle">{{ kpi.label }}</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums text-content">
          <span v-if="loading" class="skeleton inline-block h-7 w-24 align-middle" />
          <template v-else>{{ kpi.value }}</template>
        </p>
        <p class="mt-1 flex items-center gap-1 text-xs">
          <template v-if="kpi.delta.percent">
            <span :class="kpi.delta.up ? 'text-mint-400' : 'text-rose-400'" aria-hidden="true">
              {{ kpi.delta.up ? '↑' : '↓' }}
            </span>
            <span :class="kpi.delta.up ? 'text-mint-400' : 'text-rose-400'">{{ kpi.delta.text }}</span>
            <span class="text-subtle">к прошлой неделе</span>
          </template>
          <span v-else class="text-subtle">{{ kpi.delta.text }}</span>
        </p>
      </div>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_380px]">
      <div class="min-w-0 space-y-5">
      <NCard title="Продажи по дням" icon="▤">
        <template #actions>
          <NBadge tone="brand">Последние 12 дней</NBadge>
        </template>

        <svg
          :viewBox="`0 0 ${CHART_W} ${CHART_H}`"
          class="mt-4 h-44 w-full"
            preserveAspectRatio="none"
            role="img"
            aria-label="График продаж по дням"
          >
            <defs>
              <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="rgb(var(--brand-500))" stop-opacity="0.45" />
                <stop offset="100%" stop-color="rgb(var(--brand-500))" stop-opacity="0" />
              </linearGradient>
            </defs>
            <path :d="areaPath" fill="url(#areaFill)" />
            <path
              :d="linePath"
              fill="none"
              stroke="rgb(var(--brand-400))"
              stroke-width="2.5"
              stroke-linejoin="round"
              stroke-linecap="round"
            />
          </svg>

          <div class="mt-2 flex justify-between text-2xs text-subtle">
            <span>{{ series[0]?.label }}</span>
            <span>{{ series[Math.floor(series.length / 2)]?.label }}</span>
            <span>{{ series[series.length - 1]?.label }}</span>
          </div>
        </NCard>

        <section>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-content">Последние заказы</h2>
            <NButton variant="ghost" size="sm" @click="router.push('/admin/orders')">Все заказы →</NButton>
          </div>

          <NDataTable v-if="recent.length" :columns="COLUMNS" :rows="recent" :loading="loading" @row="router.push('/admin/orders')">
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

          <NEmptyState
            v-else-if="!loading"
            class="surface-card"
            icon="◫"
            title="Заказов пока нет"
            description="Как только покупатель оформит заказ, он появится здесь."
          />
        </section>
      </div>

      <aside class="min-w-0">
        <NCard title="Требует внимания" description="То, что мешает продажам прямо сейчас" padding="none">
          <ul v-if="tasks.length" class="divide-y divide-line">
            <li v-for="task in tasks" :key="task.id" class="flex items-center gap-3 px-4 py-3">
              <span
                :class="['h-1.5 w-1.5 flex-none rounded-full', task.urgent ? 'bg-accent-500' : 'bg-sun-500']"
                aria-hidden="true"
              />
              <p class="min-w-0 flex-1 text-sm text-muted">{{ task.text }}</p>
              <NButton variant="ghost" size="sm" class="flex-none" @click="router.push(task.to)">
                {{ task.action }}
              </NButton>
            </li>
          </ul>
          <p v-else class="px-4 py-6 text-sm text-subtle">
            <template v-if="loading">Проверяем…</template>
            <template v-else>Всё в порядке — срочных задач нет.</template>
          </p>
        </NCard>

        <NCard title="Мероприятия по выручке" class="mt-4">
          <ul v-if="topEvents.length" class="space-y-2.5">
            <li v-for="(item, index) in topEvents" :key="item.title">
              <div class="flex items-center justify-between gap-2 text-xs">
                <span class="min-w-0 truncate text-muted">{{ item.title }}</span>
                <span class="flex-none tabular-nums text-content">{{ money(item.value) }}</span>
              </div>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-3">
                <div
                  class="h-full rounded-full"
                  :class="index === 0 ? 'bg-brand-500' : index === 1 ? 'bg-sky-500' : 'bg-accent-500'"
                  :style="{ width: `${Math.max(3, item.share)}%` }"
                />
              </div>
            </li>
          </ul>
          <p v-else class="mt-2 text-xs text-subtle">Выручка появится после первых оплат.</p>
        </NCard>
      </aside>
    </div>
  </div>
</template>
