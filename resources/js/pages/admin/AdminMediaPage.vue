<script setup lang="ts">
/**
 * Файлы (медиатека).
 *
 * Сетка, а не таблица: у файла главное — как он выглядит. Превью квадратное,
 * страницы подгружаются по мере прокрутки (IntersectionObserver), а счётчик
 * «Показано X из Y» всегда говорит, сколько файлов уже на экране.
 *
 * Папки — виртуальная группировка записей media_assets.folder_id. Перемещение
 * не меняет путь файла на диске, поэтому не ломает ссылки афиш и галерей.
 * Где используется файл — видно до удаления (поле links из API).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import { useUiStore } from '@/stores/ui'
import { request, send, upload } from '@/lib/api'
import type { PageMeta } from '@/lib/api'
import {
  MEDIA_ACCEPT,
  MEDIA_TYPES_LABEL,
  type MediaAsset,
  type MediaKind,
  assetSrc,
  dimensionsLabel,
  humanSize,
  kindOf,
  usageLabels,
  validateMediaFile,
} from '@/lib/mediaLibrary'
import { formatDate } from '@/lib/format'

interface MediaFolder {
  id: number
  public_id: string
  parent_id: number
  name: string
  files_count: number
}

const PER_PAGE = 24

const ui = useUiStore()

const items = ref<MediaAsset[]>([])
const folders = ref<MediaFolder[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const loadError = ref<string | null>(null)

const currentFolder = ref<number | null>(null)
const search = ref('')
const kind = ref('all')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)

const selected = ref<Set<number>>(new Set())
const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const uploading = ref(false)
const uploadProgress = ref({ done: 0, total: 0 })
const uploadErrors = ref<string[]>([])
const busyId = ref<number | null>(null)
const sentinel = ref<HTMLElement | null>(null)

const editOpen = ref(false)
const editing = ref<MediaAsset | null>(null)
const saving = ref(false)
const formError = ref<string | null>(null)
const form = ref({ title: '', alt_text: '', folder_id: '0' })

const folderModalOpen = ref(false)
const editingFolder = ref<MediaFolder | null>(null)
const folderForm = ref({ name: '', parent_id: '0' })

const moveOpen = ref(false)
const moveTarget = ref('0')

const KIND_ICON: Record<MediaKind, string> = {
  image: '🖼',
  video: '🎬',
  document: '📄',
  other: '📦',
}

const KIND_SEGMENTS = [
  { value: 'all', label: 'Все' },
  { value: 'image', label: 'Изображения' },
  { value: 'video', label: 'Видео' },
  { value: 'document', label: 'PDF' },
]

const hasMore = computed(() => page.value < lastPage.value)
const shown = computed(() => items.value.length)

const folderOptions = computed(() => [
  { value: '0', label: 'Все файлы (корень)' },
  ...folders.value.map((f) => ({ value: String(f.id), label: folderPath(f.id) })),
])

const breadcrumb = computed(() => {
  const chain: string[] = []
  let id = currentFolder.value
  while (id !== null) {
    const f = folders.value.find((x) => x.id === id)
    if (!f) break
    chain.unshift(f.name)
    id = f.parent_id === 0 ? null : f.parent_id
  }
  return chain
})

function folderPath(id: number): string {
  const chain: string[] = []
  let cursor: number | null = id
  const guard = new Set<number>()
  while (cursor !== null && !guard.has(cursor)) {
    guard.add(cursor)
    const f = folders.value.find((x) => x.id === cursor)
    if (!f) break
    chain.unshift(f.name)
    cursor = f.parent_id === 0 ? null : f.parent_id
  }
  return chain.join(' / ')
}

function depth(folder: MediaFolder): number {
  let d = 0
  let parent = folder.parent_id
  const guard = new Set<number>()
  while (parent !== 0 && !guard.has(parent) && d < 8) {
    guard.add(parent)
    d++
    parent = folders.value.find((f) => f.id === parent)?.parent_id ?? 0
  }
  return d
}

function queryString(targetPage: number): string {
  const params = new URLSearchParams({
    per_page: String(PER_PAGE),
    page: String(targetPage),
  })
  if (currentFolder.value !== null) params.set('folder_id', String(currentFolder.value))
  if (search.value.trim()) params.set('search', search.value.trim())
  if (kind.value !== 'all') params.set('kind', kind.value)
  return `/media?${params.toString()}`
}

async function load(reset = true): Promise<void> {
  if (reset) {
    loading.value = true
    items.value = []
    page.value = 1
    selected.value = new Set()
  } else {
    loadingMore.value = true
  }
  loadError.value = null

  try {
    const res = await request<{ data: MediaAsset[]; meta?: PageMeta }>(queryString(reset ? 1 : page.value + 1))
    const batch = Array.isArray(res.data) ? res.data : []
    items.value = reset ? batch : [...items.value, ...batch]
    lastPage.value = Math.max(1, Number(res.meta?.last_page ?? 1) || 1)
    total.value = Number(res.meta?.total ?? items.value.length) || 0
    page.value = reset ? 1 : page.value + 1

    if (reset && items.value.length === 0 && lastPage.value > 1) {
      page.value = lastPage.value
      await load(true)
    }
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : 'Не удалось загрузить файлы'
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

async function loadFolders(): Promise<void> {
  try {
    const res = await request<{ data: MediaFolder[] }>('/media/folders')
    folders.value = Array.isArray(res.data) ? res.data : []
  } catch {
    // Папки — улучшение, а не условие работы библиотеки: файлы должны
    // показываться даже если дерево не загрузилось.
    folders.value = []
  }
}

function openFolder(id: number | null): void {
  currentFolder.value = id
  void load(true)
}

function resetFilters(): void {
  currentFolder.value = null
  search.value = ''
  kind.value = 'all'
  void load(true)
}

let observer: IntersectionObserver | null = null
function watchSentinel(): void {
  observer?.disconnect()
  if (!sentinel.value) return
  observer = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting) && hasMore.value && !loading.value && !loadingMore.value) {
      void load(false)
    }
  }, { rootMargin: '300px' })
  observer.observe(sentinel.value)
}

watch([currentFolder, search, kind], () => {
  void load(true)
})
watch([hasMore, loading], () => {
  void Promise.resolve().then(watchSentinel)
})

function onFilesSelected(event: Event): void {
  const input = event.target as HTMLInputElement
  const files = input.files ? Array.from(input.files) : []
  input.value = ''
  void uploadFiles(files)
}

function onDrop(event: DragEvent): void {
  dragOver.value = false
  const files = event.dataTransfer?.files ? Array.from(event.dataTransfer.files) : []
  void uploadFiles(files)
}

async function uploadFiles(files: File[]): Promise<void> {
  if (files.length === 0 || uploading.value) return
  const rejected: string[] = []
  const accepted: File[] = []
  for (const file of files) {
    const problem = validateMediaFile(file)
    if (problem === null) accepted.push(file)
    else rejected.push(problem)
  }
  uploadErrors.value = rejected
  if (accepted.length === 0) return

  uploading.value = true
  uploadProgress.value = { done: 0, total: accepted.length }
  try {
    for (const file of accepted) {
      const formData = new FormData()
      formData.append('file', file)
      if (currentFolder.value !== null) formData.append('folder_id', String(currentFolder.value))
      try {
        await upload<MediaAsset>('/media', formData, 'POST')
      } catch (e) {
        uploadErrors.value = [...uploadErrors.value, `«${file.name}»: ${e instanceof Error ? e.message : String(e)}`]
      }
      uploadProgress.value = { done: uploadProgress.value.done + 1, total: accepted.length }
    }
    ui.notify('mint', 'Загрузка завершена', `${uploadProgress.value.done} файл(ов)`)
  } finally {
    uploading.value = false
    await load(true)
  }
}

function openEdit(item: MediaAsset): void {
  editing.value = item
  form.value = {
    title: item.title ?? '',
    alt_text: item.alt_text ?? '',
    folder_id: String(item.folder_id ?? 0),
  }
  formError.value = null
  editOpen.value = true
}

async function saveMeta(): Promise<void> {
  if (editing.value === null || saving.value) return
  saving.value = true
  formError.value = null
  try {
    await send<MediaAsset>(`/media/${editing.value.id}`, 'PATCH', {
      title: form.value.title.trim() || null,
      alt_text: form.value.alt_text.trim() || null,
      folder_id: Number(form.value.folder_id),
    })
    ui.notify('mint', 'Файл обновлён', editing.value.filename)
    editOpen.value = false
    await load(true)
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function remove(item: MediaAsset): Promise<void> {
  const used = usageLabels(item.links)
  const question = used.length
    ? `Файл используется: ${used.join('; ')}.\n\nУдалить файл «${item.filename}» вместе со связями?`
    : `Удалить файл «${item.filename}»?`
  if (!window.confirm(question)) return

  busyId.value = item.id
  try {
    await send<unknown>(`/media/${item.id}`, 'DELETE')
    ui.notify('sun', 'Файл удалён', item.filename)
    await load(true)
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  } finally {
    busyId.value = null
  }
}

function toggleSelect(id: number): void {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function selectAll(): void {
  selected.value = selected.value.size === items.value.length
    ? new Set()
    : new Set(items.value.map((item) => item.id))
}

async function bulkDelete(): Promise<void> {
  if (selected.value.size === 0) return
  if (!window.confirm(`Удалить выбранных файлов: ${selected.value.size}?`)) return
  const ids = [...selected.value]
  try {
    for (const id of ids) await send<unknown>(`/media/${id}`, 'DELETE')
    ui.notify('sun', 'Файлы удалены', `${ids.length} шт.`)
    selected.value = new Set()
    await load(true)
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  }
}

function openMove(): void {
  moveTarget.value = String(currentFolder.value ?? 0)
  moveOpen.value = true
}

async function bulkMove(): Promise<void> {
  if (selected.value.size === 0) return
  try {
    const res = await send<{ moved: number }>('/media/folders/move', 'POST', {
      media_ids: [...selected.value],
      folder_id: Number(moveTarget.value),
    })
    ui.notify('mint', 'Файлы перемещены', `${res.data.moved} шт.`)
    moveOpen.value = false
    selected.value = new Set()
    await load(true)
  } catch (e) {
    ui.notify('rose', 'Не удалось переместить', e instanceof Error ? e.message : String(e))
  }
}

function openFolderCreate(): void {
  editingFolder.value = null
  folderForm.value = { name: '', parent_id: String(currentFolder.value ?? 0) }
  folderModalOpen.value = true
}

function openFolderEdit(folder: MediaFolder): void {
  editingFolder.value = folder
  folderForm.value = { name: folder.name, parent_id: String(folder.parent_id) }
  folderModalOpen.value = true
}

async function saveFolder(): Promise<void> {
  const name = folderForm.value.name.trim()
  if (!name) return
  try {
    if (editingFolder.value) {
      await send(`/media/folders/${editingFolder.value.id}`, 'PATCH', { name })
    } else {
      await send('/media/folders', 'POST', { name, parent_id: Number(folderForm.value.parent_id) })
    }
    folderModalOpen.value = false
    await loadFolders()
    ui.notify('mint', 'Папка сохранена', name)
  } catch (e) {
    ui.notify('rose', 'Не удалось сохранить папку', e instanceof Error ? e.message : String(e))
  }
}

async function deleteFolder(folder: MediaFolder): Promise<void> {
  if (!window.confirm(`Удалить папку «${folder.name}»?`)) return
  try {
    await send(`/media/folders/${folder.id}`, 'DELETE')
    if (currentFolder.value === folder.id) currentFolder.value = null
    await Promise.all([loadFolders(), load(true)])
    ui.notify('sun', 'Папка удалена', folder.name)
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить папку', e instanceof Error ? e.message : String(e))
  }
}

async function copyUrl(item: MediaAsset): Promise<void> {
  try {
    await navigator.clipboard.writeText(new URL(assetSrc(item), window.location.origin).href)
    ui.notify('mint', 'Ссылка скопирована', item.filename)
  } catch {
    ui.notify('sun', 'Не удалось скопировать', 'Проверьте разрешение браузера на буфер обмена.')
  }
}

onMounted(() => {
  void load(true)
  void loadFolders()
  void Promise.resolve().then(watchSentinel)
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="mx-auto flex max-w-[1500px] gap-5">
    <!-- Дерево папок -->
    <aside class="hidden w-56 flex-none lg:block">
      <div class="surface-card sticky top-20 p-3">
        <div class="flex items-center justify-between gap-2">
          <h2 class="text-xs font-semibold uppercase tracking-wider text-subtle">Папки</h2>
          <button type="button" class="text-lg leading-none text-brand-300 hover:text-brand-200" title="Новая папка" @click="openFolderCreate">＋</button>
        </div>
        <ul class="mt-2 space-y-0.5">
          <li>
            <button type="button" class="w-full rounded-lg px-2 py-1.5 text-left text-sm transition"
                    :class="currentFolder === null ? 'bg-brand-500/12 text-brand-300' : 'text-muted hover:bg-surface-3'"
                    @click="openFolder(null)">
              📁 Все файлы <span class="text-2xs text-subtle">{{ total }}</span>
            </button>
          </li>
          <li v-for="f in folders" :key="f.id">
            <div class="group flex items-center gap-1 rounded-lg pr-1 transition hover:bg-surface-3"
                 :class="currentFolder === f.id ? 'bg-brand-500/12 text-brand-300' : 'text-muted'"
                 :style="{ paddingLeft: `${8 + depth(f) * 12}px` }">
              <button type="button" class="min-w-0 flex-1 truncate py-1.5 text-left text-sm" :title="folderPath(f.id)" @click="openFolder(f.id)">
                📂 {{ f.name }} <span class="text-2xs text-subtle">{{ f.files_count }}</span>
              </button>
              <button type="button" class="opacity-0 transition group-hover:opacity-100" title="Переименовать" @click="openFolderEdit(f)">✎</button>
              <button type="button" class="opacity-0 transition group-hover:opacity-100 hover:text-rose-400" title="Удалить папку" @click="deleteFolder(f)">✕</button>
            </div>
          </li>
        </ul>
      </div>
    </aside>

    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-2xl font-bold tracking-tight text-content">Файлы</h1>
          <p class="mt-1 text-sm text-muted">
            <template v-if="loading">Загрузка…</template>
            <template v-else>
              Показано {{ shown }} из {{ total }}
              <template v-if="breadcrumb.length"> · {{ breadcrumb.join(' / ') }}</template>
            </template>
          </p>
        </div>
        <NButton variant="primary" :disabled="uploading" @click="fileInput?.click()">
          {{ uploading ? `Загрузка ${uploadProgress.done}/${uploadProgress.total}…` : 'Загрузить файлы' }}
        </NButton>
      </div>

      <input ref="fileInput" type="file" multiple class="hidden" :accept="MEDIA_ACCEPT" @change="onFilesSelected" />

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="relative min-w-[220px] flex-1">
          <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-subtle" aria-hidden="true">⌕</span>
          <input v-model="search" type="search" placeholder="Поиск по имени или названию"
                 aria-label="Поиск файлов"
                 class="h-10 w-full rounded-lg border border-line bg-surface pl-9 pr-3 text-sm text-content placeholder:text-subtle focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25" />
        </div>
        <NSegmented v-model="kind" :segments="KIND_SEGMENTS" size="sm" aria-label="Тип файла" />
        <NButton v-if="currentFolder !== null || search || kind !== 'all'" variant="ghost" size="sm" @click="resetFilters">Сбросить</NButton>
      </div>

      <div class="mt-3 flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-line bg-surface-2 px-4 py-5 text-center text-sm text-muted transition hover:border-brand-500"
           :class="{ 'border-brand-500 bg-brand-500/5': dragOver }"
           role="button" tabindex="0"
           @click="fileInput?.click()"
           @keydown.enter.prevent="fileInput?.click()"
           @dragover.prevent="dragOver = true"
           @dragleave.prevent="dragOver = false"
           @drop.prevent="onDrop">
        <span class="mb-1 block text-2xl" aria-hidden="true">📁</span>
        Перетащите файлы сюда или нажмите для выбора
        <span class="mt-1 block text-xs text-subtle">{{ MEDIA_TYPES_LABEL }}, до 10 МБ каждый<template v-if="currentFolder !== null"> · в папку «{{ breadcrumb.at(-1) }}»</template></span>
      </div>

      <ul v-if="uploadErrors.length" class="mt-3 space-y-1">
        <li v-for="(problem, i) in uploadErrors" :key="i"
            class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs leading-relaxed text-rose-400">
          {{ problem }}
        </li>
      </ul>

      <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
        Не удалось загрузить файлы: {{ loadError }}
        <button type="button" class="ml-2 underline" @click="load(true)">Повторить</button>
      </div>

      <div v-if="selected.size" class="surface-card mt-4 flex flex-wrap items-center gap-2 border-brand-500/30 px-4 py-2.5">
        <span class="text-sm text-content">Выбрано: {{ selected.size }}</span>
        <NButton variant="ghost" size="sm" @click="selectAll">Выбрать всё</NButton>
        <NButton variant="secondary" size="sm" @click="openMove">Переместить</NButton>
        <NButton variant="ghost" size="sm" @click="bulkDelete">Удалить</NButton>
        <NButton variant="ghost" size="sm" class="ml-auto" @click="selected = new Set()">Снять выделение</NButton>
      </div>

      <p v-if="loading" class="mt-6 text-sm text-muted">Загрузка файлов…</p>

      <p v-else-if="items.length === 0"
         class="mt-6 rounded-xl border border-dashed border-line bg-surface-2 px-4 py-8 text-center text-sm leading-relaxed text-muted">
        Файлов пока нет. Загрузите первую афишу или создайте папку для порядка.
      </p>

      <template v-else>
        <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          <li v-for="item in items" :key="item.id"
              class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-surface-2"
              :class="selected.has(item.id) ? 'ring-2 ring-brand-500' : ''">
            <label class="absolute left-2 top-2 z-10 flex h-6 w-6 cursor-pointer items-center justify-center rounded-md border border-line bg-surface/90 text-xs"
                   :title="selected.has(item.id) ? 'Снять выделение' : 'Выбрать файл'">
              <input type="checkbox" class="sr-only" :checked="selected.has(item.id)" @change="toggleSelect(item.id)" />
              {{ selected.has(item.id) ? '✓' : '' }}
            </label>

            <div class="relative aspect-square bg-surface-3">
              <img v-if="kindOf(item.mime_type) === 'image'"
                   :src="assetSrc(item)"
                   :alt="item.alt_text || item.filename"
                   class="h-full w-full object-cover"
                   loading="lazy" />
              <div v-else class="flex h-full w-full items-center justify-center text-5xl" aria-hidden="true">
                {{ KIND_ICON[kindOf(item.mime_type)] }}
              </div>
            </div>

            <div class="flex flex-1 flex-col gap-1 p-3">
              <p class="truncate text-sm font-medium text-content" :title="item.title || item.filename">
                {{ item.title || item.filename }}
              </p>
              <p class="truncate text-2xs text-subtle" :title="item.filename">{{ item.filename }}</p>
              <p class="text-2xs text-muted">
                {{ humanSize(item.size_bytes) }}
                <template v-if="dimensionsLabel(item)"> · {{ dimensionsLabel(item) }}</template>
                <template v-if="formatDate(item.created_at)"> · {{ formatDate(item.created_at) }}</template>
              </p>

              <ul v-if="usageLabels(item.links).length" class="mt-1 flex flex-wrap gap-1">
                <li v-for="label in usageLabels(item.links)" :key="label"
                    class="rounded-full border border-brand-500/30 bg-brand-500/10 px-2 py-0.5 text-2xs text-brand-400">
                  {{ label }}
                </li>
              </ul>
              <p v-else class="mt-1 text-2xs text-subtle">нигде не используется</p>

              <div class="mt-auto flex flex-wrap items-center gap-1 pt-2">
                <NButton variant="ghost" size="sm" @click="openEdit(item)">Изменить</NButton>
                <NButton variant="ghost" size="sm" @click="copyUrl(item)">URL</NButton>
                <NButton variant="ghost" size="sm" class="ml-auto" :loading="busyId === item.id" @click="remove(item)">Удалить</NButton>
              </div>
            </div>
          </li>
        </ul>

        <div class="mt-6 flex flex-col items-center gap-2">
          <div ref="sentinel" class="h-px w-full" aria-hidden="true" />
          <p class="text-xs text-subtle">Показано {{ shown }} из {{ total }}</p>
          <NButton v-if="hasMore" variant="secondary" size="sm" :loading="loadingMore" @click="load(false)">
            Загрузить ещё
          </NButton>
          <p v-else class="text-xs text-subtle">Все файлы загружены</p>
        </div>
      </template>
    </div>

    <!-- Метаданные файла -->
    <NModal :open="editOpen" title="Метаданные файла" size="md" @update:open="editOpen = false">
      <p v-if="editing" class="mb-3 text-xs text-subtle">{{ editing.filename }}</p>
      <div v-if="formError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ formError }}</div>
      <form class="space-y-3" @submit.prevent="saveMeta">
        <NInput v-model="form.title" label="Название" placeholder="Афиша новогоднего концерта" />
        <NInput v-model="form.alt_text" label="Описание для незрячих и поиска"
                placeholder="Сцена с оркестром на фоне красного занавеса" />
        <NSelect v-model="form.folder_id" label="Папка" :options="folderOptions" />
        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="editOpen = false">Отмена</NButton>
          <NButton type="submit" variant="accent" :loading="saving">Сохранить</NButton>
        </div>
      </form>
    </NModal>

    <!-- Папка -->
    <NModal :open="folderModalOpen" :title="editingFolder ? 'Переименовать папку' : 'Новая папка'" size="sm" @update:open="folderModalOpen = false">
      <form class="space-y-3" @submit.prevent="saveFolder">
        <NInput v-model="folderForm.name" label="Название" placeholder="Афиши 2026" required />
        <NSelect v-if="!editingFolder" v-model="folderForm.parent_id" label="Родительская папка" :options="folderOptions" />
        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="folderModalOpen = false">Отмена</NButton>
          <NButton type="submit" variant="accent">Сохранить</NButton>
        </div>
      </form>
    </NModal>

    <!-- Массовое перемещение -->
    <NModal :open="moveOpen" title="Переместить файлы" size="sm" @update:open="moveOpen = false">
      <div class="space-y-3">
        <p class="text-sm text-muted">Выбрано файлов: {{ selected.size }}</p>
        <NSelect v-model="moveTarget" label="Папка назначения" :options="folderOptions" />
        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="moveOpen = false">Отмена</NButton>
          <NButton variant="accent" @click="bulkMove">Переместить</NButton>
        </div>
      </div>
    </NModal>
  </div>
</template>
