<script setup lang="ts">
/**
 * Выбор изображения из медиатеки — модалка в стиле WordPress.
 *
 * Раньше единственным способом задать картинку вне формы мероприятия было
 * вставить ссылку в текстовое поле, а переиспользуемого выбора из медиатеки не
 * существовало вовсе. Компонент собирает в себе и список файлов, и загрузку с
 * компьютера: оба пути ведут к одному результату — выбранному `MediaAsset`.
 *
 * Использование:
 *   <MediaPicker v-model:open="open" @select="onPick" />
 */
import { computed, ref, watch } from 'vue'
import NModal from '@/components/ui/NModal.vue'
import NButton from '@/components/ui/NButton.vue'
import { get, upload } from '@/lib/api'
import {
  MEDIA_ACCEPT,
  humanSize,
  kindOf,
  validateMediaFile,
  type MediaAsset,
} from '@/lib/mediaLibrary'

const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    /** Показывать только изображения (для афиш/обложек). */
    imagesOnly?: boolean
  }>(),
  { title: 'Медиатека', imagesOnly: true },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; select: [asset: MediaAsset] }>()

const PER_PAGE = 24

const items = ref<MediaAsset[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const uploading = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const fileInput = ref<HTMLInputElement | null>(null)

const visible = computed(() =>
  props.imagesOnly ? items.value.filter((a) => kindOf(a.mime_type) === 'image') : items.value,
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<unknown>(`/media?per_page=${PER_PAGE}&page=${page.value}`)
    const body = res.data as { data?: MediaAsset[]; meta?: Record<string, unknown> } | MediaAsset[]
    if (Array.isArray(body)) {
      items.value = body
      lastPage.value = 1
      total.value = body.length
    } else {
      items.value = body.data ?? []
      lastPage.value = Number(body.meta?.last_page ?? 1) || 1
      total.value = Number(body.meta?.total ?? items.value.length) || items.value.length
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      page.value = 1
      load()
    }
  },
)

function close(): void {
  emit('update:open', false)
}

function pick(asset: MediaAsset): void {
  emit('select', asset)
  close()
}

function triggerUpload(): void {
  fileInput.value?.click()
}

async function onFile(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return

  const problem = validateMediaFile(file)
  if (problem) {
    error.value = problem
    return
  }

  uploading.value = true
  error.value = null
  try {
    const fd = new FormData()
    fd.append('file', file, file.name)
    const res = await upload<MediaAsset>('/media', fd, 'POST')
    const asset = res.data
    // Сценарий «загрузил → сразу подставил»: свежий файл выбирается сам.
    if (asset && typeof asset === 'object' && 'url' in asset) {
      emit('select', asset)
      close()
    } else {
      await load()
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    uploading.value = false
  }
}

function go(delta: number): void {
  const next = Math.min(Math.max(page.value + delta, 1), lastPage.value)
  if (next === page.value) return
  page.value = next
  load()
}
</script>

<template>
  <NModal
    :open="open"
    :title="title"
    description="Выберите файл из медиатеки или загрузите новый с компьютера."
    size="lg"
    @update:open="close"
  >
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-subtle">В медиатеке: {{ total }}</p>
        <div class="flex items-center gap-2">
          <input ref="fileInput" type="file" :accept="MEDIA_ACCEPT" class="hidden" @change="onFile" />
          <NButton variant="secondary" :loading="uploading" @click="triggerUpload">Загрузить с компьютера</NButton>
        </div>
      </div>

      <p
        v-if="error"
        class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-400"
      >
        {{ error }}
      </p>

      <p v-if="loading" class="py-8 text-center text-sm text-subtle">Загружаем медиатеку…</p>

      <div
        v-else-if="visible.length"
        class="grid max-h-[50vh] grid-cols-3 gap-3 overflow-y-auto sm:grid-cols-4"
      >
        <button
          v-for="asset in visible"
          :key="asset.id"
          type="button"
          class="overflow-hidden rounded-lg border border-line bg-surface-2 text-left transition-colors hover:border-brand-400"
          @click="pick(asset)"
        >
          <img
            v-if="kindOf(asset.mime_type) === 'image'"
            :src="asset.url"
            :alt="asset.alt_text || asset.filename"
            class="h-24 w-full object-cover"
            loading="lazy"
          />
          <span v-else class="grid h-24 w-full place-items-center text-2xl">🎬</span>
          <span class="block truncate px-2 pt-1 text-2xs text-subtle">{{ asset.filename }}</span>
          <span class="block px-2 pb-1 text-2xs text-subtle">{{ humanSize(asset.size_bytes) }}</span>
        </button>
      </div>

      <p v-else class="py-8 text-center text-sm text-subtle">
        {{ imagesOnly ? 'Изображений пока нет — загрузите первое с компьютера.' : 'Файлов пока нет — загрузите первый с компьютера.' }}
      </p>

      <div v-if="lastPage > 1" class="flex items-center justify-center gap-3">
        <NButton variant="ghost" size="sm" :disabled="page <= 1" @click="go(-1)">← Назад</NButton>
        <span class="text-xs text-subtle">Стр. {{ page }} из {{ lastPage }}</span>
        <NButton variant="ghost" size="sm" :disabled="page >= lastPage" @click="go(1)">Вперёд →</NButton>
      </div>
    </div>
  </NModal>
</template>
