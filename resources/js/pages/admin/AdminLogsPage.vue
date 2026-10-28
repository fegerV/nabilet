<script setup lang="ts">
/**
 * Логи приложения.
 *
 * ДО ПОЯВЛЕНИЯ ЭКРАНА
 *   Логи лежали в `storage/logs/laravel-YYYY-MM-DD.log`, и единственным
 *   способом их посмотреть был SSH. Эндпоинт `GET /admin/system/logs` при этом
 *   существовал, но отдавал плоский список последних строк — без уровня, даты
 *   и поиска, то есть отвечал «что-то произошло», но не «что именно и по
 *   какому заказу».
 *
 * ЧТО ЗДЕСЬ ГЛАВНОЕ
 *   - единица списка — ЗАПИСЬ, а не строка: многострочный стектрейс не
 *     разваливается на независимые строки и раскрывается по клику;
 *   - фильтры (уровень, поиск, файл) выполняются на сервере: фильтровать в
 *     браузере последние 100 строк — значит искать ошибку, которой в этих ста
 *     строках нет;
 *   - `request_id` виден в строке — по нему поддержка находит все записи
 *     одного обращения, не листая файл.
 *
 * ЧЕГО ЗДЕСЬ СОЗНАТЕЛЬНО НЕТ
 *   Кнопки «очистить логи» и «скачать всё». Очистка — привилегированная
 *   операция с необратимыми последствиями, её место в `artisan`/cron; выгрузка
 *   целого файла из админки — это вынос всего содержимого лога в браузер, где
 *   в записях встречаются идентификаторы пользователей.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { request } from '@/lib/api'

interface LogEntry {
  date: string
  environment: string
  level: string
  message: string
  context: string
  trace: string
  request_id: string | null
}

interface LogFile {
  name: string
  size: number
  modified_at: string
  is_current: boolean
}

interface LogsResponse {
  file: string | null
  entries: LogEntry[]
  matched: number
  total_entries: number
  limit: number
  offset: number
  truncated: boolean
  levels_present: Record<string, number>
  levels: string[]
  files: LogFile[]
  message?: string
}

/** Уровни, которые показываются по умолчанию, когда фильтр пуст. */
const PAGE_SIZES = [50, 100, 200, 500]

const data = ref<LogsResponse | null>(null)
const loading = ref(true)
const refreshing = ref(false)
const error = ref<string | null>(null)

const file = ref<string | null>(null)
const levels = ref<string[]>([])
const search = ref('')
const lines = ref(100)
const offset = ref(0)

/** Поиск с задержкой: запрос на каждую букву — это чтение файла на каждую букву. */
let searchTimer: ReturnType<typeof setTimeout> | null = null
const appliedSearch = ref('')

/** Автообновление выключено по умолчанию: страница не должна сама себя дёргать. */
const autoRefresh = ref(false)
let refreshTimer: ReturnType<typeof setInterval> | null = null

/** Раскрытые записи — по индексу в текущей странице. */
const expanded = ref<Set<number>>(new Set())

const entries = computed<LogEntry[]>(() => data.value?.entries ?? [])
const files = computed<LogFile[]>(() => data.value?.files ?? [])

const range = computed(() => {
  const total = data.value?.matched ?? 0
  if (total === 0) return '0'
  const from = offset.value + 1
  const to = Math.min(offset.value + lines.value, total)
  return `${from}–${to} из ${total}`
})

const hasPrev = computed(() => offset.value > 0)
const hasNext = computed(() => offset.value + lines.value < (data.value?.matched ?? 0))

function query(): string {
  const params = new URLSearchParams()
  if (file.value) params.set('file', file.value)
  if (levels.value.length > 0) params.set('level', levels.value.join(','))
  if (appliedSearch.value.trim() !== '') params.set('search', appliedSearch.value.trim())
  params.set('lines', String(lines.value))
  params.set('offset', String(offset.value))
  return params.toString()
}

async function load(silent = false): Promise<void> {
  if (silent) refreshing.value = true
  else loading.value = true

  error.value = null

  try {
    const res = await request<LogsResponse>(`/admin/system/logs?${query()}`)
    data.value = res
    expanded.value = new Set()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

function selectFile(name: string): void {
  if (file.value === name) return
  file.value = name
}

function toggleLevel(level: string): void {
  const index = levels.value.indexOf(level)
  if (index === -1) levels.value.push(level)
  else levels.value.splice(index, 1)
}

function toggleEntry(index: number): void {
  const next = new Set(expanded.value)
  if (next.has(index)) next.delete(index)
  else next.add(index)
  expanded.value = next
}

function go(delta: number): void {
  offset.value = Math.max(0, offset.value + delta * lines.value)
  void load()
}

function levelClass(level: string): string {
  switch (level) {
    case 'EMERGENCY':
    case 'ALERT':
    case 'CRITICAL':
    case 'ERROR':
      return 'bg-rose-500/15 text-rose-400'
    case 'WARNING':
      // `amber` — цвет дефолтной палитры Tailwind: он рисуется, но обходит
      // семантику проекта. «Ожидание/внимание» здесь — `sun` (он же цвет
      // hold'а, таймера и статуса «Ждём оплаты»), поэтому предупреждение
      // должно выглядеть так же, как остальные предупреждения интерфейса.
      return 'bg-sun-500/15 text-sun-400'
    case 'NOTICE':
    case 'INFO':
      return 'bg-sky-500/15 text-sky-400'
    default:
      return 'bg-surface-2 text-muted'
  }
}

function formatSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} КБ`
  return `${bytes} Б`
}

/** Имя файла `laravel-2026-10-22.log` → `22.10.2026`. */
function fileLabel(name: string): string {
  const match = /(\d{4})-(\d{2})-(\d{2})/.exec(name)
  return match === null ? name : `${match[3]}.${match[2]}.${match[1]}`
}

function timeOf(date: string): string {
  // `2026-10-22 13:15:57` → `13:15:57`; дата и так известна из выбранного файла.
  const parts = date.split(' ')
  return parts.length > 1 ? parts[1] : date
}

function copy(entry: LogEntry): void {
  const text = `[${entry.date}] ${entry.environment}.${entry.level}: ${entry.message}`
  const full = entry.context === '' ? text : `${text}\n${entry.context}`
  void navigator.clipboard?.writeText(entry.trace === '' ? full : `${full}\n${entry.trace}`)
}

/*
 * Смена файла, уровней или размера страницы возвращает на первую страницу:
 * иначе `offset` от прежнего набора молча давал бы пустой экран.
 */
watch([levels, lines, file], () => {
  offset.value = 0
  void load()
}, { deep: true })

watch(search, (value) => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    appliedSearch.value = value
    offset.value = 0
    void load()
  }, 400)
})

watch(autoRefresh, (enabled) => {
  if (refreshTimer !== null) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
  if (enabled) refreshTimer = setInterval(() => { void load(true) }, 10_000)
})

onMounted(() => { void load() })

onBeforeUnmount(() => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  if (refreshTimer !== null) clearInterval(refreshTimer)
})
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold">Логи</h1>
        <p class="mt-1 text-sm text-muted">
          Записи приложения с уровнями, поиском и стектрейсами. Секреты и
          персональные данные вырезаны фильтром — в том числе в записях,
          сделанных до его появления.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <NButton variant="secondary" size="sm" :loading="refreshing" @click="load(true)">Обновить</NButton>
        <NButton
          :variant="autoRefresh ? 'primary' : 'secondary'"
          size="sm"
          @click="autoRefresh = !autoRefresh"
        >
          {{ autoRefresh ? 'Автообновление вкл.' : 'Автообновление' }}
        </NButton>
      </div>
    </header>

    <p v-if="error" class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ error }}</p>

    <!-- Файлы: канал daily пишет по файлу в сутки, поэтому «вчерашние» ошибки
         лежат не в текущем файле и без переключателя были бы недоступны. -->
    <section v-if="files.length > 0" class="flex flex-wrap gap-2">
      <button
        v-for="item in files"
        :key="item.name"
        type="button"
        class="rounded-lg border px-3 py-1.5 text-xs transition"
        :class="data?.file === item.name
          ? 'border-mint-500 bg-mint-500/10 text-mint-400'
          : 'border-line text-muted hover:border-mint-500/50'"
        @click="selectFile(item.name)"
      >
        {{ fileLabel(item.name) }}
        <span class="ml-1 opacity-70">{{ formatSize(item.size) }}</span>
      </button>
    </section>

    <section class="space-y-3 rounded-xl border border-line p-4">
      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-[240px] flex-1">
          <NInput v-model="search" label="Поиск по сообщению, контексту и стектрейсу" placeholder="номер заказа, request_id, текст ошибки" />
        </div>
        <label class="w-28 flex-none text-sm">
          <span class="mb-1 block text-muted">На странице</span>
          <select
            v-model.number="lines"
            class="w-full rounded-lg border border-line bg-surface px-2 py-2 text-sm"
          >
            <option v-for="size in PAGE_SIZES" :key="size" :value="size">{{ size }}</option>
          </select>
        </label>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-muted">Уровни:</span>
        <button
          v-for="level in (data?.levels ?? [])"
          :key="level"
          type="button"
          class="rounded-lg border px-2.5 py-1 text-xs transition"
          :class="levels.includes(level) ? levelClass(level) + ' border-transparent' : 'border-line text-muted hover:border-mint-500/50'"
          @click="toggleLevel(level)"
        >
          {{ level }}
          <span v-if="data?.levels_present[level]" class="ml-1 opacity-70">{{ data?.levels_present[level] }}</span>
        </button>
        <button
          v-if="levels.length > 0"
          type="button"
          class="text-xs text-muted underline"
          @click="levels = []; offset = 0"
        >
          сбросить
        </button>
      </div>
    </section>

    <div v-if="loading" class="text-sm text-muted">Загрузка…</div>

    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted">
        <span>
          Показано {{ range }} · записей в файле: {{ data?.total_entries ?? 0 }}
          <template v-if="data?.truncated"> · прочитан последний фрагмент файла</template>
        </span>
        <div class="flex gap-2">
          <NButton variant="secondary" size="sm" :disabled="!hasPrev" @click="go(-1)">Новее</NButton>
          <NButton variant="secondary" size="sm" :disabled="!hasNext" @click="go(1)">Старее</NButton>
        </div>
      </div>

      <p v-if="entries.length === 0" class="rounded-xl border border-line p-4 text-sm text-muted">
        {{ data?.message ?? 'Записей по выбранным условиям нет.' }}
      </p>

      <section v-else class="space-y-2">
        <article
          v-for="(entry, index) in entries"
          :key="`${entry.date}-${index}`"
          class="rounded-xl border border-line"
        >
          <div class="flex flex-wrap items-start gap-3 p-3">
            <span class="rounded px-2 py-0.5 text-[11px] font-semibold" :class="levelClass(entry.level)">
              {{ entry.level }}
            </span>
            <span class="font-mono text-xs text-muted">{{ timeOf(entry.date) }}</span>
            <div class="min-w-[200px] flex-1">
              <p class="text-sm break-words">{{ entry.message }}</p>
              <p class="mt-1 flex flex-wrap gap-3 text-[11px] text-muted">
                <span v-if="entry.request_id">request_id: <code>{{ entry.request_id }}</code></span>
                <span>окружение: {{ entry.environment }}</span>
              </p>
            </div>
            <div class="flex gap-2">
              <NButton variant="secondary" size="sm" @click="copy(entry)">Копировать</NButton>
              <NButton
                v-if="entry.context !== '' || entry.trace !== ''"
                variant="secondary"
                size="sm"
                @click="toggleEntry(index)"
              >
                {{ expanded.has(index) ? 'Свернуть' : 'Подробнее' }}
              </NButton>
            </div>
          </div>

          <div v-if="expanded.has(index)" class="space-y-2 border-t border-line p-3">
            <div v-if="entry.context !== ''">
              <div class="mb-1 text-[11px] uppercase tracking-wide text-muted">Контекст</div>
              <pre class="max-h-64 overflow-auto rounded-lg bg-surface-2 p-2 text-[11px] whitespace-pre-wrap break-all">{{ entry.context }}</pre>
            </div>
            <div v-if="entry.trace !== ''">
              <div class="mb-1 text-[11px] uppercase tracking-wide text-muted">Стектрейс</div>
              <pre class="max-h-64 overflow-auto rounded-lg bg-surface-2 p-2 text-[11px] whitespace-pre-wrap break-all">{{ entry.trace }}</pre>
            </div>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>
