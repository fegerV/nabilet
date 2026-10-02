<script setup lang="ts">
/**
 * Форма мероприятия (админка) — удобное создание/редактирование события.
 *
 * Секции: Основное · Афиша (drag&drop-загрузка файла ИЛИ URL, живое превью) ·
 * Описание · Статус и SEO. Создание: POST /api/v1/events (multipart при
 * выбранном файле), обновление: PATCH /api/v1/events/{id}. Ошибки валидации
 * API (error.details.fields) привязываются к конкретным полям формы.
 *
 * Про жизненный цикл: дата/время и цены живут не в событии, а в сеансах —
 * после создания предлагаем перейти к сеансам и ценам (кнопка-подсказка).
 */
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import { useUiStore } from '@/stores/ui'
import { ApiError, get, send, upload } from '@/lib/api'

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
const fieldErrors = ref<Record<string, string>>({})

const form = ref({
  title: '',
  slug: '',
  description: '',
  short_description: '',
  status: 'draft',
  poster: '',
  age_limit: '',
  duration_minutes: '',
  seo_title: '',
  seo_description: '',
})

/* ── Афиша: файл + URL + превью ─────────────────────────────────────────── */
const posterFile = ref<File | null>(null)
const posterPreview = ref<string>('')          // локальный object-URL выбранного файла
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const posterError = ref<string | null>(null)

const POSTER_MAX_BYTES = 5 * 1024 * 1024
const POSTER_TYPES = ['image/jpeg', 'image/png', 'image/webp']

function setPosterFile(file: File | null): void {
  posterError.value = null
  if (posterPreview.value) {
    URL.revokeObjectURL(posterPreview.value)
    posterPreview.value = ''
  }
  posterFile.value = null
  if (!file) return

  if (!POSTER_TYPES.includes(file.type)) {
    posterError.value = 'Поддерживаются только jpg, png и webp.'
    return
  }
  if (file.size > POSTER_MAX_BYTES) {
    posterError.value = `Файл ${(file.size / 1024 / 1024).toFixed(1)} МБ превышает лимит 5 МБ.`
    return
  }
  posterFile.value = file
  posterPreview.value = URL.createObjectURL(file)
}

function onPosterInputChange(e: Event): void {
  const input = e.target as HTMLInputElement
  setPosterFile(input.files && input.files[0] ? input.files[0] : null)
}

function onDrop(e: DragEvent): void {
  dragOver.value = false
  const file = e.dataTransfer?.files?.[0] ?? null
  setPosterFile(file)
  if (fileInput.value) fileInput.value.value = ''
}

function clearPoster(): void {
  setPosterFile(null)
  form.value.poster = ''
  posterError.value = null
  if (fileInput.value) fileInput.value.value = ''
}

/** Что показывать в превью: выбранный файл важнее сохранённого URL. */
const posterShown = computed(() => posterPreview.value || form.value.poster)

/* ── Slug-автогенерация ─────────────────────────────────────────────────── */
const slugTouched = ref(false)

function makeSlug(title: string): string {
  return title
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9а-яё\s-]/gi, '')
    .replace(/[\s_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

watch(
  () => form.value.title,
  (title) => {
    if (!slugTouched.value) form.value.slug = makeSlug(title)
  },
)

function regenerateSlug(): void {
  form.value.slug = makeSlug(form.value.title)
}

/* ── Счётчики символов ──────────────────────────────────────────────────── */
function len(v: string): number {
  return v.trim().length
}

/* ── Загрузка/сброс ─────────────────────────────────────────────────────── */
function resetForm(e: Record<string, unknown>): void {
  form.value = {
    title: String(e.title ?? ''),
    slug: String(e.slug ?? ''),
    description: String(e.description ?? ''),
    short_description: String(e.short_description ?? ''),
    status: String(e.status ?? 'draft'),
    // FIX: API отдаёт поле `poster` (колонка БД), а форма читала image_url —
    // после сохранения превью афиши терялось.
    poster: String(e.poster ?? e.image_url ?? ''),
    age_limit: String(e.age_limit ?? ''),
    duration_minutes: e.duration_minutes != null ? String(e.duration_minutes) : '',
    seo_title: String(e.seo_title ?? ''),
    seo_description: String(e.seo_description ?? ''),
  }
  slugTouched.value = Boolean(form.value.slug)
}

async function load(): Promise<void> {
  setPosterFile(null)
  fieldErrors.value = {}
  if (!isEdit.value) {
    error.value = null
    resetForm({ status: 'draft' })
    loading.value = false
    return
  }
  loading.value = true
  error.value = null
  try {
    // GET /events/{id} отдаёт { data: {...} }; get<D>() типизирован как
    // Promise<{ data: D; meta? }>, поэтому D — сам объект события.
    const res = await get<Record<string, unknown>>(`/events/${eventId.value}`)
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

/* ── Клиентская валидация ───────────────────────────────────────────────── */
function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.title.trim()) errs.title = 'Укажите название мероприятия.'
  if (!form.value.slug.trim()) errs.slug = 'Slug обязателен — по нему строится адрес страницы.'
  else if (!/^[a-z0-9а-яё-]+$/.test(form.value.slug.trim())) errs.slug = 'Slug: только буквы, цифры и дефисы.'
  if (len(form.value.short_description) > 500) errs.short_description = 'Не больше 500 символов.'
  if (form.value.duration_minutes && (!/^\d+$/.test(form.value.duration_minutes) || Number(form.value.duration_minutes) < 1)) {
    errs.duration_minutes = 'Продолжительность — целое число минут (≥ 1).'
  }
  if (!posterFile.value && form.value.poster.trim()) {
    try {
      new URL(form.value.poster.trim())
    } catch {
      errs.poster = 'Ссылка на афишу должна быть полным URL (https://…).'
    }
  }
  fieldErrors.value = errs
  return Object.keys(errs).length === 0
}

/* ── Сохранение ─────────────────────────────────────────────────────────── */
interface SavedEvent {
  id: number
}

/** Текстовые поля формы → в FormData или JSON-объект одним проходом. */
function fillFields(set: (k: string, v: string | null) => void): void {
  set('title', form.value.title.trim())
  set('slug', form.value.slug.trim() || makeSlug(form.value.title))
  set('description', form.value.description.trim() || null)
  set('short_description', form.value.short_description.trim() || null)
  set('status', form.value.status)
  set('age_limit', form.value.age_limit.trim() || null)
  set('duration_minutes', form.value.duration_minutes || null)
  set('seo_title', form.value.seo_title.trim() || null)
  set('seo_description', form.value.seo_description.trim() || null)
}

async function save(goToSessions = false): Promise<void> {
  if (saving.value) return
  error.value = null
  if (!validate()) {
    error.value = 'Проверьте выделенные поля.'
    return
  }
  saving.value = true
  try {
    let res: { data: SavedEvent }
    if (posterFile.value) {
      // Multipart: файл афиши + текстовые поля одним запросом (poster_file
      // имеет приоритет над URL — см. StoreEventRequest::storeUploadedPoster()).
      const fd = new FormData()
      fillFields((k, v) => {
        if (v !== null) fd.append(k, v)
      })
      fd.append('poster_file', posterFile.value, posterFile.value.name)
      res = isEdit.value
        ? await upload<SavedEvent>(`/events/${eventId.value}`, fd, 'PATCH')
        : await upload<SavedEvent>('/events', fd, 'POST')
    } else {
      const payload: Record<string, string | null> = {}
      fillFields((k, v) => {
        payload[k] = v
      })
      payload.poster = form.value.poster.trim() || null
      res = isEdit.value
        ? await send<SavedEvent>(`/events/${eventId.value}`, 'PATCH', payload)
        : await send<SavedEvent>('/events', 'POST', payload)
    }
    const savedId = res.data.id
    const title = form.value.title.trim()
    ui.notify('mint', isEdit.value ? 'Мероприятие обновлено' : 'Мероприятие создано', title)
    if (goToSessions) {
      router.push(`/admin/sessions?event=${savedId}`)
      return
    }
    if (!isEdit.value) {
      router.push(`/admin/events/${savedId}`)
    }
  } catch (err) {
    if (err instanceof ApiError && err.details) {
      const mapped: Record<string, string> = {}
      for (const [field, messages] of Object.entries(err.details)) {
        const key = field === 'poster_file' ? 'poster' : field.replace(/\..*$/, '')
        if (messages.length) mapped[key] = messages[0]
      }
      fieldErrors.value = mapped
      error.value = err.message || 'Сохранить не удалось — проверьте выделенные поля.'
    } else {
      fieldErrors.value = {}
      error.value = err instanceof Error ? err.message : String(err)
    }
  } finally {
    saving.value = false
  }
}

const STATUS_OPTIONS = [
  { value: 'draft', label: 'Черновик' },
  { value: 'published', label: 'Опубликовано' },
  { value: 'archived', label: 'Архив' },
  { value: 'cancelled', label: 'Отменено' },
]
</script>

<template>
  <div class="mx-auto max-w-[860px]">
    <div class="mb-5">
      <button type="button" class="text-sm text-muted hover:text-content" @click="router.push('/admin/events')">← Мероприятия</button>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-content">{{ isEdit ? 'Редактировать мероприятие' : 'Новое мероприятие' }}</h1>
      <p v-if="!isEdit" class="mt-1 text-sm text-muted">Заполните карточку — даты, зал и цены настраиваются отдельно, в сеансах.</p>
    </div>

    <div v-if="error" class="surface-card mb-4 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</div>
    <div v-if="loading" class="surface-card px-4 py-6 text-center text-sm text-muted">Загрузка…</div>

    <form v-else class="surface-card space-y-6 p-5" @submit.prevent="save(false)">
      <!-- ── Основное ── -->
      <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Основное</h2>
        <div class="space-y-4">
          <NInput v-model="form.title" label="Название *" placeholder="Концерт группы «Секрет»" :error="fieldErrors.title" />
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <NInput v-model="form.slug" label="Slug (URL) *" placeholder="koncert-sekret" :error="fieldErrors.slug" hint="По нему строится адрес страницы" @update:model-value="slugTouched = true" />
              <button type="button" class="mt-1 text-xs text-brand-500 hover:underline" @click="regenerateSlug">Сгенерировать из названия</button>
            </div>
            <NSelect v-model="form.status" label="Статус" :options="STATUS_OPTIONS" />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <NInput v-model="form.age_limit" label="Возрастное ограничение" placeholder="16+" :error="fieldErrors.age_limit" />
            <NInput v-model="form.duration_minutes" label="Продолжительность, мин" type="number" placeholder="90" :error="fieldErrors.duration_minutes" />
          </div>
        </div>
      </section>

      <!-- ── Афиша ── -->
      <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Афиша концерта</h2>
        <div class="grid gap-4 sm:grid-cols-[220px_1fr]">
          <div
            class="flex min-h-[280px] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border border-dashed border-line bg-surface-2 text-center transition hover:border-brand-500"
            :class="{ 'border-brand-500 bg-brand-500/5': dragOver }"
            role="button"
            tabindex="0"
            @click="fileInput?.click()"
            @keydown.enter.prevent="fileInput?.click()"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
          >
            <img v-if="posterShown" :src="posterShown" alt="Афиша" class="h-full w-full object-cover" @error="posterError = 'Изображение не загрузилось — проверьте ссылку.'" />
            <div v-else class="px-4 py-6 text-sm text-muted">
              <span class="mb-1 block text-2xl" aria-hidden="true">🖼</span>
              Перетащите файл сюда<br />или нажмите для выбора
              <span class="mt-1 block text-xs text-subtle">jpg / png / webp, до 5 МБ</span>
            </div>
          </div>
          <div class="space-y-3">
            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPosterInputChange" />
            <p v-if="posterFile" class="text-sm text-content">
              Выбран файл: <b>{{ posterFile.name }}</b>
              <span class="text-xs text-subtle">({{ (posterFile.size / 1024 / 1024).toFixed(2) }} МБ, будет загружен при сохранении)</span>
            </p>
            <NInput v-model="form.poster" label="…или ссылка на афишу (URL)" placeholder="https://…/poster.jpg" :error="fieldErrors.poster || posterError || undefined" :disabled="Boolean(posterFile)" />
            <p v-if="posterFile" class="text-xs text-subtle">Пока выбран файл, ссылка игнорируется — файл имеет приоритет.</p>
            <button v-if="posterShown" type="button" class="block text-xs text-rose-400 hover:underline" @click="clearPoster">Убрать афишу</button>
          </div>
        </div>
      </section>

      <!-- ── Описание ── -->
      <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Описание</h2>
        <div class="space-y-4">
          <div class="w-full">
            <label class="mb-1.5 block text-sm font-medium text-content">Краткое описание <span class="text-xs text-subtle">для карточки, до 500 символов · {{ len(form.short_description) }}/500</span></label>
            <textarea
              v-model="form.short_description"
              rows="2"
              maxlength="500"
              placeholder="Хиты 80-х в симфонической аранжировке"
              class="w-full resize-y rounded-xl border bg-surface-2 px-3.5 py-2.5 text-[15px] text-content placeholder:text-subtle focus:outline-none"
              :class="fieldErrors.short_description ? 'border-rose-500/60' : 'border-transparent focus:border-brand-500'"
            ></textarea>
            <p v-if="fieldErrors.short_description" class="mt-1 text-xs text-rose-400">{{ fieldErrors.short_description }}</p>
          </div>
          <div class="w-full">
            <label class="mb-1.5 block text-sm font-medium text-content">Полное описание</label>
            <textarea
              v-model="form.description"
              rows="6"
              placeholder="Подробное описание программы, состава, особенностей вечера…"
              class="w-full resize-y rounded-xl border bg-surface-2 px-3.5 py-2.5 text-[15px] text-content placeholder:text-subtle focus:outline-none"
              :class="fieldErrors.description ? 'border-rose-500/60' : 'border-transparent focus:border-brand-500'"
            ></textarea>
            <p v-if="fieldErrors.description" class="mt-1 text-xs text-rose-400">{{ fieldErrors.description }}</p>
          </div>
        </div>
      </section>

      <!-- ── SEO ── -->
      <details class="rounded-lg border border-line p-3 text-sm">
        <summary class="cursor-pointer font-medium text-content">SEO <span class="text-xs font-normal text-subtle">(необязательно)</span></summary>
        <div class="mt-3 space-y-3">
          <NInput v-model="form.seo_title" label="SEO-title" placeholder="Концерт «Секрет» — билеты в Москве" maxlength="255" :hint="`${len(form.seo_title)}/255`" />
          <div class="w-full">
            <label class="mb-1.5 block text-sm font-medium text-content">SEO-description <span class="text-xs text-subtle">{{ len(form.seo_description) }}/500</span></label>
            <textarea
              v-model="form.seo_description"
              rows="2"
              maxlength="500"
              placeholder="Описание для поисковых систем"
              class="w-full resize-y rounded-xl border border-transparent bg-surface-2 px-3.5 py-2.5 text-[15px] text-content placeholder:text-subtle focus:border-brand-500 focus:outline-none"
            ></textarea>
          </div>
        </div>
      </details>

      <!-- ── Подсказка о жизненном цикле ── -->
      <aside class="rounded-xl border border-line bg-surface-2 px-4 py-3 text-xs leading-relaxed text-muted">
        Чтобы продавать билеты: создайте <b class="text-content">сеанс</b> (дата, время, зал, схема),
        установите <b class="text-content">цены на ряды</b> и переведите событие в статус
        <b class="text-content">«Опубликовано»</b>. Без опубликованного сеанса покупатели не увидят кассу.
      </aside>

      <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
        <NButton variant="secondary" @click="router.push('/admin/events')">Отмена</NButton>
        <NButton v-if="isEdit" variant="ghost" @click="router.push(`/admin/sessions?event=${eventId}`)">К сеансам →</NButton>
        <NButton type="submit" variant="accent" :loading="saving">{{ isEdit ? 'Сохранить' : 'Создать' }}</NButton>
        <NButton v-if="!isEdit" variant="primary" :loading="saving" @click.prevent="save(true)">Создать и настроить сеанс →</NButton>
      </div>
    </form>
  </div>
</template>
