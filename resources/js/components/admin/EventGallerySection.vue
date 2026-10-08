<script setup lang="ts">
/**
 * Дополнительные фото и видео мероприятия.
 *
 * ЧТО БЫЛО
 *
 * Раздела не существовало вообще, а таблица `media_links` — та, для этого и
 * предназначенная, — стояла пустой, потому что модуль Media не отдавал ни
 * одного маршрута. Здесь форма наконец может приложить к мероприятию файлы.
 *
 * ЗАГРУЗКА ИДЁТ ПОСЛЕДОВАТЕЛЬНО, И ЭТО НАМЕРЕННО
 *
 * Позиция нового элемента вычисляется на сервере как «максимум + 1» среди
 * связей объекта. При параллельной отправке два запроса прочитали бы один и
 * тот же максимум и записали одинаковую позицию — порядок галереи оказался бы
 * произвольным. Поэтому файлы уходят по одному, а полоса прогресса честно
 * показывает, сколько уже ушло.
 *
 * ПОСЛЕ КАЖДОЙ ПРАВКИ СПИСОК ПЕРЕЧИТЫВАЕТСЯ
 *
 * Порядок — это данные на сервере, а не состояние экрана. Оптимистичная
 * перестановка в массиве нужна только для мгновенного отклика; окончательный
 * порядок приходит из `GET /events/{id}/gallery`.
 */
import { computed, onMounted, ref, watch } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import { get, send, upload } from '@/lib/api'
import {
  GALLERY_TYPES,
  type GalleryItem,
  isImage,
  moveWithin,
  reorderPlan,
  validateGalleryFile,
} from '@/lib/eventGallery'
// `humanSize` — общая функция библиотеки файлов, а не часть галереи. Держать её
// в двух местах значит однажды увидеть «2 КБ» на одном экране и «2.0 КБ» на
// другом; поэтому она импортируется из общего модуля.
import { humanSize } from '@/lib/mediaLibrary'

const props = defineProps<{ eventId: number }>()

const items = ref<GalleryItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const uploading = ref(false)
const uploadProgress = ref({ done: 0, total: 0 })
const uploadErrors = ref<string[]>([])
const busyMediaId = ref<number | null>(null)

const accept = computed(() => GALLERY_TYPES.join(','))

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const res = await get<GalleryItem[]>(`/events/${props.eventId}/gallery`)
    items.value = Array.isArray(res.data) ? res.data : []
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    loading.value = false
  }
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
    const problem = validateGalleryFile(file)
    if (problem === null) {
      accepted.push(file)
    } else {
      rejected.push(problem)
    }
  }

  uploadErrors.value = rejected

  if (accepted.length === 0) return

  uploading.value = true
  uploadProgress.value = { done: 0, total: accepted.length }

  try {
    for (const file of accepted) {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('entity_type', 'event')
      fd.append('entity_id', String(props.eventId))
      fd.append('role', 'gallery')

      try {
        await upload<unknown>('/media', fd, 'POST')
      } catch (err) {
        uploadErrors.value = [
          ...uploadErrors.value,
          `«${file.name}»: ${err instanceof Error ? err.message : String(err)}`,
        ]
      }

      uploadProgress.value = { done: uploadProgress.value.done + 1, total: accepted.length }
    }
  } finally {
    uploading.value = false
    await load()
  }
}

async function remove(item: GalleryItem): Promise<void> {
  busyMediaId.value = item.media_id

  try {
    await send(`/events/${props.eventId}/gallery/${item.media_id}`, 'DELETE')
    await load()
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    busyMediaId.value = null
  }
}

/**
 * Переставить файл на одну ступень.
 *
 * Сначала меняем локально (мгновенный отклик), затем отправляем ДВЕ новые
 * позиции — перемещаемого элемента и того, с кем он поменялся местами.
 * Отправлять позиции всего списка значило бы делать N запросов там, где
 * достаточно двух.
 */
async function move(index: number, direction: -1 | 1): Promise<void> {
  const plan = reorderPlan(items.value, index, direction)

  if (plan.length === 0) return

  items.value = moveWithin(items.value, index, direction)

  try {
    for (const change of plan) {
      await send(`/media/${change.mediaId}`, 'PATCH', {
        entity_type: 'event',
        entity_id: props.eventId,
        role: 'gallery',
        position: change.position,
      })
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    // Сервер — источник правды о порядке: после ответа перечитываем список.
    await load()
  }
}

onMounted(() => {
  void load()
})

// Мероприятие могло смениться без перемонтирования (переход между карточками).
watch(() => props.eventId, () => {
  void load()
})
</script>

<template>
  <section class="rounded-xl border border-line p-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">Дополнительные фото и видео</h2>
      <NButton variant="secondary" size="sm" :disabled="uploading" @click="fileInput?.click()">
        {{ uploading ? `Загрузка ${uploadProgress.done}/${uploadProgress.total}…` : 'Добавить файлы' }}
      </NButton>
    </div>

    <p class="mb-3 text-xs leading-relaxed text-subtle">
      Галерея показывается на странице мероприятия под описанием. Порядок — тот, в котором
      файлы добавлены; его можно менять стрелками. Первый файл не становится афишей: афиша
      выбирается выше, отдельно.
    </p>

    <input
      ref="fileInput"
      type="file"
      multiple
      class="hidden"
      :accept="accept"
      @change="onFilesSelected"
    />

    <div
      class="mb-3 flex cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-line bg-surface-2 px-4 py-6 text-center text-sm text-muted transition hover:border-brand-500"
      :class="{ 'border-brand-500 bg-brand-500/5': dragOver }"
      role="button"
      tabindex="0"
      @click="fileInput?.click()"
      @keydown.enter.prevent="fileInput?.click()"
      @dragover.prevent="dragOver = true"
      @dragleave.prevent="dragOver = false"
      @drop.prevent="onDrop"
    >
      <span class="mb-1 block text-2xl" aria-hidden="true">🖼</span>
      Перетащите файлы сюда или нажмите для выбора
      <span class="mt-1 block text-xs text-subtle">jpg / png / webp / avif / gif / mp4 / webm, до 10 МБ каждый</span>
    </div>

    <ul v-if="uploadErrors.length" class="mb-3 space-y-1">
      <li
        v-for="(problem, i) in uploadErrors"
        :key="i"
        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs leading-relaxed text-rose-400"
      >
        {{ problem }}
      </li>
    </ul>

    <p v-if="error" class="mb-3 rounded-lg border border-sun-500/30 bg-sun-500/10 px-3 py-2 text-xs leading-relaxed text-sun-400">
      {{ error }}
    </p>

    <p v-if="loading" class="text-sm text-muted">Загрузка галереи…</p>

    <p
      v-else-if="items.length === 0"
      class="rounded-lg border border-dashed border-line bg-surface-2 px-3 py-4 text-sm leading-relaxed text-muted"
    >
      Дополнительных материалов нет. Файлы отсюда попадают в галерею на странице мероприятия.
    </p>

    <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <li
        v-for="(item, index) in items"
        :key="item.id"
        class="group overflow-hidden rounded-lg border border-line bg-surface-2"
      >
        <div class="relative aspect-[4/3] bg-surface-3">
          <img
            v-if="isImage(item) && item.media"
            :src="item.media.url"
            :alt="item.media.alt_text || item.media.filename"
            class="h-full w-full object-cover"
            loading="lazy"
          />
          <div v-else class="flex h-full w-full items-center justify-center text-3xl" aria-hidden="true">🎬</div>
        </div>

        <div class="space-y-1 p-2">
          <p class="truncate text-xs text-content" :title="item.media?.filename ?? ''">
            {{ item.media?.filename ?? 'файл удалён' }}
          </p>
          <p class="text-[11px] text-subtle">
            {{ item.media ? humanSize(item.media.size_bytes) : '' }}
          </p>

          <div class="flex items-center gap-1 pt-1">
            <button
              type="button"
              class="rounded px-1.5 py-0.5 text-xs text-muted transition hover:bg-surface-3 hover:text-content disabled:opacity-40"
              :disabled="index === 0 || uploading"
              :aria-label="`Переместить «${item.media?.filename ?? ''}» выше`"
              @click="move(index, -1)"
            >
              ←
            </button>
            <button
              type="button"
              class="rounded px-1.5 py-0.5 text-xs text-muted transition hover:bg-surface-3 hover:text-content disabled:opacity-40"
              :disabled="index === items.length - 1 || uploading"
              :aria-label="`Переместить «${item.media?.filename ?? ''}» ниже`"
              @click="move(index, 1)"
            >
              →
            </button>
            <button
              type="button"
              class="ml-auto rounded px-1.5 py-0.5 text-xs text-rose-400 transition hover:bg-rose-500/10 disabled:opacity-40"
              :disabled="busyMediaId === item.media_id || uploading"
              :aria-label="`Убрать «${item.media?.filename ?? ''}» из галереи`"
              @click="remove(item)"
            >
              Убрать
            </button>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>
