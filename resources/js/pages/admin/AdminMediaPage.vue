<script setup lang="ts">
/**
 * Файлы (админка).
 *
 * ЧТО БЫЛО
 *
 * Модуль Media отдавал пять эндпоинтов контракта, но ни одного экрана к ним:
 * `resources/js/pages/admin/` содержал двадцать страниц, и ни одной про файлы.
 * Афиша выбиралась только из карточки мероприятия, галерея — оттуда же, а
 * посмотреть, какие файлы вообще есть у организации и где они используются,
 * было негде. Файл, привязанный к удалённому мероприятию, оставался в
 * хранилище навсегда: о нём нельзя было узнать из интерфейса.
 *
 * ПОЧЕМУ СЕТКА, А НЕ ТАБЛИЦА
 *
 * У файла главное — как он выглядит. `NDataTable` показал бы имя и размер, но
 * не саму картинку, а афишу выбирают глазами. Сетка же позволяет и то, и
 * другое, а строк с файлами у одной организации редко бывает много.
 *
 * ГДЕ ИСПОЛЬЗУЕТСЯ — ВИДНО ДО УДАЛЕНИЯ
 *
 * Список отдаёт `links` (см. `MediaController::index`), и карточка показывает,
 * к чему привязан файл. Без этого удаление было бы действием вслепую: вместе с
 * файлом уехала бы афиша или галерея мероприятия, и заметили бы это только
 * покупатели.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'
import { useUiStore } from '@/stores/ui'
import { request, send, upload } from '@/lib/api'
import type { PageMeta } from '@/lib/api'
import {
  MEDIA_ACCEPT,
  MEDIA_TYPES_LABEL,
  type MediaAsset,
  type MediaKind,
  clampPage,
  dimensionsLabel,
  formatDate,
  humanSize,
  kindOf,
  usageLabels,
  validateMediaFile,
} from '@/lib/mediaLibrary'

/**
 * `meta` списка файлов — общий `PageMeta` из `lib/api.ts`.
 *
 * Раньше здесь был локальный интерфейс с полем `page`: модуль Media отдавал
 * его, потому что так было записано в контракте, а восемь других контроллеров
 * отдавали `current_page`. Локальная копия типа и была симптомом — странице
 * приходилось описывать ответ, который ни один общий тип не описывал.
 * Расхождение устранено в пользу `current_page` (см. `MediaController::index`),
 * и тип теперь общий.
 */

const PER_PAGE = 24

const ui = useUiStore()

const items = ref<MediaAsset[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)

const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const uploading = ref(false)
const uploadProgress = ref({ done: 0, total: 0 })
const uploadErrors = ref<string[]>([])
const busyId = ref<number | null>(null)

const editOpen = ref(false)
const editing = ref<MediaAsset | null>(null)
const saving = ref(false)
const formError = ref<string | null>(null)
const form = ref({ title: '', alt_text: '' })

const KIND_ICON: Record<MediaKind, string> = {
  image: '🖼',
  video: '🎬',
  document: '📄',
  other: '📦',
}

const hasPrev = computed(() => page.value > 1)
const hasNext = computed(() => page.value < lastPage.value)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    const res = await request<{ data: MediaAsset[]; meta?: PageMeta }>(
      `/media?per_page=${PER_PAGE}&page=${page.value}`,
    )

    items.value = Array.isArray(res.data) ? res.data : []
    lastPage.value = Math.max(1, Number(res.meta?.last_page ?? 1) || 1)
    total.value = Number(res.meta?.total ?? items.value.length) || 0

    // Удалили последний файл последней страницы — текущая страница опустела.
    // Сервер вернул бы пустой список, и это выглядело бы как «файлы пропали».
    const clamped = clampPage(page.value, lastPage.value)

    if (clamped !== page.value) {
      page.value = clamped
      await load()
    }
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

function goTo(next: number): void {
  const target = clampPage(next, lastPage.value)

  if (target === page.value) return

  page.value = target
  void load()
}

function onFilesSelected(event: Event): void {
  const input = event.target as HTMLInputElement
  const files = input.files ? Array.from(input.files) : []

  // Поле очищается сразу: иначе повторный выбор того же файла не вызовет
  // `change`, и администратор решит, что загрузка не сработала.
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

  uploadErrors.value = []

  // Проверяем ВСЁ до первой отправки: иначе часть файлов уже уехала бы на
  // сервер, а потом выяснилось бы, что остальные не того типа.
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
    // По одному, а не пачкой: так полоса прогресса честно показывает, сколько
    // уже ушло, и один отклонённый файл не отменяет остальные.
    for (const file of accepted) {
      const formData = new FormData()
      formData.append('file', file)

      try {
        await upload<MediaAsset>('/media', formData, 'POST')
      } catch (e) {
        uploadErrors.value = [
          ...uploadErrors.value,
          `«${file.name}»: ${e instanceof Error ? e.message : String(e)}`,
        ]
      }

      uploadProgress.value = { done: uploadProgress.value.done + 1, total: accepted.length }
    }
  } finally {
    uploading.value = false
    // Список отсортирован по убыванию `id`, поэтому новые файлы приходят в
    // начало. Открываем первую страницу — иначе загруженное осталось бы за кадром.
    page.value = 1
    await load()
  }
}

function openEdit(item: MediaAsset): void {
  editing.value = item
  form.value = { title: item.title ?? '', alt_text: item.alt_text ?? '' }
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
    })

    ui.notify('mint', 'Файл обновлён', editing.value.filename)
    editOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function remove(item: MediaAsset): Promise<void> {
  // Подтверждение называет связи: удалять файл, не зная, что он афиша
  // мероприятия, — это действие вслепую.
  const used = usageLabels(item.links)
  const question = used.length
    ? `Файл используется: ${used.join('; ')}.\n\nУдалить файл «${item.filename}» вместе со связями?`
    : `Удалить файл «${item.filename}»?`

  if (!window.confirm(question)) return

  busyId.value = item.id

  try {
    await send<unknown>(`/media/${item.id}`, 'DELETE')
    ui.notify('sun', 'Файл удалён', item.filename)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  } finally {
    busyId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Файлы</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ total }} файлов</template>
        </p>
      </div>
      <NButton variant="primary" :disabled="uploading" @click="fileInput?.click()">
        {{ uploading ? `Загрузка ${uploadProgress.done}/${uploadProgress.total}…` : 'Загрузить файлы' }}
      </NButton>
    </div>

    <input
      ref="fileInput"
      type="file"
      multiple
      class="hidden"
      :accept="MEDIA_ACCEPT"
      @change="onFilesSelected"
    />

    <div
      class="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-line bg-surface-2 px-4 py-6 text-center text-sm text-muted transition hover:border-brand-500"
      :class="{ 'border-brand-500 bg-brand-500/5': dragOver }"
      role="button"
      tabindex="0"
      @click="fileInput?.click()"
      @keydown.enter.prevent="fileInput?.click()"
      @dragover.prevent="dragOver = true"
      @dragleave.prevent="dragOver = false"
      @drop.prevent="onDrop"
    >
      <span class="mb-1 block text-2xl" aria-hidden="true">📁</span>
      Перетащите файлы сюда или нажмите для выбора
      <span class="mt-1 block text-xs text-subtle">{{ MEDIA_TYPES_LABEL }}, до 10 МБ каждый</span>
    </div>

    <ul v-if="uploadErrors.length" class="mt-3 space-y-1">
      <li
        v-for="(problem, i) in uploadErrors"
        :key="i"
        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs leading-relaxed text-rose-400"
      >
        {{ problem }}
      </li>
    </ul>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить файлы: {{ loadError }}
    </div>

    <p v-if="loading" class="mt-6 text-sm text-muted">Загрузка файлов…</p>

    <p
      v-else-if="items.length === 0"
      class="mt-6 rounded-xl border border-dashed border-line bg-surface-2 px-4 py-8 text-center text-sm leading-relaxed text-muted"
    >
      Файлов пока нет. Афиши и галереи мероприятий появляются здесь автоматически — вместе с
      загрузкой из карточки мероприятия.
    </p>

    <template v-else>
      <ul class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <li
          v-for="item in items"
          :key="item.id"
          class="group flex flex-col overflow-hidden rounded-xl border border-line bg-surface-2"
        >
          <div class="relative aspect-[4/3] bg-surface-3">
            <img
              v-if="kindOf(item.mime_type) === 'image'"
              :src="item.url"
              :alt="item.alt_text || item.filename"
              class="h-full w-full object-cover"
              loading="lazy"
            />
            <div v-else class="flex h-full w-full items-center justify-center text-4xl" aria-hidden="true">
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

            <!--
              Где используется. Пусто — значит файл ни к чему не привязан, и это
              видно до удаления, а не после.
            -->
            <ul v-if="usageLabels(item.links).length" class="mt-1 flex flex-wrap gap-1">
              <li
                v-for="label in usageLabels(item.links)"
                :key="label"
                class="rounded-full border border-brand-500/30 bg-brand-500/10 px-2 py-0.5 text-2xs text-brand-400"
              >
                {{ label }}
              </li>
            </ul>
            <p v-else class="mt-1 text-2xs text-subtle">нигде не используется</p>

            <div class="mt-auto flex items-center gap-1 pt-2">
              <NButton variant="ghost" size="sm" @click="openEdit(item)">Изменить</NButton>
              <NButton
                variant="ghost"
                size="sm"
                class="ml-auto"
                :loading="busyId === item.id"
                @click="remove(item)"
              >
                Удалить
              </NButton>
            </div>
          </div>
        </li>
      </ul>

      <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-3">
        <NButton variant="secondary" size="sm" :disabled="!hasPrev" @click="goTo(page - 1)">
          Назад
        </NButton>
        <span class="text-sm text-muted">Страница {{ page }} из {{ lastPage }}</span>
        <NButton variant="secondary" size="sm" :disabled="!hasNext" @click="goTo(page + 1)">
          Вперёд
        </NButton>
      </div>
    </template>

    <NModal
      :open="editOpen"
      title="Метаданные файла"
      size="md"
      @update:open="editOpen = false"
    >
      <p v-if="editing" class="mb-3 text-xs text-subtle">{{ editing.filename }}</p>

      <div v-if="formError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
        {{ formError }}
      </div>

      <form class="space-y-3" @submit.prevent="saveMeta">
        <NInput v-model="form.title" label="Название" placeholder="Афиша новогоднего концерта" />
        <!--
          Alt-текст — не формальность: с ним картинку прочитает незрячий
          покупатель и найдёт поисковик.
        -->
        <NInput
          v-model="form.alt_text"
          label="Описание для незрячих и поиска"
          placeholder="Сцена с оркестром на фоне красного занавеса"
        />

        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="editOpen = false">Отмена</NButton>
          <NButton type="submit" variant="accent" :loading="saving">Сохранить</NButton>
        </div>
      </form>
    </NModal>
  </div>
</template>
