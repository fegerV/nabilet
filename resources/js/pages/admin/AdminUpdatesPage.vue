<script setup lang="ts">
/**
 * Обновление системы в один клик.
 *
 * Тяжёлая работа делается сервером по шагам (тик cron), поэтому HTTP-запрос
 * здесь только создаёт запись, а экран опрашивает /status каждые 2 секунды.
 * Опасные действия требуют явной фразы подтверждения — как возврат в
 * AdminAgentPage требует REFUND, здесь это UPDATE и ROLLBACK.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { ApiError, get, send, upload } from '@/lib/api'
import { formatDateTimeLong, humanSize } from '@/lib/format'
import type { Tone } from '@/lib/types'
import { useUiStore } from '@/stores/ui'

interface AvailableUpdate {
  version: string
  released_at: string
  channel: string
  changelog: string
  from_versions: string[]
  size_bytes: number
}

interface CheckResponse {
  current_version: string
  available: AvailableUpdate | null
  reason?: string
  feed_configured: boolean
}

interface UploadResponse {
  file: string
  checksum: string
  size: number
}

interface StartResponse {
  update_id: number
  status: string
  message: string
}

interface UpdateStatus {
  update_id: number | null
  status: string
  step: string | null
  current_version: string | null
  target_version: string | null
  source: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  can_rollback: boolean
}

interface HistoryItem {
  id: number
  from_version: string
  to_version: string
  status: string
  source: string
  started_at: string | null
  finished_at: string | null
  error: string | null
}

/**
 * Системные эндпоинты исторически отвечают то плоским объектом
 * (/admin/system/status), то обёрткой { data } (ресурсные). Принимаем оба
 * вида: бэкенд обновлений может прийти с любой из этих форм, а UI не должен
 * показывать «нет данных» из-за расхождения в обёртке.
 */
type Wrapped<T> = T | { data: T }
function unwrap<T>(value: Wrapped<T>): T {
  if (value !== null && typeof value === 'object' && 'data' in value) {
    return (value as { data: T }).data
  }
  return value as T
}

/** GET /history отдаёт { data: [...] }, но допускаем и двойную обёртку. */
function historyRows(payload: unknown): HistoryItem[] {
  if (Array.isArray(payload)) return payload as HistoryItem[]
  const first = (payload as { data?: unknown } | null)?.data
  if (Array.isArray(first)) return first as HistoryItem[]
  const second = (first as { data?: unknown } | null)?.data
  return Array.isArray(second) ? second as HistoryItem[] : []
}

const TERMINAL = new Set(['done', 'failed', 'failed_after_swap', 'rolled_back', 'cancelled', 'idle'])
const ACTIVE = new Set(['checking', 'preparing', 'ready', 'applying', 'migrating', 'verifying', 'rolling_back'])
const CANCELLABLE = new Set(['preparing', 'ready'])

const STATUS_LABELS: Record<string, string> = {
  checking: 'Проверка ленты обновлений',
  preparing: 'Подготовка обновления',
  ready: 'Готово к установке',
  applying: 'Установка файлов',
  migrating: 'Применение миграций',
  verifying: 'Проверка после обновления',
  done: 'Обновление завершено',
  failed: 'Ошибка до подмены файлов — сайт не изменён',
  failed_after_swap: 'Ошибка после подмены файлов — сайт в режиме обслуживания, нужен откат',
  rolling_back: 'Откат к предыдущей версии',
  rolled_back: 'Откат завершён',
  cancelled: 'Обновление отменено',
  idle: 'Обновление не запускалось',
}

const STEP_LABELS: Record<string, string> = {
  backup: 'создание бэкапа',
  download: 'скачивание архива',
  checksum: 'проверка контрольной суммы',
  extract: 'распаковка в staging',
  preflight: 'предварительная проверка',
  swap: 'подмена каталогов',
  migrate: 'миграции',
  verify: 'проверка работоспособности',
}

const STAGES = [
  { label: 'Проверка' },
  { label: 'Подготовка' },
  { label: 'Готовность' },
  { label: 'Установка' },
  { label: 'Миграции' },
  { label: 'Проверка' },
  { label: 'Готово' },
]
const STAGE_INDEX: Record<string, number> = {
  checking: 0,
  preparing: 1,
  ready: 2,
  applying: 3,
  migrating: 4,
  verifying: 5,
  done: 6,
  failed: 1,
  failed_after_swap: 4,
}

const ui = useUiStore()

const check = ref<CheckResponse | null>(null)
const status = ref<UpdateStatus | null>(null)
const history = ref<HistoryItem[]>([])
const loading = ref(true)
const checking = ref(false)
const starting = ref(false)
const uploading = ref(false)
const cancelling = ref(false)
const rollingBack = ref(false)
const error = ref<string | null>(null)

const updateModalOpen = ref(false)
const rollbackModalOpen = ref(false)
const confirmPhrase = ref('')
const pendingSource = ref<'feed' | 'upload'>('feed')

const uploadFile = ref<File | null>(null)
const checksum = ref('')
const uploadVersion = ref('')
const uploaded = ref<UploadResponse | null>(null)

// Всё, что не терминальное, считаем активным: сервер может вернуть новый
// промежуточный статус, и опрос всё равно должен идти до завершения.
const active = computed(() => status.value !== null && !TERMINAL.has(status.value.status))
const canCancel = computed(() => status.value !== null && CANCELLABLE.has(status.value.status))
const canRollback = computed(() => status.value?.can_rollback === true && !active.value && !rollingBack.value)
const canStartFeed = computed(() => check.value?.available != null && !active.value && !starting.value)
const canStartUpload = computed(() => uploaded.value !== null && !active.value && !starting.value)

const checksumError = computed(() => {
  const value = checksum.value.trim()
  return value !== '' && !/^[0-9a-fA-F]{64}$/.test(value) ? 'Нужно ровно 64 шестнадцатеричных символа (SHA-256).' : ''
})

const stageIndex = computed(() => {
  if (!status.value) return -1
  return STAGE_INDEX[status.value.status] ?? -1
})

function statusLabel(s: UpdateStatus): string {
  const base = STATUS_LABELS[s.status] ?? `Неизвестное состояние: ${s.status}`
  if (s.status === 'preparing' && s.step) {
    return `${base}: ${STEP_LABELS[s.step] ?? s.step}`
  }
  return base
}

function statusTone(s: string): Tone {
  if (s === 'done' || s === 'rolled_back') return 'mint'
  if (s === 'failed' || s === 'failed_after_swap' || s === 'cancelled') return 'rose'
  if (ACTIVE.has(s)) return 'sun'
  return 'neutral'
}

function sourceLabel(s: string): string {
  return s === 'upload' ? 'загруженный архив' : 'лента обновлений'
}

function message(e: unknown, fallback: string): string {
  return e instanceof Error ? e.message : fallback
}

/* ── Загрузка данных ─────────────────────────────────────────────────────── */

async function loadCheck(): Promise<void> {
  checking.value = true
  try {
    const res = await get<Wrapped<CheckResponse>>('/admin/system/update/check')
    check.value = unwrap(res.data)
  } catch (e) {
    error.value = message(e, 'Не удалось проверить обновления')
  } finally {
    checking.value = false
  }
}

async function loadHistory(): Promise<void> {
  try {
    const res = await get<unknown>('/admin/system/update/history')
    history.value = historyRows(res.data)
  } catch {
    history.value = []
  }
}

async function refreshStatus(): Promise<void> {
  try {
    const res = await get<Wrapped<UpdateStatus>>('/admin/system/update/status')
    status.value = unwrap(res.data) ?? null
    if (status.value && TERMINAL.has(status.value.status)) {
      stopPolling()
      void loadCheck()
      void loadHistory()
    }
  } catch (e) {
    // 404 означает «активного обновления нет» — это нормальный покой, а не сбой.
    if (e instanceof ApiError && e.status === 404) {
      status.value = null
      stopPolling()
      return
    }
    // Сеть на пару тиков — не повод сбрасывать экран в ошибку.
    error.value = message(e, 'Не удалось получить статус обновления')
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  await Promise.all([loadCheck(), refreshStatus(), loadHistory()])
  loading.value = false
  if (active.value) startPolling()
}

/* ── Polling ─────────────────────────────────────────────────────────────── */

let pollTimer: ReturnType<typeof setInterval> | null = null
let pollInFlight = false

function startPolling(): void {
  if (!active.value || pollTimer !== null) return
  pollTimer = setInterval(() => { void pollOnce() }, 2000)
}

function stopPolling(): void {
  if (pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function pollOnce(): Promise<void> {
  if (!active.value || pollInFlight) return
  pollInFlight = true
  try {
    await refreshStatus()
  } finally {
    pollInFlight = false
  }
}

/* ── Действия ────────────────────────────────────────────────────────────── */

function openUpdate(source: 'feed' | 'upload'): void {
  pendingSource.value = source
  confirmPhrase.value = ''
  updateModalOpen.value = true
}

function openRollback(): void {
  confirmPhrase.value = ''
  rollbackModalOpen.value = true
}

async function confirmUpdate(): Promise<void> {
  if (confirmPhrase.value !== 'UPDATE') return

  const version = pendingSource.value === 'feed'
    ? (check.value?.available?.version ?? '')
    : (uploadVersion.value.trim() || check.value?.available?.version || '')
  if (!version) {
    ui.notify('sun', 'Не указана версия', 'Укажите версию архива или сначала проверьте ленту обновлений.')
    return
  }

  const body: { version: string; confirm_phrase: 'UPDATE'; source: 'feed' | 'upload'; file?: string } = {
    version,
    confirm_phrase: 'UPDATE',
    source: pendingSource.value,
  }
  if (pendingSource.value === 'upload' && uploaded.value) {
    body.file = uploaded.value.file
  }

  starting.value = true
  try {
    const res = await send<Wrapped<StartResponse>>('/admin/system/update/start', 'POST', body)
    const started = unwrap(res.data)
    status.value = {
      update_id: started.update_id,
      status: started.status,
      step: null,
      current_version: check.value?.current_version ?? null,
      target_version: version,
      source: pendingSource.value,
      error: null,
      started_at: null,
      finished_at: null,
      can_rollback: false,
    }
    updateModalOpen.value = false
    ui.notify('mint', 'Обновление запущено', started.message)
    startPolling()
    void refreshStatus()
    void loadHistory()
  } catch (e) {
    ui.notify('rose', 'Не удалось запустить обновление', message(e, 'Ошибка запроса'))
  } finally {
    starting.value = false
  }
}

async function confirmRollback(): Promise<void> {
  if (confirmPhrase.value !== 'ROLLBACK') return
  rollingBack.value = true
  try {
    const res = await send<Wrapped<{ update_id: number; status: string }>>('/admin/system/update/rollback', 'POST', {
      confirm_phrase: 'ROLLBACK',
    })
    const rollback = unwrap(res.data)
    status.value = {
      update_id: rollback.update_id,
      status: rollback.status,
      step: null,
      current_version: check.value?.current_version ?? null,
      target_version: null,
      source: 'feed',
      error: null,
      started_at: null,
      finished_at: null,
      can_rollback: false,
    }
    rollbackModalOpen.value = false
    ui.notify('sun', 'Откат запущен', 'Старый код возвращается; сайт остаётся в режиме обслуживания до окончания.')
    startPolling()
    void refreshStatus()
    void loadHistory()
  } catch (e) {
    ui.notify('rose', 'Не удалось откатить', message(e, 'Ошибка запроса'))
  } finally {
    rollingBack.value = false
  }
}

async function cancelUpdate(): Promise<void> {
  cancelling.value = true
  try {
    await send('/admin/system/update/cancel', 'POST', {})
    ui.notify('neutral', 'Обновление отменено', 'Подготовка остановлена, сайт не изменён.')
    await refreshStatus()
    await loadHistory()
  } catch (e) {
    ui.notify('rose', 'Не удалось отменить', message(e, 'Ошибка запроса'))
  } finally {
    cancelling.value = false
  }
}

function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  uploaded.value = null
  if (file && !file.name.toLowerCase().endsWith('.zip')) {
    uploadFile.value = null
    ui.notify('sun', 'Нужен zip-архив', 'Выберите самодостаточный архив обновления с расширением .zip.')
    return
  }
  uploadFile.value = file
}

async function uploadArchive(): Promise<void> {
  if (!uploadFile.value) {
    ui.notify('sun', 'Выберите архив', 'Сначала выберите zip-архив обновления.')
    return
  }
  if (!/^[0-9a-fA-F]{64}$/.test(checksum.value.trim())) {
    ui.notify('sun', 'Проверьте контрольную сумму', 'SHA-256 архива — ровно 64 шестнадцатеричных символа.')
    return
  }

  const form = new FormData()
  form.append('file', uploadFile.value)
  form.append('checksum', checksum.value.trim().toLowerCase())

  uploading.value = true
  try {
    const res = await upload<Wrapped<UploadResponse>>('/admin/system/update/upload', form)
    uploaded.value = unwrap(res.data)
    ui.notify('mint', 'Архив загружен', 'Теперь можно запустить обновление из архива.')
  } catch (e) {
    ui.notify('rose', 'Не удалось загрузить архив', message(e, 'Ошибка запроса'))
  } finally {
    uploading.value = false
  }
}

onMounted(() => {
  void load()
  startPolling()
})

onUnmounted(stopPolling)
</script>

<template>
  <div class="mx-auto max-w-[1100px] space-y-5">
    <NPageHeader
      title="Обновления системы"
      subtitle="Проверка ленты обновлений, установка в один клик, статус по шагам и откат к предыдущей версии."
      icon="↻"
    >
      <template #actions>
        <NButton variant="secondary" size="sm" :loading="checking" @click="loadCheck">Проверить</NButton>
        <NButton variant="primary" size="sm" :disabled="!canStartFeed" @click="openUpdate('feed')">Обновить</NButton>
      </template>
    </NPageHeader>

    <p v-if="error" role="alert" class="surface-card border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</p>

    <!-- Доступная версия -->
    <section class="surface-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-content">Версии</h2>
          <p class="mt-1 text-sm text-muted">Текущая версия берётся с сервера, а не из собранного фронтенда.</p>
        </div>
        <NBadge v-if="check" :tone="check.available ? 'mint' : 'neutral'">
          {{ check.available ? 'Доступно обновление' : 'Актуальная версия' }}
        </NBadge>
      </div>

      <div v-if="loading" class="py-6 text-sm text-subtle">Загрузка…</div>
      <template v-else-if="check">
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl border border-line bg-surface-2 px-4 py-3">
            <dt class="text-xs text-subtle">Установлена</dt>
            <dd class="mt-1 font-mono text-base font-semibold text-content">{{ check.current_version || '—' }}</dd>
          </div>
          <div class="rounded-xl border border-line bg-surface-2 px-4 py-3">
            <dt class="text-xs text-subtle">Доступна</dt>
            <dd class="mt-1 font-mono text-base font-semibold text-content">{{ check.available?.version ?? '—' }}</dd>
          </div>
          <div class="rounded-xl border border-line bg-surface-2 px-4 py-3">
            <dt class="text-xs text-subtle">Канал</dt>
            <dd class="mt-1 text-sm text-content">{{ check.available?.channel ?? '—' }}</dd>
          </div>
          <div class="rounded-xl border border-line bg-surface-2 px-4 py-3">
            <dt class="text-xs text-subtle">Дата выхода</dt>
            <dd class="mt-1 text-sm text-content">{{ check.available ? formatDateTimeLong(check.available.released_at) : '—' }}</dd>
          </div>
        </dl>

        <div v-if="check.available" class="mt-4 grid gap-4 sm:grid-cols-2">
          <div class="rounded-xl border border-line px-4 py-3">
            <p class="text-xs text-subtle">Размер архива</p>
            <p class="mt-1 text-sm font-medium text-content">{{ humanSize(check.available.size_bytes) }}</p>
            <p class="mt-1 text-xs text-subtle">
              Совместимо с: {{ check.available.from_versions.join(', ') || 'не указано' }}
            </p>
          </div>
          <div class="rounded-xl border border-line px-4 py-3">
            <p class="text-xs text-subtle">Лента обновлений</p>
            <p class="mt-1 text-sm text-content">
              {{ check.feed_configured ? 'Настроена' : 'Не настроена — используйте ручную загрузку ниже' }}
            </p>
          </div>
        </div>

        <div v-if="check.available?.changelog" class="mt-4">
          <p class="mb-1.5 text-sm font-medium text-content">Что изменилось</p>
          <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded-xl border border-line bg-surface-2 p-4 text-sm leading-relaxed text-muted">{{ check.available.changelog }}</pre>
        </div>
        <p v-else-if="check.reason" class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-muted">
          {{ check.reason }}
        </p>
        <p v-else class="mt-4 text-sm text-subtle">Обновлений нет.</p>
      </template>
    </section>

    <!-- Ход обновления -->
    <section class="surface-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-content">Ход обновления</h2>
          <p class="mt-1 text-sm text-muted">
            <template v-if="status">
              {{ statusLabel(status) }}
              <span v-if="status.target_version" class="text-subtle"> · цель: {{ status.target_version }}</span>
              <span v-if="status.source" class="text-subtle"> · источник: {{ sourceLabel(status.source) }}</span>
            </template>
            <template v-else>Обновление не запускалось.</template>
          </p>
        </div>
        <NBadge v-if="status" :tone="statusTone(status.status)" :dot="active">
          {{ STATUS_LABELS[status.status] ?? status.status }}
        </NBadge>
      </div>

      <ol v-if="status" class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <li
          v-for="(stage, index) in STAGES"
          :key="stage.label + index"
          class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs"
          :class="index <= stageIndex ? 'border-brand-500/40 bg-brand-500/10 text-content' : 'border-line text-subtle'"
        >
          <span class="grid h-5 w-5 flex-none place-items-center rounded-full border border-current text-2xs">{{ index + 1 }}</span>
          <span class="truncate">{{ stage.label }}</span>
        </li>
      </ol>

      <div v-if="status?.error" role="alert" class="mt-4 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-300">
        {{ status.error }}
      </div>

      <div v-if="status?.started_at || status?.finished_at" class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-subtle">
        <span v-if="status.started_at">Начато: {{ formatDateTimeLong(status.started_at) }}</span>
        <span v-if="status.finished_at">Завершено: {{ formatDateTimeLong(status.finished_at) }}</span>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <NButton v-if="canCancel" variant="secondary" size="sm" :loading="cancelling" @click="cancelUpdate">Отмена</NButton>
        <NButton v-if="canRollback" variant="danger" size="sm" @click="openRollback">Откатить</NButton>
        <NButton v-if="status && TERMINAL.has(status.status) && status.status !== 'rolled_back'" variant="secondary" size="sm" @click="refreshStatus">
          Обновить статус
        </NButton>
      </div>
      <p v-if="status?.status === 'failed_after_swap'" class="mt-3 text-xs leading-relaxed text-sun-400">
        База данных может быть частично изменена: откат возвращает только код. Перед возвратом продаж проверьте миграции и бэкап.
      </p>
    </section>

    <!-- Ручная загрузка архива -->
    <section class="surface-card p-5">
      <h2 class="text-base font-semibold text-content">Загрузка архива вручную</h2>
      <p class="mt-1 max-w-3xl text-sm text-muted">
        Запасной вариант, когда лента обновлений не настроена или сервер не выходит в интернет.
        Возьмите самодостаточный zip для хостинга и файл <code class="rounded bg-surface-2 px-1">.sha256</code> рядом с ним —
        контрольная сумма проверяется до распаковки. Без совпадения архив не будет принят.
      </p>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
          <label for="update-archive" class="mb-1.5 block text-sm font-medium text-content">Архив обновления (.zip)</label>
          <input
            id="update-archive"
            type="file"
            accept=".zip,application/zip"
            class="block w-full rounded-lg border border-line bg-surface text-sm text-content file:mr-3 file:h-10 file:border-0 file:bg-surface-3 file:px-3 file:text-sm file:text-content"
            @change="onFileChange"
          />
          <p class="mt-1.5 text-xs text-subtle">{{ uploadFile ? uploadFile.name : 'Файл ещё не выбран' }}</p>
        </div>
        <NInput
          v-model="checksum"
          label="Контрольная сумма SHA-256"
          placeholder="64 шестнадцатеричных символа"
          :error="checksumError"
          hint="Содержимое файла .sha256 — 64 символа 0–9 и a–f."
        />
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <NInput
          v-model="uploadVersion"
          label="Версия в архиве"
          placeholder="1.17.0"
          :hint="check?.available ? `По умолчанию: ${check.available.version} из ленты` : 'Версия из манифеста архива'"
        />
        <div class="flex items-end">
          <NButton variant="secondary" size="sm" :loading="uploading" @click="uploadArchive">Загрузить архив</NButton>
        </div>
      </div>

      <div v-if="uploaded" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-mint-500/30 bg-mint-500/10 px-4 py-3 text-sm">
        <span class="min-w-0 break-all font-mono text-xs text-content">{{ uploaded.file }}</span>
        <NBadge tone="mint">Загружен · {{ humanSize(uploaded.size) }}</NBadge>
        <NButton variant="primary" size="sm" :disabled="!canStartUpload" @click="openUpdate('upload')">Обновить из архива</NButton>
      </div>
    </section>

    <!-- История -->
    <section class="surface-card">
      <header class="border-b border-line px-5 py-3">
        <h2 class="text-sm font-semibold text-content">История обновлений ({{ history.length }})</h2>
      </header>
      <p v-if="loading" class="px-5 py-6 text-sm text-subtle">Загрузка…</p>
      <p v-else-if="history.length === 0" class="px-5 py-8 text-center text-sm text-subtle">Обновлений пока не было.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-surface-2 text-left text-xs uppercase tracking-wide text-subtle">
            <tr>
              <th class="p-3">Версии</th>
              <th class="p-3">Статус</th>
              <th class="p-3">Источник</th>
              <th class="p-3">Начало</th>
              <th class="p-3">Конец</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in history" :key="item.id" class="border-t border-line align-top">
              <td class="p-3 font-mono text-xs text-content">{{ item.from_version }} → {{ item.to_version }}</td>
              <td class="p-3">
                <NBadge :tone="statusTone(item.status)">{{ STATUS_LABELS[item.status] ?? item.status }}</NBadge>
                <p v-if="item.error" class="mt-1 max-w-md text-xs text-rose-400">{{ item.error }}</p>
              </td>
              <td class="p-3 text-xs text-subtle">{{ sourceLabel(item.source) }}</td>
              <td class="p-3 text-xs text-subtle">{{ item.started_at ? formatDateTimeLong(item.started_at) : '—' }}</td>
              <td class="p-3 text-xs text-subtle">{{ item.finished_at ? formatDateTimeLong(item.finished_at) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <NModal v-model:open="updateModalOpen" title="Подтвердить обновление" :destructive="true">
      <div class="space-y-4">
        <p class="text-sm text-content">
          Будет установлена версия <code class="rounded bg-surface-2 px-1">{{ pendingSource === 'feed' ? check?.available?.version : (uploadVersion || uploaded?.file) }}</code>
          из {{ pendingSource === 'feed' ? 'ленты обновлений' : 'загруженного архива' }}.
          Перед подменой файлов создаётся полный бэкап: на Яндекс.Диск, если он подключён, иначе локально на сервере. После установки запускаются миграции и проверка сайта.
        </p>
        <p class="rounded-xl border border-sun-500/30 bg-sun-500/10 px-4 py-3 text-sm text-sun-400">
          Не закрывайте страницу во время обновления — сайт может кратковременно уйти в режим обслуживания.
        </p>
        <NInput v-model="confirmPhrase" label="Для подтверждения введите UPDATE" placeholder="UPDATE" />
        <div class="flex justify-end gap-2">
          <NButton variant="ghost" size="sm" @click="updateModalOpen = false">Отмена</NButton>
          <NButton variant="danger" size="sm" :disabled="confirmPhrase !== 'UPDATE' || starting" :loading="starting" @click="confirmUpdate">
            Обновить
          </NButton>
        </div>
      </div>
    </NModal>

    <NModal v-model:open="rollbackModalOpen" title="Откатить обновление" :destructive="true">
      <div class="space-y-4">
        <p class="text-sm text-content">
          Будет восстановлен код предыдущей версии. База данных не откатывается автоматически:
          проверьте применённые миграции перед возвратом продаж.
        </p>
        <NInput v-model="confirmPhrase" label="Для подтверждения введите ROLLBACK" placeholder="ROLLBACK" />
        <div class="flex justify-end gap-2">
          <NButton variant="ghost" size="sm" @click="rollbackModalOpen = false">Отмена</NButton>
          <NButton variant="danger" size="sm" :disabled="confirmPhrase !== 'ROLLBACK' || rollingBack" :loading="rollingBack" @click="confirmRollback">
            Откатить
          </NButton>
        </div>
      </div>
    </NModal>
  </div>
</template>
