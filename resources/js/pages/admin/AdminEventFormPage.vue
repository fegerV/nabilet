<script setup lang="ts">
/**
 * Форма мероприятия (админка).
 *
 * Создание и редактирование события. GET /api/v1/events/{id} для заполнения,
 * POST /api/v1/events (создание) / PATCH /api/v1/events/{id} (обновление).
 * Статусы: draft → опубликовать (published) → архив.
 */
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const isEdit = computed(() => {
  const id = Number(route.params.id)
  return route.params.id !== undefined && !Number.isNaN(id)
})
const eventId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const form = ref({
  title: '',
  slug: '',
  description: '',
  short_description: '',
  status: 'draft',
  start_date: '',
  end_date: '',
  timezone: 'Europe/Moscow',
  currency: 'RUB',
  image_url: '',
  seo_title: '',
  seo_description: '',
})

const STATUS_OPTIONS = [
  { value: 'draft', label: 'Черновик' },
  { value: 'published', label: 'Опубликовано' },
  { value: 'archived', label: 'Архив' },
  { value: 'cancelled', label: 'Отменено' },
]

function makeSlug(title: string): string {
  return title
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9а-яё\s-]/gi, '')
    .replace(/[\s_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

function onTitle(): void {
  if (!form.value.slug) form.value.slug = makeSlug(form.value.title)
}

function resetForm(e: Record<string, unknown>): void {
  form.value = {
    title: String(e.title ?? ''),
    slug: String(e.slug ?? ''),
    description: String(e.description ?? ''),
    short_description: String(e.short_description ?? ''),
    status: String(e.status ?? 'draft'),
    start_date: String(e.start_date ?? ''),
    end_date: String(e.end_date ?? ''),
    timezone: String(e.timezone ?? 'Europe/Moscow'),
    currency: String(e.currency ?? 'RUB'),
    image_url: String(e.image_url ?? ''),
    seo_title: String(e.seo_title ?? ''),
    seo_description: String(e.seo_description ?? ''),
  }
}

async function load(): Promise<void> {
  if (!isEdit.value) {
    // Новая форма — сброс
    error.value = null
    resetForm({ status: 'draft', currency: 'RUB', timezone: 'Europe/Moscow' })
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: Record<string, unknown> }>(`/events/${eventId.value}`)
    resetForm(res.data)
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    loading.value = false
  }
}

watch(
  () => route.params.id,
  () => load(),
)

onMounted(load)

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  error.value = null
  try {
    const payload: Record<string, unknown> = {
      title: form.value.title.trim(),
      slug: form.value.slug.trim() || makeSlug(form.value.title),
      description: form.value.description.trim() || null,
      short_description: form.value.short_description.trim() || null,
      status: form.value.status,
      timezone: form.value.timezone,
      currency: form.value.currency,
      image_url: form.value.image_url.trim() || null,
      seo_title: form.value.seo_title.trim() || null,
      seo_description: form.value.seo_description.trim() || null,
    }
    // Даты: пустое значение пропускаем целиком (never null — валидация date не принимает null)
    if (form.value.start_date) payload.start_date = form.value.start_date
    if (form.value.end_date) payload.end_date = form.value.end_date
    if (isEdit.value) {
      await send<unknown>(`/events/${eventId.value}`, 'PATCH', payload)
      ui.notify('mint', 'Мероприятие обновлено', payload.title)
    } else {
      const res = await send<{ data: { id: number } }>('/events', 'POST', payload)
      ui.notify('mint', 'Мероприятие создано', payload.title)
      router.push(`/admin/events/${res.data.id}`)
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[860px]">
    <div class="mb-5">
      <button type="button" class="text-sm text-muted hover:text-content" @click="router.push('/admin/events')">← Мероприятия</button>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-content">{{ isEdit ? 'Редактировать мероприятие' : 'Новое мероприятие' }}</h1>
    </div>

    <div v-if="error" class="surface-card mb-4 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</div>
    <div v-if="loading" class="surface-card px-4 py-6 text-center text-sm text-muted">Загрузка…</div>

    <form v-else class="surface-card space-y-4 p-5" @submit.prevent="save">
      <NInput v-model="form.title" label="Название" placeholder="Концерт группы «Секрет»" required @update:model-value="onTitle" />
      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.slug" label="Slug (URL)" placeholder="koncert-sekret" hint="По нему строится SEO-адрес страницы" />
        <NSelect v-model="form.status" label="Статус" :options="STATUS_OPTIONS" />
      </div>
      <div class="w-full">
        <label class="mb-1.5 block text-sm font-medium text-content">Описание</label>
        <textarea
          v-model="form.description"
          rows="5"
          placeholder="Кратко о событии"
          class="w-full resize-y rounded-xl border border-transparent bg-surface-2 px-3.5 py-2.5 text-[15px] text-content placeholder:text-subtle focus:border-brand-500 focus:outline-none"
        ></textarea>
      </div>
      <div class="w-full">
        <label class="mb-1.5 block text-sm font-medium text-content">Краткое описание</label>
        <textarea
          v-model="form.short_description"
          rows="2"
          maxlength="500"
          placeholder="Для карточки (до 500 символов)"
          class="w-full resize-y rounded-xl border border-transparent bg-surface-2 px-3.5 py-2.5 text-[15px] text-content placeholder:text-subtle focus:border-brand-500 focus:outline-none"
        ></textarea>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.start_date" label="Дата начала" type="date" />
        <NInput v-model="form.end_date" label="Дата окончания" type="date" />
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.currency" label="Валюта" placeholder="RUB" maxlength="3" />
        <NInput v-model="form.image_url" label="Постер (URL)" placeholder="https://…/poster.jpg" />
      </div>
      <details class="rounded-lg border border-line p-3 text-sm">
        <summary class="cursor-pointer font-medium text-content">SEO</summary>
        <div class="mt-3 space-y-3">
          <NInput v-model="form.seo_title" label="SEO-title" placeholder="Концерт «Секрет» — билеты в Москве" maxlength="255" />
          <NTextarea v-model="form.seo_description" label="SEO-description" rows="2" maxlength="500" placeholder="Описание для поисковиков" />
        </div>
      </details>

      <div class="flex justify-end gap-2 border-t border-line pt-4">
        <NButton variant="secondary" @click="router.push('/admin/events')">Отмена</NButton>
        <NButton type="submit" variant="accent" :loading="saving">{{ isEdit ? 'Сохранить' : 'Создать' }}</NButton>
      </div>
    </form>
  </div>
</template>