<script setup lang="ts">
/**
 * Аналитика продаж.
 *
 * Отдельного OLAP-сервиса в ядре нет, поэтому показатели считаются здесь из
 * заказов, платежей и билетов — этого достаточно для операционных вопросов
 * («сколько принесла неделя», «какая доля оплат проходит»). Как только
 * появится аналитический эндпоинт, расчёт уедет на сервер, а экран останется.
 *
 * График — обычные CSS-столбики: под одну диаграмму тащить chart-библиотеку
 * дороже, чем она стоит.
 */
import { computed, onMounted, ref } from 'vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { get } from '@/lib/api'
import { money, dateLong } from '@/lib/format'

interface ApiOrder {
  id: string
  status: string
  payment_status?: string | null
  total_amount?: number | null
  created_at?: string | null
  paid_at?: string | null
  items_count?: number
}

interface ApiPayment {
  id: string
  status?: string | null
  amount?: number | null
  provider?: string | null
  paid_at?: string | null
  created_at?: string | null
}

const orders = ref<ApiOrder[]>([])
const payments = ref<ApiPayment[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function unwrap<T>(path: string): Promise<T[]> {
  const res = await get<{ data: T[] }>(path)
  const inner = res.data as unknown as { data?: T[] } | T[]
  return Array.isArray(inner) ? inner : (inner as { data: T[] }).data ?? []
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [o, p] = await Promise.all([
      unwrap<ApiOrder>('/orders?per_page=200'),
      unwrap<ApiPayment>('/payments?per_page=200'),
    ])
    orders.value = o
    payments.value = p
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const paid = computed(() => orders.value.filter((o) => o.status === 'paid'))
const revenue = computed(() => paid.value.reduce((sum, o) => sum + (o.total_amount ?? 0), 0))
const avgCheck = computed(() => (paid.value.length ? revenue.value / paid.value.length : 0))

/** Доля успешных оплат: главный индикатор того, что деньги доходят. */
const successRate = computed(() => {
  const total = payments.value.length
  if (!total) return 0
  const ok = payments.value.filter((p) => p.status === 'succeeded').length
  return Math.round((ok / total) * 100)
})

/** Выручка по дням за последние 14 дней. */
const series = computed(() => {
  const days: Array<{ date: string; label: string; total: number; count: number }> = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  for (let i = 13; i >= 0; i -= 1) {
    const day = new Date(today)
    day.setDate(day.getDate() - i)
    days.push({ date: day.toISOString().slice(0, 10), label: dateLong(day.toISOString()), total: 0, count: 0 })
  }

  for (const order of paid.value) {
    const at = order.paid_at ?? order.created_at
    if (!at) continue
    const key = new Date(at).toISOString().slice(0, 10)
    const bucket = days.find((d) => d.date === key)
    if (bucket) {
      bucket.total += order.total_amount ?? 0
      bucket.count += 1
    }
  }

  return days
})

const maxTotal = computed(() => Math.max(1, ...series.value.map((d) => d.total)))

const CARDS = computed(() => [
  { label: 'Оплаченных заказов', value: String(paid.value.length), hint: `всего ${orders.value.length}` },
  { label: 'Выручка', value: money(revenue.value), hint: 'по оплаченным заказам' },
  { label: 'Средний чек', value: money(avgCheck.value), hint: 'выручка / заказы' },
  { label: 'Успешных оплат', value: `${successRate.value}%`, hint: `${payments.value.length} попыток` },
])

const statusRows = computed(() => {
  const map = new Map<string, number>()
  for (const order of orders.value) map.set(order.status, (map.get(order.status) ?? 0) + 1)
  return [...map.entries()].sort((a, b) => b[1] - a[1])
})

const STATUS_LABELS: Record<string, string> = {
  pending: 'Создан',
  awaiting_payment: 'Ждём оплаты',
  paid: 'Оплачен',
  payment_failed: 'Оплата не прошла',
  cancelled: 'Отменён',
  expired: 'Истёк',
  refunded: 'Возврат',
  partially_refunded: 'Частичный возврат',
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <h1 class="text-2xl font-bold tracking-tight text-content">Аналитика</h1>
    <p class="mt-1 text-sm text-muted">Продажи, оплата и состав заказов по всем мероприятиям.</p>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить данные: {{ error }}
    </div>

    <p v-else-if="loading" class="mt-5 text-sm text-subtle">Считаем…</p>

    <template v-else>
      <!-- Показатели -->
      <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="card in CARDS" :key="card.label" class="surface-card p-4">
          <p class="text-2xs uppercase tracking-wide text-subtle">{{ card.label }}</p>
          <p class="mt-1 text-2xl font-bold tabular-nums text-content">{{ card.value }}</p>
          <p class="mt-0.5 text-xs text-subtle">{{ card.hint }}</p>
        </div>
      </div>

      <!-- График -->
      <div class="surface-card mt-5 p-4 sm:p-5">
        <div class="flex items-baseline justify-between gap-3">
          <p class="text-sm font-semibold text-content">Выручка по дням</p>
          <p class="text-xs text-subtle">последние 14 дней</p>
        </div>

        <div class="mt-5 flex h-48 items-end gap-1.5">
          <div v-for="day in series" :key="day.date" class="group relative flex flex-1 flex-col items-center justify-end gap-1">
            <span class="text-2xs tabular-nums text-subtle opacity-0 transition-opacity group-hover:opacity-100">
              {{ money(day.total) }}
            </span>
            <span
              class="w-full rounded-t bg-brand-gradient transition-all duration-200"
              :style="{ height: `${Math.max(4, (day.total / maxTotal) * 100)}%` }"
              :title="`${day.label}: ${money(day.total)}`"
            />
            <span class="mt-1 text-2xs text-subtle">{{ day.date.slice(8) }}</span>
          </div>
        </div>
      </div>

      <!-- Состав заказов -->
      <div class="surface-card mt-5 p-4 sm:p-5">
        <p class="mb-3 text-sm font-semibold text-content">Заказы по статусам</p>
        <div v-if="statusRows.length" class="space-y-2">
          <div v-for="[status, count] in statusRows" :key="status" class="flex items-center gap-3">
            <span class="w-40 flex-none text-sm text-muted">{{ STATUS_LABELS[status] ?? status }}</span>
            <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-surface-3">
              <span
                class="block h-full rounded-full bg-brand-500"
                :style="{ width: `${Math.round((count / orders.length) * 100)}%` }"
              />
            </span>
            <span class="w-10 flex-none text-right text-sm tabular-nums text-content">{{ count }}</span>
          </div>
        </div>
        <NEmptyState
          v-else
          icon="▲"
          title="Пока нечего показывать"
          description="Появятся заказы — появятся и показатели."
        />
      </div>
    </template>
  </div>
</template>
