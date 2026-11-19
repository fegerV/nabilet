<script setup lang="ts">
/**
 * Бэкапы: список локальных копий и скачивание на компьютер.
 *
 * Важно: автоматическое создание и выгрузка на Яндекс.Диск идут cron-ом
 * (`backup:run` в 03:00), а не из веб-запроса — mysqldump живёт дольше любого
 * HTTP-таймаута. Поэтому здесь нет кнопки «Создать сейчас»: она обещала бы
 * работу, которую хостинг не гарантирует. Страница показывает статус последней
 * копии, список файлов и понятную инструкцию, как запустить бэкап вручную.
 */
import { onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import NBadge from '@/components/ui/NBadge.vue'
import { get } from '@/lib/api'
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

const files = ref<BackupFile[]>([])
const check = ref<BackupCheck | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const downloading = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [list, status] = await Promise.all([
      get<BackupFile[]>('/admin/backups'),
      get<unknown>('/admin/system/status'),
    ])
    files.value = list.data
    // system/status — плоский ответ, без обёртки `{ data }`.
    check.value = (status as { checks?: { last_backup?: BackupCheck } }).checks?.last_backup ?? null
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить список бэкапов'
  } finally {
    loading.value = false
  }
}

function modified(ts: number): string {
  return new Date(ts * 1000).toLocaleString('ru-RU')
}

function tone(s?: string): 'mint' | 'sun' | 'rose' {
  if (s === 'ok') return 'mint'
  if (s === 'warning') return 'sun'
  return 'rose'
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
    error.value = `Не удалось скачать ${name}: ${e instanceof Error ? e.message : String(e)}`
  } finally {
    downloading.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1000px]">
    <NPageHeader
      title="Бэкапы"
      subtitle="Локальные резервные копии: посмотреть статус и скачать файл на компьютер. Автоматическая выгрузка на Яндекс.Диск идёт по расписанию."
    />

    <p v-if="error" role="alert" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</p>

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
      <div class="mt-4 flex flex-wrap gap-2">
        <NButton variant="ghost" size="sm" :disabled="loading" @click="load">Обновить</NButton>
      </div>
    </section>

    <section class="surface-card mt-5">
      <header class="border-b border-line px-5 py-3">
        <h2 class="text-sm font-semibold text-content">Локальные копии ({{ files.length }})</h2>
        <p class="mt-0.5 text-xs text-subtle">Файлы из storage/app/backups — скачивание доступно только администратору.</p>
      </header>
      <p v-if="loading" class="px-5 py-6 text-sm text-subtle">Загрузка…</p>
      <div v-else-if="files.length === 0" class="px-5 py-8 text-center text-sm text-subtle">
        Локальных копий пока нет. Обычно это значит, что cron ещё не запускал `backup:run` или у копий включено удаление после выгрузки на Диск.
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

    <section class="surface-card mt-5 p-5 text-sm text-muted">
      <h2 class="text-sm font-semibold text-content">Как настроить автоматические бэкапы</h2>
      <ul class="mt-3 space-y-2">
        <li>1. В <code>.env</code> задайте <code>YANDEX_DISK_OAUTH_TOKEN</code> (OAuth-токен Яндекс.Диска) и, при необходимости, <code>YANDEX_DISK_BACKUPS_PATH</code>. Без токена команда <code>backup:run</code> завершится ошибкой — бэкап на том же диске не защищает от его отказа.</li>
        <li>2. В панели хостинга добавьте cron: <code>* * * * * /путь/к/php /путь/к/сайту/artisan schedule:run</code> (путь до PHP и каталог сайта уточните у провайдера). Расписание внутри приложения уже создаёт копию каждый день в 03:00 (<code>backup:run</code>).</li>
        <li>3. Для шифрования задайте <code>NABILET_BACKUP_ENCRYPTION_KEY</code>; без него копия уйдёт на Диск в открытом виде с предупреждением в логе.</li>
        <li>4. Разовый запуск вручную (по SSH): <code>php artisan backup:run --keep-local</code> — оставит файл и здесь, и на Диске. Ротация: <code>php artisan backup:rotate</code>.</li>
        <li>5. Проверка: на этой странице статус «Свежая», а в журнале «Логи» нет ошибок <code>Backup failed</code>. Подробная инструкция восстановления — в документации по развёртыванию, раздел про бэкапы.</li>
      </ul>
    </section>
  </div>
</template>
