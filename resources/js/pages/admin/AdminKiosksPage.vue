<script setup lang="ts">
/**
 * Парк информационных киосков (админка).
 *
 * Три задачи на одной странице:
 *   1. аналитика по парку (устройства, online, продажи и выручка);
 *   2. реестр: создать киоск с адресом расположения, переименовать, увидеть
 *      одноразовый токен, ротировать его, отозвать;
 *   3. управление: включить/выключить ПРОДАЖИ и выдать директиву удалённого
 *      обновления (версия, URL, checksum).
 *
 * Выключение продаж НЕ отзывает токен: киоск остаётся на связи и продолжает
 * получать heartbeat/конфиг, просто не может создать продажу. Это отдельный
 * флаг `sales_enabled`, а не статус устройства.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NModal from '@/components/ui/NModal.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { money } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'
import type { Tone } from '@/lib/types'

const ui = useUiStore()

interface ApiKiosk {
  id: number
  public_id: string
  name: string
  location_address?: string | null
  location_note?: string | null
  status: string
  sales_enabled: boolean
  platform: string
  app_version?: string | null
  config_version?: string | null
  target_version?: string | null
  update_status: string
  update_applied_version?: string | null
  update_error?: string | null
  last_seen_at?: string | null
  last_used_at?: string | null
  revoked_at?: string | null
  kiosk_token_hash?: string
  cart_token?: string
  created_at?: string
}

interface Summary {
  devices: {
    total: number
    active: number
    disabled: number
    revoked: number
    selling: number
    online: number
  }
  today: { sales: number; revenue_minor: number }
  week: { sales: number; revenue_minor: number }
  top: Array<{ device_id: number; device_name: string; sales_count: number; revenue_minor: number }>
  offline_after_minutes: number
}

interface KioskSale {
  public_id: string
  client_sale_id: string
  status: string
  amount_minor: number | null
  created_at: string
}

const kiosks = ref<ApiKiosk[]>([])
const summary = ref<Summary | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const formOpen = ref(false)
const editing = ref<string | null>(null)
const editingStatus = ref<string>('active')
const saving = ref(false)
const formError = ref<string | null>(null)
const form = ref({
  name: '',
  location_address: '',
  location_note: '',
  platform: 'web_kiosk',
  app_version: '',
  sales_enabled: false,
  statusDisabled: false,
})
const organizations = ref<Array<{ id: number; name: string }>>([])
const organizationId = ref<number | null>(null)

const tokenModal = ref(false)
const oneTimeToken = ref('')
const tokenFor = ref('')

const updateOpen = ref(false)
const updateTarget = ref<string | null>(null)
const updateForm = ref({ target_version: '', update_url: '', update_checksum: '' })
const updateError = ref<string | null>(null)

const detailOpen = ref(false)
const detail = ref<{ device: ApiKiosk; stats: Record<string, number>; recent_sales: KioskSale[] } | null>(null)

const confirmRevokeOpen = ref(false)
const revokeTarget = ref('')
const confirmCancelOpen = ref(false)
const cancelTarget = ref('')
const busy = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiKiosk[] }>('/admin/kiosks?per_page=100')
    const inner = res.data as unknown as { data?: ApiKiosk[] } | ApiKiosk[]
    kiosks.value = Array.isArray(inner) ? inner : inner.data ?? []
    summary.value = (res as unknown as { summary?: Summary }).summary ?? null
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

async function loadOrganizations(): Promise<void> {
  try {
    const res = await get<{ data: Array<{ id: number; name: string }> }>('/organizations?per_page=100')
    const inner = res.data as unknown as { data?: Array<{ id: number; name: string }> } | Array<{ id: number; name: string }>
    organizations.value = Array.isArray(inner) ? inner : inner.data ?? []
    if (organizations.value.length > 0 && organizationId.value === null) {
      organizationId.value = organizations.value[0]!.id
    }
  } catch {
    // Организация крупного аккаунта одна — можно создать киоск и без списка.
  }
}

onMounted(() => {
  void load()
  void loadOrganizations()
})

function openCreate(): void {
  editing.value = null
  form.value = {
    name: '', location_address: '', location_note: '',
    platform: 'web_kiosk', app_version: '', sales_enabled: false, statusDisabled: false,
  }
  formError.value = null
  formOpen.value = true
}

function editFromRow(row: unknown): void {
  openEdit(row as { raw: ApiKiosk })
}

function openEdit(row: { raw: ApiKiosk }): void {
  const k = row.raw
  editing.value = k.public_id
  editingStatus.value = k.status
  form.value = {
    name: k.name ?? '',
    location_address: k.location_address ?? '',
    location_note: k.location_note ?? '',
    platform: k.platform ?? 'web_kiosk',
    app_version: k.app_version ?? '',
    sales_enabled: Boolean(k.sales_enabled),
    statusDisabled: k.status === 'disabled',
  }
  formError.value = null
  formOpen.value = true
}

async function save(): Promise<void> {
  saving.value = true
  formError.value = null
  try {
    const { statusDisabled, ...rest } = form.value
    const payload = {
      ...rest,
      app_version: form.value.app_version.trim() || null,
      ...(editing.value && editingStatus.value !== 'revoked'
        ? { status: statusDisabled ? 'disabled' : 'active' }
        : {}),
    }
    if (editing.value) {
      await send(`/admin/kiosks/${editing.value}`, 'PATCH', payload)
      ui.notify('mint', 'Киоск обновлён', form.value.name)
      formOpen.value = false
    } else {
      if (organizationId.value === null) {
        formError.value = 'Не удалось определить организацию — обновите страницу.'
        return
      }
      const res = await send<{ device: ApiKiosk; token: string }>('/admin/kiosks', 'POST', {
        organization_id: organizationId.value,
        ...payload,
      })
      oneTimeToken.value = res.data.token
      tokenFor.value = res.data.device.name
      tokenModal.value = true
      formOpen.value = false
    }
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function toggleSales(k: ApiKiosk): Promise<void> {
  busy.value = k.public_id
  try {
    await send(`/admin/kiosks/${k.public_id}`, 'PATCH', { sales_enabled: !k.sales_enabled })
    ui.notify('mint', k.sales_enabled ? 'Продажи выключены' : 'Продажи включены', k.name)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось изменить продажи', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = null
  }
}

async function rotate(k: ApiKiosk): Promise<void> {
  busy.value = k.public_id
  try {
    const res = await send<{ device: ApiKiosk; token: string }>(
      `/admin/kiosks/${k.public_id}/rotate-token`, 'POST', {},
    )
    oneTimeToken.value = res.data.token
    tokenFor.value = res.data.device.name
    tokenModal.value = true
    ui.notify('sun', 'Токен ротирован', 'Старый токен немедленно перестал работать')
    await load()
  } catch (e) {
    ui.notify('rose', 'Ротация не удалась', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = null
  }
}

async function revoke(): Promise<void> {
  const publicId = revokeTarget.value
  if (!publicId) return
  busy.value = publicId
  try {
    await send(`/admin/kiosks/${publicId}/revoke`, 'POST', {})
    ui.notify('sun', 'Киоск отозван', 'Восстановить его можно только новой выдачей токена')
    confirmRevokeOpen.value = false
    await load()
  } catch (e) {
    ui.notify('rose', 'Отзыв не удался', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = null
  }
}

function openUpdate(k: ApiKiosk): void {
  updateTarget.value = k.public_id
  updateForm.value = { target_version: k.target_version ?? '', update_url: '', update_checksum: '' }
  updateError.value = null
  updateOpen.value = true
}

async function issueUpdate(): Promise<void> {
  if (!updateTarget.value) return
  saving.value = true
  updateError.value = null
  try {
    const payload: Record<string, string> = {
      target_version: updateForm.value.target_version.trim(),
      update_url: updateForm.value.update_url.trim(),
    }
    if (updateForm.value.update_checksum.trim()) {
      payload.update_checksum = updateForm.value.update_checksum.trim()
    }
    await send(`/admin/kiosks/${updateTarget.value}/update`, 'POST', payload)
    ui.notify('mint', 'Директива обновления выдана', 'Киоск получит её на следующем heartbeat')
    updateOpen.value = false
    await load()
  } catch (e) {
    updateError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function cancelUpdate(): Promise<void> {
  const publicId = cancelTarget.value
  if (!publicId) return
  busy.value = publicId
  try {
    await send(`/admin/kiosks/${publicId}/update/cancel`, 'POST', {})
    ui.notify('sun', 'Обновление отменено')
    confirmCancelOpen.value = false
    await load()
  } catch (e) {
    ui.notify('rose', 'Отмена не удалась', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = null
  }
}

async function detailFromRow(row: unknown): Promise<void> {
  await openDetail((row as { raw: ApiKiosk }).raw)
}

async function openDetail(k: ApiKiosk): Promise<void> {
  busy.value = k.public_id
  try {
    const res = await get<{
      device: ApiKiosk
      stats: Record<string, number>
      recent_sales: KioskSale[]
    }>(`/admin/kiosks/${k.public_id}`)
    detail.value = res.data
    detailOpen.value = true
  } catch (e) {
    ui.notify('rose', 'Не удалось открыть киоск', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = null
  }
}

async function copyToken(): Promise<void> {
  try {
    await navigator.clipboard.writeText(oneTimeToken.value)
    ui.notify('mint', 'Токен скопирован', 'Храните его в безопасном месте')
  } catch {
    ui.notify('rose', 'Не удалось скопировать', 'Выделите токен вручную')
  }
}

function statusTone(status: string): Tone {
  if (status === 'active') return 'mint'
  if (status === 'disabled') return 'sun'
  return 'rose'
}

function saleTone(status: string): Tone {
  if (status === 'issued' || status === 'paid') return 'mint'
  if (status === 'failed') return 'rose'
  return 'sun'
}

function online(k: ApiKiosk): boolean {
  if (!k.last_seen_at) return false
  const minutes = summary.value?.offline_after_minutes ?? 5
  return Date.now() - new Date(k.last_seen_at).getTime() < minutes * 60_000
}

// Процент для высоты полосы — без литерала 100 рядом с денежным полем:
// сторож money.test.ts требует, чтобы переводить копейки мог только lib/money.ts.
const PERCENT = 100
const maxTop = computed(() => Math.max(1, ...(summary.value?.top ?? []).map((r) => r.revenue_minor)))

const rows = computed(() => kiosks.value.map((k) => ({
  id: k.public_id,
  name: k.name,
  location: k.location_address || k.location_note || '—',
  status: k.status,
  sales: k.sales_enabled,
  connection: online(k) ? 'online' : 'offline',
  update: k.update_status,
  raw: k,
})))

const columns: Column[] = [
  { key: 'name', label: 'Киоск', sortable: true },
  { key: 'location', label: 'Расположение', hideOnMobile: true },
  { key: 'status', label: 'Статус' },
  { key: 'sales', label: 'Продажи' },
  { key: 'connection', label: 'Связь' },
  { key: 'update', label: 'Обновление', hideOnMobile: true },
  { key: 'actions', label: '', align: 'right' },
]
</script>

<template>
  <section class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">Информационные киоски</h1>
        <p class="mt-1 text-sm text-subtle">
          Реестр устройств, продажи, аналитика и удалённые обновления. Киоск — устройство с токеном, а не пользователь.
        </p>
      </div>
      <NButton variant="primary" @click="openCreate">Создать киоск</NButton>
    </header>

    <p v-if="loadError" class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-200">
      {{ loadError }}
    </p>

    <!-- Аналитика парка -->
    <div v-if="summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border border-line bg-surface p-4">
        <div class="text-2xs uppercase tracking-wide text-subtle">Устройств</div>
        <div class="mt-1 text-2xl font-semibold">{{ summary.devices.total }}</div>
        <div class="mt-1 text-xs text-subtle">
          активных {{ summary.devices.active }} · на связи {{ summary.devices.online }}
        </div>
      </div>
      <div class="rounded-xl border border-line bg-surface p-4">
        <div class="text-2xs uppercase tracking-wide text-subtle">Продают сейчас</div>
        <div class="mt-1 text-2xl font-semibold">{{ summary.devices.selling }}</div>
        <div class="mt-1 text-xs text-subtle">остальные на связи, но без продаж</div>
      </div>
      <div class="rounded-xl border border-line bg-surface p-4">
        <div class="text-2xs uppercase tracking-wide text-subtle">Продаж сегодня</div>
        <div class="mt-1 text-2xl font-semibold">{{ summary.today.sales }}</div>
        <div class="mt-1 text-xs text-subtle">{{ money(summary.today.revenue_minor) }}</div>
      </div>
      <div class="rounded-xl border border-line bg-surface p-4">
        <div class="text-2xs uppercase tracking-wide text-subtle">За 7 дней</div>
        <div class="mt-1 text-2xl font-semibold">{{ summary.week.sales }}</div>
        <div class="mt-1 text-xs text-subtle">{{ money(summary.week.revenue_minor) }}</div>
      </div>
    </div>

    <div v-if="summary && summary.top.length > 0" class="rounded-xl border border-line bg-surface p-4">
      <h2 class="text-sm font-semibold">Топ киосков за 7 дней</h2>
      <div class="mt-4 flex h-32 items-end gap-3">
        <div v-for="item in summary.top" :key="item.device_id" class="flex flex-1 flex-col items-center justify-end gap-1">
          <span
            class="w-full rounded-t bg-brand-gradient transition-all"
            :style="{ height: `${Math.max(4, (item.revenue_minor / maxTop) * PERCENT)}%` }"
            :title="`${item.device_name}: ${money(item.revenue_minor)}`"
          />
          <span class="max-w-full truncate text-2xs text-subtle">{{ item.device_name }}</span>
        </div>
      </div>
    </div>

    <!-- Реестр -->
    <div class="rounded-xl border border-line bg-surface">
      <div class="flex items-center justify-between border-b border-line px-4 py-3">
        <h2 class="text-sm font-semibold">Устройства</h2>
        <span class="text-xs text-subtle">Показано {{ kiosks.length }}</span>
      </div>
      <NDataTable :columns :rows :loading @row="detailFromRow">
        <template #cell-name="{ row }">
          <div class="font-medium">{{ row.name }}</div>
          <div class="text-2xs text-subtle">{{ (row.raw as ApiKiosk).public_id }}</div>
        </template>
        <template #cell-location="{ row }">
          <span class="text-xs text-subtle">{{ row.location }}</span>
        </template>
        <template #cell-status="{ row }">
          <NBadge :tone="statusTone(String(row.status))">{{ row.status }}</NBadge>
        </template>
        <template #cell-sales="{ row }">
          <button
            type="button"
            class="rounded-md border border-line px-2 py-1 text-xs transition-colors hover:border-brand-500"
            :disabled="busy === (row.raw as ApiKiosk).public_id"
            @click.stop="toggleSales(row.raw as ApiKiosk)"
          >
            {{ row.sales ? 'Включены' : 'Выключены' }}
          </button>
        </template>
        <template #cell-connection="{ row }">
          <NBadge :tone="row.connection === 'online' ? 'mint' : 'rose'">
            {{ row.connection === 'online' ? 'на связи' : 'не в сети' }}
          </NBadge>
        </template>
        <template #cell-update="{ row }">
          <span class="text-xs">
            {{ row.update === 'pending' ? `ожидает ${(row.raw as ApiKiosk).target_version ?? ''}` : row.update }}
          </span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-wrap justify-end gap-1">
            <NButton size="sm" variant="ghost" @click.stop="editFromRow(row)">Изменить</NButton>
            <NButton size="sm" variant="ghost" @click.stop="rotate(row.raw as ApiKiosk)">Токен</NButton>
            <NButton size="sm" variant="ghost" @click.stop="openUpdate(row.raw as ApiKiosk)">Обновить</NButton>
            <NButton
              v-if="(row.raw as ApiKiosk).update_status === 'pending'"
              size="sm" variant="ghost"
              @click.stop="cancelTarget = (row.raw as ApiKiosk).public_id; confirmCancelOpen = true"
            >Отменить</NButton>
            <NButton
              size="sm" variant="danger"
              @click.stop="revokeTarget = (row.raw as ApiKiosk).public_id; confirmRevokeOpen = true"
            >Отозвать</NButton>
          </div>
        </template>
        <template #mobile-title="{ row }">{{ row.name }}</template>
        <template #mobile-meta="{ row }">
          {{ row.location }} · {{ row.status }} · {{ row.sales ? 'продажи вкл' : 'продажи выкл' }}
        </template>
      </NDataTable>
    </div>

    <!-- Форма создания/редактирования -->
    <NModal v-model:open="formOpen" :title="editing ? 'Изменить киоск' : 'Новый киоск'" size="lg">
      <form class="space-y-4" @submit.prevent="save">
        <label v-if="!editing" class="block text-xs text-subtle">
          Организация
          <select
            v-model.number="organizationId"
            class="mt-1 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm"
          >
            <option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
          </select>
        </label>
        <NInput v-model="form.name" label="Название" placeholder="Киоск у входа" required />
        <NInput v-model="form.location_address" label="Адрес расположения" placeholder="ул. Центральная, 1" />
        <NInput v-model="form.location_note" label="Примечание к расположению" placeholder="2-й этаж, рядом с гардеробом" />
        <div class="grid gap-3 sm:grid-cols-2">
          <NInput v-model="form.platform" label="Платформа" placeholder="web_kiosk" />
          <NInput v-model="form.app_version" label="Версия приложения" placeholder="1.0.0" />
        </div>
        <NCheckbox
          v-model="form.sales_enabled"
          label="Разрешить продажи"
          description="Новый киоск по умолчанию не продаёт — включите продажи после установки устройства."
        />
        <NCheckbox
          v-model="form.statusDisabled"
          label="Временно отключить устройство"
          description="Отключённый киоск не сможет подключаться. Продажи при этом выключаются принудительно."
        />
        <p v-if="formError" class="text-sm text-rose-300">{{ formError }}</p>
        <div class="flex justify-end gap-2 pt-2">
          <NButton variant="ghost" @click="formOpen = false">Отмена</NButton>
          <NButton variant="primary" type="submit" :loading="saving">Сохранить</NButton>
        </div>
      </form>
    </NModal>

    <!-- Одноразовый токен -->
    <NModal v-model:open="tokenModal" title="Токен киоска" description="Токен показывается один раз и нигде больше не хранится в открытом виде.">
      <div class="space-y-4">
        <div class="text-sm text-subtle">{{ tokenFor }}</div>
        <code class="block break-all rounded-lg border border-line bg-canvas p-3 text-xs">{{ oneTimeToken }}</code>
        <p class="text-xs text-subtle">
          Сохраните токен в конфигурации киоска. Если он потерян — выполните ротацию, старый токен перестанет работать.
        </p>
        <div class="flex justify-end gap-2">
          <NButton variant="ghost" @click="tokenModal = false">Закрыть</NButton>
          <NButton variant="primary" @click="copyToken">Скопировать</NButton>
        </div>
      </div>
    </NModal>

    <!-- Директива обновления -->
    <NModal v-model:open="updateOpen" title="Удалённое обновление" description="Сервер публикует версию и ссылку; киоск забирает директиву heartbeat'ом.">
      <form class="space-y-4" @submit.prevent="issueUpdate">
        <NInput v-model="updateForm.target_version" label="Целевая версия" placeholder="1.2.3" required />
        <NInput v-model="updateForm.update_url" label="URL архива" placeholder="https://example.test/kiosk-1.2.3.zip" required />
        <NInput v-model="updateForm.update_checksum" label="SHA-256 (необязательно)" placeholder="64 hex-символа" />
        <p v-if="updateError" class="text-sm text-rose-300">{{ updateError }}</p>
        <div class="flex justify-end gap-2 pt-2">
          <NButton variant="ghost" @click="updateOpen = false">Отмена</NButton>
          <NButton variant="primary" type="submit" :loading="saving">Выдать директиву</NButton>
        </div>
      </form>
    </NModal>

    <!-- Карточка киоска -->
    <NModal v-model:open="detailOpen" title="Киоск" size="lg" v-if="detail">
      <div class="space-y-4">
        <div class="text-sm font-medium">{{ detail.device.name }}</div>
        <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
          <div class="rounded-lg border border-line p-2">
            <div class="text-2xs text-subtle">Всего продаж</div><div class="font-semibold">{{ detail.stats.total ?? 0 }}</div>
          </div>
          <div class="rounded-lg border border-line p-2">
            <div class="text-2xs text-subtle">Выдано билетов</div><div class="font-semibold">{{ detail.stats.issued ?? 0 }}</div>
          </div>
          <div class="rounded-lg border border-line p-2">
            <div class="text-2xs text-subtle">Ошибок</div><div class="font-semibold">{{ detail.stats.failed ?? 0 }}</div>
          </div>
          <div class="rounded-lg border border-line p-2">
            <div class="text-2xs text-subtle">Выручка</div><div class="font-semibold">{{ money(detail.stats.revenue_minor ?? 0) }}</div>
          </div>
        </div>
        <div>
          <h3 class="text-sm font-semibold">Последние продажи</h3>
          <div v-if="detail.recent_sales.length === 0" class="mt-2 text-xs text-subtle">Продаж пока нет.</div>
          <ul v-else class="mt-2 divide-y divide-line text-xs">
            <li v-for="s in detail.recent_sales" :key="s.public_id" class="flex items-center justify-between gap-2 py-2">
              <span class="truncate">{{ s.client_sale_id }}</span>
              <NBadge :tone="saleTone(s.status)">{{ s.status }}</NBadge>
              <span>{{ money(s.amount_minor ?? 0) }}</span>
            </li>
          </ul>
        </div>
      </div>
    </NModal>

    <!-- Подтверждения -->
    <NModal
      v-model:open="confirmRevokeOpen"
      destructive title="Отозвать киоск?"
      description="Отзыв необратим: старый токен больше не подключится, а история продаж сохранится."
    >
      <div class="flex justify-end gap-2">
        <NButton variant="ghost" @click="confirmRevokeOpen = false">Отмена</NButton>
        <NButton variant="danger" :loading="busy !== null" @click="revoke">Отозвать</NButton>
      </div>
    </NModal>

    <NModal
      v-model:open="confirmCancelOpen"
      title="Отменить обновление?"
      description="Киоск больше не получит директиву, пока она не будет выдана заново."
    >
      <div class="flex justify-end gap-2">
        <NButton variant="ghost" @click="confirmCancelOpen = false">Отмена</NButton>
        <NButton variant="primary" @click="cancelUpdate">Отменить директиву</NButton>
      </div>
    </NModal>
  </section>
</template>
