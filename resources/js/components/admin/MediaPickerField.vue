<script setup lang="ts">
/**
 * Поле «картинка из медиатеки» — NInput URL + кнопка «Выбрать из медиатеки»
 * и модалка-пикер (выбор в всплывающем окне удобнее: не сдвигает форму).
 *
 * ЗАЧЕМ ЖИВЁТ ОТДЕЛЬНЫМ КОМПОНЕНТОМ
 *   Медиатека в проекте одна; копировать её выборку в каждую форму
 *   (билетный конструктор, витрина, брендинг) значило бы трижды поддерживать
 *   один и тот же список. Поле можно вбить руками (внешний URL), а можно
 *   выбрать из библиотеки — оба пути пишут одну строку в `modelValue`.
 *
 * ЗАГРУЗКА ЛЕНИВАЯ: список медиатеки поднимается при первом открытии
 * пикера, а не при монтировании формы — форма с шестью полями картинок не
 * должна шесть раз дёргать `/media` или грузить 48 превью на старте.
 */
import { ref } from 'vue'
import { get } from '@/lib/api'
import { storageSrc, type MediaAsset } from '@/lib/mediaLibrary'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    hint?: string
    placeholder?: string
  }>(),
  { placeholder: 'https://…' },
)

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const open = ref(false)
const items = ref<MediaAsset[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

async function openPicker(): Promise<void> {
  open.value = true

  if (loading.value || items.value.length > 0) return

  loading.value = true
  error.value = null

  try {
    const res = await get('/media?per_page=48')
    const payload = res.data as unknown

    items.value = Array.isArray(payload) ? (payload as MediaAsset[]) : ((payload as { data?: MediaAsset[] })?.data ?? [])
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
    items.value = []
  } finally {
    loading.value = false
  }
}

function pick(asset: MediaAsset): void {
  emit('update:modelValue', asset.url)
  open.value = false
}

function clear(): void {
  emit('update:modelValue', '')

  open.value = false
}

/** Внешние картинки и абсолютные URL не трогаем, медиатечные — через /storage. */
const previewSrc = props.modelValue.startsWith('http')
  ? props.modelValue
  : storageSrc(props.modelValue)
</script>

<template>
  <div>
    <NInput
      :model-value="modelValue"
      :label="label"
      :hint="hint"
      :placeholder="placeholder"
      @update:model-value="emit('update:modelValue', $event)"
    />

    <div class="mt-2 flex items-center gap-2.5">
      <!-- Превью выбранного: внешний URL — как есть, из медиатеки — через /storage. -->
      <img
        v-if="modelValue"
        :src="previewSrc"
        alt=""
        class="h-10 w-16 rounded border border-line bg-surface-2 object-contain"
      />
      <NButton variant="secondary" size="sm" icon="🖼" @click="openPicker">Выбрать из медиатеки</NButton>
      <NButton v-if="modelValue" variant="ghost" size="sm" aria-label="Очистить поле" @click="clear">✕</NButton>
    </div>

    <NModal
      :open="open"
      title="Медиатека"
      description="Выберите изображение — путь подставится в поле."
      size="lg"
      @update:open="open = $event"
    >
      <p v-if="loading" class="py-8 text-center text-sm text-subtle">Загружаем файлы…</p>

      <p v-else-if="error" class="py-2 text-sm text-rose-400">Не удалось загрузить: {{ error }}</p>

      <p v-else-if="!items.length" class="py-8 text-center text-sm text-subtle">
        Медиатека пуста — загрузите файлы в разделе «Медиатека».
      </p>

      <div v-else class="grid max-h-[55vh] grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
        <button
          v-for="asset in items"
          :key="asset.id"
          type="button"
          class="rounded-lg border border-line bg-surface-2 p-1 transition-colors hover:border-brand-500/60"
          :title="asset.filename"
          @click="pick(asset)"
        >
          <img
            v-if="String(asset.mime_type ?? '').startsWith('image/')"
            :src="asset.url"
            :alt="asset.filename ?? ''"
            class="h-16 w-full rounded object-contain"
          />
          <span v-else class="grid h-16 w-full place-items-center rounded text-2xs text-subtle">файл</span>
          <span class="block truncate text-2xs text-subtle">{{ asset.filename }}</span>
        </button>
      </div>

      <template #footer>
        <NButton v-if="modelValue" variant="ghost" size="sm" @click="clear">Очистить поле</NButton>
        <NButton variant="secondary" size="sm" @click="open = false">Отмена</NButton>
      </template>
    </NModal>
  </div>
</template>
