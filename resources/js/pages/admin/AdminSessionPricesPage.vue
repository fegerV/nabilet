<script setup lang="ts">
/**
 * Цены на ряды сеанса (админка).
 *
 * Источник списка рядов — инвентарь сеанса (GET /inventory?session_id=N):
 *   - сидячие места группируются по seat.row_id (hall_rows.id),
 *   - стоячие зоны — по standing_zone_id (standing_zones.id).
 * Применение цен — PATCH /inventory/sessions/{sessionId}/prices
 * (сервис не трогает холды и sold, синхронизирует открытые корзины).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { fetchInventory, type InventoryItem } from '@/lib/inventory'
import { money } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

interface ApiSession {
  id: number
  event_id: number
  hall_id: number
  schema_version_id?: number | null
  starts_at: string
  status: string
  sales_start_at?: string | null
  event?: { id: number; title: string } | null
  hall?: { id: number; name: string } | null
}

interface RowEntry {
  /** Ключ запроса: row_id (сидячие) или standing_zone_id (стоячие). */
  id: number
  kind: 'seat' | 'standing'
  label: string
  seatsCount: number
  /** Текущая цена ряда с сервера (минорные единицы). */
  currentMinor: number
  /** Введленная цена в рублях ('' — не менять). */
  priceRub: string
}

const sessionId = computed(() => Number(route.params.id))
const session = ref<ApiSession | null>(null)
const rows = ref<RowEntry[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const saving = ref(false)
const saveError = ref<string | null>(null)
/** После применения — сводка сервера (rows_updated / items_updated / …). */
const savedSummary = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  savedSummary.value = null
  try {
    const [sesRes, inv] = await Promise.all([
      get<{ data: ApiSession }>(`/sessions/${sessionId.value}`),
      fetchInventory(sessionId.value),
    ])
    session.value = sesRes.data

    // Группировка мест по рядам / стоячим зонам.
    const byKey = new Map<string, RowEntry>()
    for (const item of inv.items as InventoryItem[]) {
      const isStanding = item.type === 'standing' || item.standing_zone_id != null
      const keyId = isStanding ? Number(item.standing_zone_id ?? 0) : Number(item.seat?.row_id ?? 0)
      if (!keyId) continue
      const key = `${isStanding ? 'z' : 'r'}${keyId}`
      const price = Number(item.price_amount ?? 0)
      const existing = byKey.get(key)
      if (existing) {
        existing.seatsCount += 1
        // Если цены внутри ряда разошлись — показываем любую; редактирование выставит единую.
        continue
      }
      byKey.set(key, {
        id: keyId,
        kind: isStanding ? 'standing' : 'seat',
        label: isStanding
          ? String((item.metadata_json as { zone_name?: string } | null)?.zone_name ?? `Стоячая зона #${keyId}`)
          : `Ряд ${item.seat?.label ? item.seat.label.replace(/^.*ряд\s*/i, '') : keyId}`,
        seatsCount: 1,
        currentMinor: price,
        priceRub: '',
      })
    }
    rows.value = [...byKey.values()].sort(
      (a, b) => (a.kind === b.kind ? a.id - b.id : a.kind === 'seat' ? -1 : 1),
    )
    if (inv.truncated) {
      loadError.value = 'Инвентарь обрезан по лимиту страниц — список рядов может быть неполным.'
    }
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

const changedCount = computed(() => rows.value.filter((r) => r.priceRub.trim() !== '').length)

/** Ввод в рублях → минорные единицы; пустой ввод не участвует в запросе. */
function toMinor(value: string): number | null {
  const n = Number(String(value).replace(',', '.'))
  if (!Number.isFinite(n) || n < 0) return null
  return Math.round(n * 100)
}

const invalidEntries = computed(() =>
  rows.value.filter((r) => r.priceRub.trim() !== '' && toMinor(r.priceRub) === null),
)

async function apply(): Promise<void> {
  if (saving.value || changedCount.value === 0) return
  if (invalidEntries.value.length > 0) {
    saveError.value = 'Проверьте цены: нужно неотрицательное число (рубли, разделитель — точка или запятая).'
    return
  }
  saving.value = true
  saveError.value = null
  try {
    const payloadRows = rows.value
      .filter((r) => r.priceRub.trim() !== '')
      .map((r) => ({
        ...(r.kind === 'standing' ? { standing_zone_id: r.id } : { row_id: r.id }),
        price_amount: toMinor(r.priceRub) as number,
      }))
    const res = await send<{
      data: { rows_updated: number; items_updated: number; cart_items_updated: number }
    }>(`/inventory/sessions/${sessionId.value}/prices`, 'PATCH', { rows: payloadRows })
    const d = res.data
    savedSummary.value = `Обновлено рядов: ${d.rows_updated}, свободных мест: ${d.items_updated}, позиций в корзинах: ${d.cart_items_updated}.`
    ui.notify('mint', 'Цены применены', `Сеанс #${sessionId.value}: ${payloadRows.length} рядов`)
    await load()
  } catch (e) {
    saveError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

/** Быстрое заполнение всех полей текущими ценами (чтобы править от них). */
function fillCurrent(): void {
  for (const r of rows.value) r.priceRub = String(r.currentMinor / 100)
}

watch(() => route.params.id, () => void load())
onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[860px]">
    <div class="mb-5">
      <button type="button" class="text-sm text-muted hover:text-content" @click="router.push('/admin/sessions')">← Сеансы</button>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-content">
        Цены на ряды<span v-if="session?.event?.title"> — {{ session.event.title }}</span>
      </h1>
      <p v-if="session" class="mt-1 text-sm text-muted">
        Сеанс #{{ session.id }} · {{ session.hall?.name ?? `Зал #${session.hall_id}` }} · статус: {{ session.status }}
      </p>
    </div>

    <div v-if="loadError" class="surface-card mb-4 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ loadError }}</div>
    <div v-if="savedSummary" class="surface-card mb-4 border-mint-500/30 px-4 py-3 text-sm text-mint-400">{{ savedSummary }}</div>
    <div v-if="loading" class="surface-card px-4 py-6 text-center text-sm text-muted">Загрузка…</div>
    <div v-else-if="rows.length === 0" class="surface-card px-4 py-6 text-center text-sm text-muted">
      У сеанса нет инвентаря — сначала опубликуйте схему зала и создайте сеанс (места генерируются автоматически).
    </div>

    <form v-else class="surface-card space-y-3 p-5" @submit.prevent="apply">
      <div class="flex items-center justify-between">
        <span class="text-sm text-muted">Цены в рублях. Пустое поле — не менять. Холды и проданные билеты сохраняют свою цену.</span>
        <button type="button" class="text-sm text-brand-500 hover:underline" @click="fillCurrent">Подставить текущие</button>
      </div>

      <div
        v-for="r in rows"
        :key="`${r.kind}-${r.id}`"
        class="grid grid-cols-[1fr_auto] items-end gap-3 rounded-xl bg-surface-2/60 px-3.5 py-3"
      >
        <div>
          <div class="text-sm font-medium text-content">
            {{ r.label }}
            <span class="ml-1 text-xs text-subtle">({{ r.kind === 'standing' ? 'стоячая зона' : `${r.seatsCount} мест` }})</span>
          </div>
          <div class="text-xs text-muted tabular-nums">сейчас: {{ money(r.currentMinor) }}</div>
        </div>
        <NInput v-model="r.priceRub" label="Новая цена, ₽" type="number" min="0" step="0.01" placeholder="не менять" class="w-40" />
      </div>

      <div v-if="saveError" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ saveError }}</div>

      <div class="flex justify-end gap-2 border-t border-line pt-4">
        <NButton variant="secondary" @click="router.push('/admin/sessions')">Отмена</NButton>
        <NButton type="submit" variant="accent" :loading="saving" :disabled="changedCount === 0">
          Применить{{ changedCount ? ` (${changedCount})` : '' }}
        </NButton>
      </div>
    </form>
  </div>
</template>
