<script setup lang="ts">
/**
 * Форма сеанса — один компонент на два входа.
 *
 * Сеанс — это единственное место, где живут площадка, зал и дата: в `events`
 * таких колонок нет вообще (`venue_id`, `hall_id`, `starts_at` — NOT NULL в
 * `sessions`). Поэтому «назначить дату и площадку» — это всегда создание сеанса,
 * и форма должна быть одна, чтобы экраны не разъезжались.
 *
 * Раньше эта форма жила внутри `AdminSessionsPage.vue` и предлагала выбрать
 * ТОЛЬКО зал: площадку сервер выводил из зала сам, и в админке её нельзя было
 * выбрать нигде. Отсюда и жалоба «нет выбора площадки» — список залов был плоским
 * перечнем залов всех площадок сразу, без указания, чей зал.
 *
 * Порядок полей теперь отражает модель: Площадка → Зал (только её залы) →
 * схема зала → дата и время.
 */
import { computed, ref, watch } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NModal from '@/components/ui/NModal.vue'
import { ApiError, get, send } from '@/lib/api'
import {
  buildSessionPayload,
  emptySessionForm,
  splitDateTime,
  type SessionFormState,
  type SessionRecord,
} from '@/lib/sessionForm'

interface VenueOption {
  id: number
  name: string
}

interface HallOption {
  id: number
  name: string
  public_id?: string
  venue_id?: number
}

const props = withDefaults(
  defineProps<{
    open: boolean
    /** Сеанс для правки. `null` — создание. */
    session?: SessionRecord | null
    /** Мероприятие зафиксировано (форма открыта из карточки мероприятия). */
    lockEventId?: number | null
    lockEventTitle?: string | null
  }>(),
  { session: null, lockEventId: null, lockEventTitle: null },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const STATUS_OPTIONS = [
  { value: 'scheduled', label: 'Запланирован' },
  { value: 'on_sale', label: 'В продаже' },
  { value: 'held', label: 'Проведён' },
  { value: 'completed', label: 'Завершён' },
  { value: 'cancelled', label: 'Отменён' },
]

const form = ref<SessionFormState>(emptySessionForm())
const saving = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

const venues = ref<VenueOption[]>([])
const halls = ref<HallOption[]>([])
const events = ref<Array<{ id: number; title: string }>>([])
const optionsLoading = ref(false)
const optionsError = ref<string | null>(null)

const schemaVersions = ref<Array<{ id: number; version: number; status: string }>>([])
const schemaVersionsLoading = ref(false)

const isEdit = computed(() => props.session !== null)
/** Мероприятие зафиксировано — селект заменяется подписью. */
const eventLocked = computed(() => props.lockEventId !== null)

/* ── Справочники ─────────────────────────────────────────────────────────── */

/** `/venues` отдаёт пагинатор внутри `data`, `/halls` — плоский массив. */
function unwrapList<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[]
  const inner = (payload as { data?: unknown } | null)?.data
  return Array.isArray(inner) ? (inner as T[]) : []
}

async function loadOptions(): Promise<void> {
  optionsLoading.value = true
  optionsError.value = null

  try {
    const requests: Array<Promise<unknown>> = [
      get<unknown>('/venues'),
      get<unknown>('/halls'),
    ]

    // Справочник мероприятий нужен только там, где его можно выбрать.
    if (!eventLocked.value) requests.push(get<unknown>('/events?per_page=100'))

    const [venuesRes, hallsRes, eventsRes] = await Promise.all(requests)

    venues.value = unwrapList<VenueOption>((venuesRes as { data: unknown }).data)
    halls.value = unwrapList<HallOption>((hallsRes as { data: unknown }).data)
    if (eventsRes) {
      events.value = unwrapList<{ id: number; title: string }>((eventsRes as { data: unknown }).data)
    }
  } catch (err) {
    optionsError.value = err instanceof Error ? err.message : String(err)
  } finally {
    optionsLoading.value = false
  }
}

/**
 * Залы только выбранной площадки.
 *
 * Это и есть ответ на «нет выбора площадки»: плоский список залов позволял
 * выбрать зал, не понимая, где он находится, а сервер потом молча подставлял
 * площадку зала — или (до проверки на сервере) сохранял несогласованную пару.
 */
const hallOptions = computed<Array<{ value: string; label: string }>>(() => {
  const venueId = form.value.venue_id
  const list = venueId ? halls.value.filter((h) => String(h.venue_id ?? '') === venueId) : []

  if (!venueId) return [{ value: '', label: '— сначала выберите площадку —' }]

  if (list.length === 0) {
    return [{ value: '', label: '— у этой площадки нет залов —' }]
  }

  return [
    { value: '', label: '— выберите зал —' },
    ...list.map((h) => ({ value: String(h.id), label: h.name })),
  ]
})

const venueOptions = computed<Array<{ value: string; label: string }>>(() => [
  { value: '', label: optionsLoading.value ? 'Загрузка…' : '— выберите площадку —' },
  ...venues.value.map((v) => ({ value: String(v.id), label: v.name })),
])

const eventOptions = computed<Array<{ value: string; label: string }>>(() => [
  { value: '', label: '— выберите мероприятие —' },
  ...events.value.map((e) => ({ value: String(e.id), label: e.title })),
])

/** У выбранной площадки нет залов — сеанс создать не из чего. */
const venueHasNoHalls = computed(
  () => form.value.venue_id !== '' && hallOptions.value.length === 1,
)

/* ── Схемы зала ──────────────────────────────────────────────────────────── */

async function loadSchemaVersions(hallId: string): Promise<void> {
  schemaVersions.value = []
  if (!hallId) return

  const hall = halls.value.find((h) => String(h.id) === hallId)
  if (!hall?.public_id) return

  schemaVersionsLoading.value = true
  try {
    const res = await get<Array<{ id: number; version: number; status: string }>>(
      `/halls/${hall.public_id}/schema-versions`,
    )
    const list = Array.isArray(res.data) ? res.data : []
    // Для сеанса подходит только опубликованная схема: места генерируются из неё,
    // а сервер отказывает залу без опубликованной версии (422).
    schemaVersions.value = list.filter((v) => v.status === 'published')
  } catch {
    schemaVersions.value = []
  } finally {
    schemaVersionsLoading.value = false
  }
}

function onVenueChange(): void {
  // Зал принадлежит площадке, поэтому при её смене зал сбрасывается: оставить
  // прежний значило бы отправить на сервер несогласованную пару.
  form.value.hall_id = ''
  form.value.schema_version_id = ''
  schemaVersions.value = []
}

function onHallChange(): void {
  form.value.schema_version_id = ''
  void loadSchemaVersions(form.value.hall_id)
}

/* ── Инициализация ───────────────────────────────────────────────────────── */

function resetFromSession(): void {
  const s = props.session
  formError.value = null
  fieldErrors.value = {}
  schemaVersions.value = []

  if (!s) {
    form.value = emptySessionForm({
      event_id: props.lockEventId !== null ? String(props.lockEventId) : '',
      venue_id: '',
      hall_id: '',
      status: 'scheduled',
    })
    return
  }

  const start = splitDateTime(s.starts_at)
  const salesStart = splitDateTime(s.sales_start_at)
  const salesEnd = splitDateTime(s.sales_end_at)

  form.value = emptySessionForm({
    event_id: String(s.event_id ?? props.lockEventId ?? ''),
    // `venue_id` есть в ответе `/sessions` (это колонка), но если его не
    // прислали — выводим из зала, чтобы селект не выглядел пустым при живом зале.
    venue_id: String(s.venue_id ?? halls.value.find((h) => h.id === s.hall_id)?.venue_id ?? ''),
    hall_id: String(s.hall_id ?? ''),
    schema_version_id: s.schema_version_id != null ? String(s.schema_version_id) : '',
    starts_at: start.date,
    starts_time: start.time || '19:00',
    sales_start_at: salesStart.date,
    sales_start_time: salesStart.time || '00:00',
    sales_end_at: salesEnd.date,
    sales_end_time: salesEnd.time || '23:59',
    status: s.status ?? 'scheduled',
  })
}

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    resetFromSession()
    // Справочники грузим ДО вывода площадки из зала — иначе зал не найдётся.
    await loadOptions()
    if (props.session) resetFromSession()
    void loadSchemaVersions(form.value.hall_id)
  },
)

/* ── Сохранение ──────────────────────────────────────────────────────────── */

function close(): void {
  emit('update:open', false)
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = null
  fieldErrors.value = {}

  let payload: Record<string, unknown>
  try {
    payload = buildSessionPayload(form.value, { includeEvent: !eventLocked.value })
  } catch (err) {
    formError.value = err instanceof Error ? err.message : String(err)
    return
  }

  saving.value = true
  try {
    if (isEdit.value && props.session) {
      await send<unknown>(`/sessions/${props.session.id}`, 'PATCH', payload)
    } else {
      await send<unknown>('/sessions', 'POST', payload)
    }
    emit('saved')
    close()
  } catch (err) {
    if (err instanceof ApiError && err.details) {
      const mapped: Record<string, string> = {}
      for (const [field, messages] of Object.entries(err.details)) {
        const key = field.replace(/\..*$/, '')
        if (messages.length) mapped[key] = messages[0]
      }
      fieldErrors.value = mapped
      // Серверные сообщения о зале («зал принадлежит другой площадке», «у зала нет
      // опубликованной схемы») — единственные, которые объясняют отказ по делу,
      // поэтому показываем их как есть, а не общим «проверьте поля».
      formError.value = err.message || 'Сохранить сеанс не удалось.'
    } else {
      formError.value = err instanceof Error ? err.message : String(err)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <NModal
    :open="open"
    :title="isEdit ? 'Редактировать сеанс' : 'Новый сеанс'"
    description="Сеанс связывает мероприятие с площадкой, залом и датой. Именно из сеанса покупатель видит дату, зал и цены."
    size="md"
    @update:open="close"
  >
    <div
      v-if="formError"
      class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm leading-relaxed text-rose-400"
    >
      {{ formError }}
    </div>

    <div
      v-if="optionsError"
      class="mb-3 rounded-lg border border-sun-500/30 bg-sun-500/10 px-3 py-2 text-sm text-sun-400"
    >
      Справочники не загрузились: {{ optionsError }}
    </div>

    <form class="space-y-3" @submit.prevent="save">
      <!-- Мероприятие: зафиксировано, когда форму открыли из карточки события. -->
      <div v-if="eventLocked">
        <span class="mb-1.5 block text-sm font-medium text-content">Мероприятие</span>
        <p class="flex h-11 items-center rounded-lg border border-line bg-surface-2 px-3.5 text-sm text-muted">
          {{ lockEventTitle || `#${lockEventId}` }}
        </p>
      </div>
      <NSelect
        v-else
        v-model="form.event_id"
        label="Мероприятие"
        :options="eventOptions"
        :disabled="optionsLoading"
        :error="fieldErrors.event_id"
      />

      <!--
        Площадка. Раньше её здесь не было вообще: сервер выводил её из зала, и
        администратор выбирал зал из плоского списка всех залов всех площадок.
      -->
      <NSelect
        v-model="form.venue_id"
        label="Площадка"
        :options="venueOptions"
        :disabled="optionsLoading"
        :error="fieldErrors.venue_id"
        :hint="venues.length === 0 && !optionsLoading ? 'Площадок пока нет — добавьте её в разделе «Площадки».' : undefined"
        @update:model-value="onVenueChange"
      />

      <div v-if="venueHasNoHalls" class="rounded-lg border border-sun-500/30 bg-sun-500/10 px-3 py-2 text-xs leading-relaxed text-sun-400">
        У этой площадки нет залов, а сеанс без зала создать нельзя. Добавьте зал на странице площадки.
      </div>

      <NSelect
        v-model="form.hall_id"
        label="Зал"
        :options="hallOptions"
        :disabled="!form.venue_id || venueHasNoHalls"
        :error="fieldErrors.hall_id"
        @update:model-value="onHallChange"
      />

      <NSelect
        v-model="form.schema_version_id"
        :label="schemaVersionsLoading ? 'Схема зала (загрузка…)' : 'Схема зала'"
        :options="[
          { value: '', label: schemaVersionsLoading ? 'Загрузка…' : 'Авто (последняя опубликованная)' },
          ...schemaVersions.map(v => ({ value: String(v.id), label: `Версия ${v.version}` })),
        ]"
        :disabled="schemaVersionsLoading || !form.hall_id"
        hint="Из схемы создаются места. Если опубликованной схемы нет, сеанс создать нельзя."
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.starts_at" label="Дата *" type="date" :error="fieldErrors.starts_at" />
        <NInput v-model="form.starts_time" label="Время начала *" placeholder="19:00" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.sales_start_at" label="Старт продаж (дата)" type="date" />
        <NInput v-model="form.sales_start_time" label="Старт продаж (время)" placeholder="00:00" />
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <NInput v-model="form.sales_end_at" label="Окончание продаж (дата)" type="date" />
        <NInput v-model="form.sales_end_time" label="Окончание продаж (время)" placeholder="23:59" />
      </div>

      <NSelect v-model="form.status" label="Статус" :options="STATUS_OPTIONS" />

      <div class="flex justify-end gap-2 border-t border-line pt-3">
        <NButton variant="secondary" @click="close">Отмена</NButton>
        <NButton type="submit" variant="accent" :loading="saving">
          {{ isEdit ? 'Сохранить' : 'Создать сеанс' }}
        </NButton>
      </div>
    </form>
  </NModal>
</template>
