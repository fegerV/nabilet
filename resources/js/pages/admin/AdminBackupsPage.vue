<script setup lang="ts">
/**
 * Бэкапы: подключение Яндекс.Диска, запуск и скачивание копий.
 *
 * Тяжёлая работа (mysqldump, zip, загрузка) уходит в очередь — кнопка только
 * ставит задачу, а результат виден в статусе последнего запуска. Так страница
 * не умирает по таймауту шаред-хостинга.
 *
 * Токен OAuth приходит с Яндекса во фрагменте URL и обрабатывается отдельной
 * страницей /admin/backups/oauth; здесь токен НИКОГДА не отображается.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { get, send } from '@/lib/api'
import { useUiStore } from '@/stores/ui'
import { humanSize } from '@/lib/format'

interface BackupFile {
  name: string
  size: number
  modified_at: number
}

interface BackupCheck {
  status: 'ok' | 'warning' | 'error'
  message?: string
  last_backup?: string
  age_hours?: number
  file?: string
}

interface BackupSettings {
  client_id: string
  callback_url: string
  backups_path: string
  connected: boolean
  connected_from_db: boolean
  connected_at: string | null
  last_run: {
    status: string | null
    mode: string | null
    at: string | null
    message: string | null
  }
}

const MODES = [
  { value: 'db', label: 'Только база данных', hint: 'Ежедневно. Самое ценное: заказы, билеты, оплаты. Быстро и надёжно.' },
  { value: 'files', label: 'Только файлы', hint: 'Афиши, галереи, загруженные документы и конфигурация (.env — только при шифровании).' },
  { value: 'full', label: 'База + файлы', hint: 'Один архив со всем. Дольше, но восстанавливается одной копией.' },
] as const

type Mode = typeof MODES[number]['value']

const ui = useUiStore()

const files = ref<BackupFile[]>([])
const remote = ref<Array<{ name: string; path: string }>>([])
const check = ref<BackupCheck | null>(null)
const settings = ref<BackupSettings | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const selectedMode = ref<Mode>('db')
const starting = ref(false)
const testing = ref(false)
const downloading = ref<string | null>(null)
const clientIdDraft = ref('')

const connected = computed(() => settings.value?.connected ?? false)
const lastRun = computed(() => settings.value?.last_run ?? null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [list, status, cfg] = await Promise.all([
      get<BackupFile[]>('/admin/backups'),
      get<unknown>('/admin/system/status'),
      get<BackupSettings>('/admin/backups/settings'),
    ])
    files.value = list.data
    check.value = (status as { checks?: { last_backup?: BackupCheck } }).checks?.last_backup ?? null
    settings.value = cfg.data
    clientIdDraft.value = cfg.data.client_id
    if (connected.value) {
      void loadRemote()
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить данные бэкапов'
  } finally {
    loading.value = false
  }
}

async function loadRemote(): Promise<void> {
  try {
    const res = await get<Array<{ name: string; path: string }>>('/admin/backups/remote')
    remote.value = res.data
  } catch {
    remote.value = [] // удалённый список — не блокер страницы
  }
}

async function saveClientId(): Promise<void> {
  try {
    const res = await send<BackupSettings>('/admin/backups/yandex/client', 'POST', {
      client_id: clientIdDraft.value.trim(),
    })
    settings.value = res.data
    ui.notify('mint', 'ClientID сохранён', 'Теперь откройте ссылку подключения ниже.')
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить', e instanceof Error ? e.message : String(e))
  }
}

/** Implicit flow Яндекс OAuth: токен придёт во фрагменте на /admin/backups/oauth. */
async function connect(): Promise<void> {
  if (!clientIdDraft.value.trim()) {
    ui.notify('sun', 'Нужен ClientID', 'Сначала сохраните ClientID приложения Яндекс OAuth.')
    return
  }
  await saveClientId()
  const state = Array.from(crypto.getRandomValues(new Uint8Array(16)))
    .map((b) => b.toString(16).padStart(2, '0')).join('')
  sessionStorage.setItem('nabilet_yandex_oauth_state', state)
  const redirect = settings.value?.callback_url ?? `${window.location.origin}/admin/backups/oauth`
  const url = new URL('https://oauth.yandex.ru/authorize')
  url.searchParams.set('response_type', 'token')
  url.searchParams.set('client_id', clientIdDraft.value.trim())
  url.searchParams.set('redirect_uri', redirect)
  url.searchParams.set('state', state)
  window.location.assign(url.toString())
}

async function testConnection(): Promise<void> {
  testing.value = true
  try {
    const res = await get<{ ok: boolean; error: string | null }>('/admin/backups/yandex/test')
    if (res.data.ok) ui.notify('mint', 'Соединение работает', 'Токен принят Яндекс.Диском.')
    else ui.notify('rose', 'Проверка не прошла', res.data.error ?? 'Неизвестная ошибка')
  } catch (e) {
    ui.notify('rose', 'Проверка не прошла', e instanceof Error ? e.message : String(e))
  } finally {
    testing.value = false
  }
}

async function disconnect(): Promise<void> {
  if (!window.confirm('Отключить Яндекс.Диск? Запланированные бэкапы остановятся.')) return
  try {
    const res = await send<BackupSettings>('/admin/backups/yandex/disconnect', 'POST', {})
    settings.value = res.data
    remote.value = []
    ui.notify('sun', 'Диск отключён', 'Токен удалён из базы.')
  } catch (e) {
    ui.notify('rose', 'Не удалось отключить', e instanceof Error ? e.message : String(e))
  }
}

async function startBackup(): Promise<void> {
  starting.value = true
  try {
    const res = await send<{ message: string }>('/admin/backups/run', 'POST', { mode: selectedMode.value })
    ui.notify('mint', 'Бэкап в очереди', res.data.message)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось поставить задачу', e instanceof Error ? e.message : String(e))
  } finally {
    starting.value = false
  }
}

async function copy(text: string, label: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    ui.notify('mint', 'Скопировано', label)
  } catch {
    ui.notify('sun', 'Скопируйте вручную', text)
  }
}

function modified(ts: number): string {
  return new Date(ts * 1000).toLocaleString('ru-RU')
}

function tone(s?: string): 'mint' | 'sun' | 'rose' | 'neutral' {
  if (s === 'ok' || s === 'success') return 'mint'
  if (s === 'warning' || s === 'queued') return 'sun'
  if (s === 'error' || s === 'failed') return 'rose'
  return 'neutral'
}

async function download(name: string): Promise<void> {
  downloading.value = name
  try {
    const token = localStorage.getItem('nabilet_admin_token')
    const res = await fetch(`/api/v1/admin/backups/${encodeURIComponent(name)}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    ui.notify('rose', 'Не удалось скачать', e instanceof Error ? e.message : String(e))
  } finally {
    downloading.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1100px]">
    <NPageHeader
      title="Бэкапы"
      subtitle="Резервные копии в внешнем хранилище: подключение Яндекс.Диска, запуск, статус и скачивание."
    />

    <p v-if="error" role="alert" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</p>

    <!-- Подключение Яндекс.Диска -->
    <section class="surface-card mt-5 p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-content">Яндекс.Диск</h2>
          <p class="mt-1 text-sm text-muted">
            Внешнее хранилище — копия на том же сервере не защищает от его отказа.
          </p>
        </div>
        <NBadge :tone="connected ? 'mint' : 'rose'">
          {{ connected ? 'Подключён' : 'Не подключён' }}
        </NBadge>
      </div>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <NInput v-model="clientIdDraft" label="ClientID приложения Яндекс OAuth" placeholder="1234567890" />
        <div>
          <p class="mb-1.5 text-sm font-medium text-content">Redirect URI (вставьте в настройках приложения)</p>
          <div class="flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate rounded-lg bg-surface-2 px-3 py-2.5 text-xs text-content">
              {{ settings?.callback_url ?? '—' }}
            </code>
            <NButton variant="ghost" size="sm" @click="copy(settings?.callback_url ?? '', 'Redirect URI')">Копировать</NButton>
          </div>
        </div>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <NButton variant="primary" :disabled="starting" @click="connect">
          {{ connected ? 'Переподключить' : 'Подключить Диск' }}
        </NButton>
        <NButton variant="secondary" :disabled="testing || !connected" @click="testConnection">
          {{ testing ? 'Проверяем…' : 'Проверить соединение' }}
        </NButton>
        <NButton v-if="connected" variant="ghost" @click="disconnect">Отключить</NButton>
      </div>

      <details class="group mt-5 rounded-xl border border-line">
        <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-content">
          Пошаговая инструкция: как получить ClientID и токен
          <span class="float-right text-subtle transition group-open:rotate-180">▾</span>
        </summary>
        <ol class="space-y-2 border-t border-line px-4 py-4 text-sm leading-relaxed text-muted">
          <li>1. Откройте <a class="text-brand-300 underline" href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener">Яндекс OAuth → Создать приложение</a> и войдите в аккаунт Яндекса, под которым будет Диск.</li>
          <li>2. Назовите приложение (например, «NABILET бэкапы»), выберите «Веб-сервисы» и в поле <b>Callback URI</b> вставьте Redirect URI выше — точно, без slash на конце.</li>
          <li>3. В правах приложения включите доступ к <b>Яндекс.Диску</b> (чтение и запись файлов). Сохраните приложение.</li>
          <li>4. Скопируйте <b>ClientID</b> из карточки приложения в поле выше.</li>
          <li>5. Нажмите «Подключить Диск» — откроется страница Яндекса. Разрешите доступ; браузер вернётся сюда с токеном, который сохранится в зашифрованном виде.</li>
          <li>6. Проверьте соединение кнопкой «Проверить соединение».</li>
          <li>7. При желании задайте папку на Диске (настройка <code>YANDEX_DISK_BACKUPS_PATH</code> в .env, по умолчанию <code>/backups</code>).</li>
          <li>8. Официальная документация: <a class="text-brand-300 underline" href="https://yandex.ru/dev/disk/api/" target="_blank" rel="noopener">Yandex Disk API</a>, <a class="text-brand-300 underline" href="https://yandex.ru/dev/oauth/doc/dg/concepts/about.html" target="_blank" rel="noopener">Yandex OAuth</a>.</li>
        </ol>
      </details>
    </section>

    <!-- Создание бэкапа -->
    <section class="surface-card mt-5 p-5">
      <h2 class="text-base font-semibold text-content">Создать бэкап</h2>
      <p class="mt-1 text-sm text-muted">Задача уходит в очередь: страница не ждёт окончания mysqldump.</p>

      <div class="mt-4 space-y-2">
        <label v-for="m in MODES" :key="m.value"
               class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
               :class="selectedMode === m.value ? 'border-brand-500 bg-brand-500/10' : 'border-line hover:bg-surface-2'">
          <input v-model="selectedMode" type="radio" name="backup-mode" :value="m.value" class="mt-1 accent-brand-500" />
          <span>
            <span class="block text-sm font-medium text-content">{{ m.label }}</span>
            <span class="block text-xs text-subtle">{{ m.hint }}</span>
          </span>
        </label>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <NButton variant="primary" :disabled="!connected || starting" @click="startBackup">
          {{ starting ? 'Отправляем…' : 'Создать бэкап' }}
        </NButton>
        <span v-if="!connected" class="text-xs text-sun-400">Сначала подключите Яндекс.Диск</span>
      </div>

      <div v-if="lastRun?.status" class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm text-content">Последний запуск</span>
          <NBadge :tone="tone(lastRun.status)">{{ lastRun.status }}</NBadge>
          <span v-if="lastRun.mode" class="text-xs text-subtle">режим: {{ lastRun.mode }}</span>
          <span v-if="lastRun.at" class="text-xs text-subtle">{{ lastRun.at }}</span>
        </div>
        <p v-if="lastRun.message" class="mt-2 max-h-32 overflow-auto whitespace-pre-wrap text-xs text-muted">
          {{ lastRun.message }}
        </p>
      </div>

      <div class="mt-5 rounded-xl border border-line px-4 py-3 text-xs leading-relaxed text-subtle">
        <b class="text-content">Практика бэкапов (3-2-1):</b> три копии, на двух разных носителях, одна — вне сервера.
        Дамп БД — ежедневно (RPO ≈ сутки), полный архив — раз в неделю, ротация 7–30 копий.
        Шифрование задаётся ключом <code>NABILET_BACKUP_ENCRYPTION_KEY</code>; без него файл <code>.env</code> НЕ попадает в архив.
        Главное — регулярно проверять восстановление: бэкап, который ни разу не восстанавливали, считается отсутствующим.
      </div>
    </section>

    <!-- Статус и локальные копии -->
    <section class="surface-card mt-5 p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-content">Последняя резервная копия</h2>
          <p v-if="check?.last_backup" class="mt-1 text-xs text-subtle">
            {{ check.last_backup }} · {{ check.age_hours }} ч назад · {{ check.file }}
          </p>
          <p v-else class="mt-1 text-xs text-subtle">{{ check?.message ?? 'Сведения загружаются…' }}</p>
        </div>
        <NBadge :tone="tone(check?.status)">
          {{ check?.status === 'ok' ? 'Свежая' : check?.status === 'warning' ? 'Устарела' : 'Нет данных' }}
        </NBadge>
      </div>
      <div class="mt-4"><NButton variant="ghost" size="sm" @click="load">Обновить</NButton></div>
    </section>

    <section class="surface-card mt-5">
      <header class="border-b border-line px-5 py-3">
        <h2 class="text-sm font-semibold text-content">Локальные копии ({{ files.length }})</h2>
      </header>
      <p v-if="loading" class="px-5 py-6 text-sm text-subtle">Загрузка…</p>
      <div v-else-if="files.length === 0" class="px-5 py-8 text-center text-sm text-subtle">
        Локальных копий пока нет.
      </div>
      <ul v-else class="divide-y divide-line">
        <li v-for="f in files" :key="f.name" class="flex flex-wrap items-center gap-3 px-5 py-3">
          <span class="min-w-0 flex-1 truncate font-mono text-xs text-content">{{ f.name }}</span>
          <span class="text-xs text-subtle">{{ humanSize(f.size) }}</span>
          <span class="text-xs text-subtle">{{ modified(f.modified_at) }}</span>
          <NButton variant="secondary" size="sm" :disabled="downloading === f.name" @click="download(f.name)">
            {{ downloading === f.name ? 'Скачивание…' : 'Скачать' }}
          </NButton>
        </li>
      </ul>
    </section>

    <section v-if="remote.length" class="surface-card mt-5">
      <header class="border-b border-line px-5 py-3">
        <h2 class="text-sm font-semibold text-content">На Яндекс.Диске ({{ remote.length }})</h2>
      </header>
      <ul class="divide-y divide-line">
        <li v-for="r in remote" :key="r.path" class="px-5 py-2 font-mono text-xs text-muted">{{ r.name }}</li>
      </ul>
    </section>
  </div>
</template>
