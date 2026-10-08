<script setup lang="ts">
/**
 * Форма мероприятия (админка) — удобное создание/редактирование события.
 *
 * Секции: Основное · Афиша (drag&drop-загрузка файла ИЛИ URL, живое превью) ·
 * Дополнительные фото и видео · Сеансы (площадка, зал, дата) · Шаблон билета ·
 * Описание · SEO.
 * Создание: POST /api/v1/events (multipart при выбранном файле), обновление:
 * PATCH /api/v1/events/{id}. Ошибки валидации API (error.details.fields)
 * привязываются к конкретным полям формы.
 *
 * ПРО ДАТУ, ВРЕМЯ И ПЛОЩАДКУ. Их здесь нет и не может быть как полей карточки:
 * в таблице `events` таких колонок не существует, а `venue_id`, `hall_id` и
 * `starts_at` — NOT NULL в `sessions`. Одно мероприятие идёт несколько дней в
 * разных залах, и «дата мероприятия» без сеанса не определена. Поэтому форма не
 * притворяется, что хранит дату, а даёт создать сеанс на месте — секция
 * «Сеансы». Раньше отсюда вела только кнопка «К сеансам →» на другой экран, и
 * администратор не находил ответа на «где и когда» вообще.
 *
 * ПРО СТАТУС. Здесь его больше НЕЛЬЗЯ выбрать. Раньше в форме стоял
 * `<select>` со статусами, и на обновлении он ничего не делал:
 * `UpdateEventRequest` вырезает `status` из payload, потому что смена состояния
 * — не правка атрибута, а переход с проверками (`EventPublicationPolicy`:
 * без сеансов публиковать нельзя, с продажами отменять нельзя). Поле, которое
 * молча ничего не сохраняет, хуже отсутствующего — администратор выбирал
 * «Опубликовано», видел успех и оставался с черновиком. Теперь статус показан
 * как есть, а меняется кнопками, которые зовут `POST /events/{id}/publish` и
 * `/cancel` и показывают ответ политики.
 */
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import SessionFormModal from '@/components/admin/SessionFormModal.vue'
import EventGallerySection from '@/components/admin/EventGallerySection.vue'
import { useUiStore } from '@/stores/ui'
import { ApiError, get, request, send, upload } from '@/lib/api'
import { dateFull, time } from '@/lib/format'
import type { SessionRecord } from '@/lib/sessionForm'

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
  // '' — стандартный вид билета (`ticket_template_id = NULL`).
  ticket_template_id: '',
})

/* ── Факты, которые форма не редактирует ────────────────────────────────────
 * Читаются с сервера и показываются как есть. Держать их в `form` нельзя:
 * `fillFields()` отправляет всё, что лежит в `form`, обратно на сервер, и
 * `published_at` уехал бы в PATCH как поле, которое клиент не имеет права
 * назначать (публикация проставляет момент сама — см. EventPublicationService).
 */
const publishedAt = ref<string | null>(null)
const sessionCount = ref<number | null>(null)

/* ── Публикация ─────────────────────────────────────────────────────────── */
const publishing = ref(false)
const cancelling = ref(false)
const actionNotice = ref<{ tone: 'mint' | 'sun' | 'rose'; text: string } | null>(null)

/* ── Шаблоны билетов ────────────────────────────────────────────────────────
 * `ticket_templates` — модуль Tickets; список нужен, чтобы выбрать макет в
 * форме. Ошибка загрузки НЕ блокирует сохранение карточки: шаблон
 * необязателен (`ticket_template_id` nullable, NULL = стандартный вид).
 */
interface TemplateOption {
  id: number
  name: string
  active: boolean
}

const templates = ref<TemplateOption[]>([])
const templatesLoading = ref(false)
const templatesError = ref<string | null>(null)

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
    // `null` от сервера — это «стандартный вид билета», а не «неизвестно»:
    // в `<select>` ему соответствует пустая строка.
    ticket_template_id: e.ticket_template_id != null ? String(e.ticket_template_id) : '',
  }
  slugTouched.value = Boolean(form.value.slug)
  applyServerFacts(e)
}

/**
 * Обновить нередактируемые факты.
 *
 * `sessions` приходит только от `GET /events/{id}` (`whenLoaded`). Ответы
 * `publish`/`cancel` отдают `EventResource` от `fresh()` без загруженных связей,
 * поэтому отсутствие ключа означает «неизвестно», а не «ноль» — иначе счётчик
 * сеансов обнулялся бы сразу после публикации, ровно в тот момент, когда он
 * подтверждает, что публикация была осмысленной.
 */
function applyServerFacts(e: Record<string, unknown>): void {
  publishedAt.value = e.published_at != null ? String(e.published_at) : null
  if (Array.isArray(e.sessions)) sessionCount.value = e.sessions.length
}

/**
 * Список макетов билетов для выбора.
 *
 * Отдельный запрос, а не часть `GET /events/{id}`: справочник нужен и на
 * создании, где события ещё нет. Ошибка здесь не мешает сохранить карточку —
 * поэтому она попадает в `templatesError`, а не в общий `error`.
 */
async function loadTemplates(): Promise<void> {
  templatesLoading.value = true
  templatesError.value = null
  try {
    const res = await get<TemplateOption[]>('/ticket-templates')
    templates.value = Array.isArray(res.data) ? res.data : []
  } catch (err) {
    templates.value = []
    templatesError.value = err instanceof Error ? err.message : String(err)
  } finally {
    templatesLoading.value = false
  }
}

/* ── Сеансы: где и когда ────────────────────────────────────────────────────
 * Дата, время, площадка и зал живут в `sessions`, а не в `events`: в самой
 * таблице `events` таких колонок нет вообще, а в `sessions` они NOT NULL.
 * Значит, «назначить дату и площадку» — это всегда создание сеанса, и карточка
 * мероприятия обязана уметь это делать сама: без сеанса публикация отклоняется
 * (`NO_SESSIONS`), а покупателю нечего показать — ни даты, ни зала, ни цены.
 */
const sessions = ref<SessionRecord[]>([])
const sessionsLoading = ref(false)
const sessionsError = ref<string | null>(null)
const sessionModalOpen = ref(false)
const editingSession = ref<SessionRecord | null>(null)
/** Якорь для «настроить» в блоке готовности: не уводим со страницы. */
const sessionsSection = ref<HTMLElement | null>(null)

/**
 * Сеансы этого мероприятия.
 *
 * Счётчик готовности берётся ОТСЮДА, а не из `GET /events/{id}`: здесь приходит
 * весь список, и он же обновляется после создания сеанса. Ответ
 * `publish`/`cancel` отдаёт `EventResource` без загруженных связей, поэтому
 * опираться на него в счётчике нельзя.
 */
async function loadSessions(): Promise<void> {
  if (!isEdit.value) {
    sessions.value = []
    sessionCount.value = 0
    return
  }

  sessionsLoading.value = true
  sessionsError.value = null

  try {
    const res = await get<SessionRecord[]>(`/sessions?event_id=${eventId.value}&per_page=100`)
    // `/sessions` отдаёт пагинатор внутри `data`, а не плоский массив.
    const inner = res.data as unknown as { data?: SessionRecord[] } | SessionRecord[]
    sessions.value = Array.isArray(inner) ? inner : (inner.data ?? [])
    sessionCount.value = sessions.value.length
  } catch (err) {
    sessionsError.value = err instanceof Error ? err.message : String(err)
  } finally {
    sessionsLoading.value = false
  }
}

function openNewSession(): void {
  editingSession.value = null
  sessionModalOpen.value = true
}

function openEditSession(session: SessionRecord): void {
  editingSession.value = session
  sessionModalOpen.value = true
}

/**
 * После сохранения сеанса — обновить список и снять прошлый вердикт политики.
 * Вердикт снимается потому, что он относился к прошлому состоянию: «нет сеансов»
 * после создания сеанса уже неправда, и оставлять это сообщение на экране значит
 * утверждать то, чего больше нет.
 */
async function onSessionSaved(): Promise<void> {
  actionNotice.value = null
  await loadSessions()
}

/** Площадка сеанса — по названию: администратор читает имена, а не id. */
function venueNameOf(session: SessionRecord): string {
  return session.venue?.name ?? '—'
}

/** Показать секцию сеансов вместо перехода на другой экран. */
function scrollToSessions(): void {
  sessionsSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function load(): Promise<void> {
  setPosterFile(null)
  fieldErrors.value = {}
  actionNotice.value = null
  // Справочник шаблонов нужен обеим веткам, поэтому грузится параллельно и
  // не блокирует показ формы: `templatesLoading` рисует состояние в самом поле.
  void loadTemplates()
  // Сеансы — тоже параллельно: они нужны и для списка «где и когда», и для
  // счётчика готовности, но форма не должна их ждать.
  void loadSessions()

  if (!isEdit.value) {
    error.value = null
    resetForm({ status: 'draft' })
    // У нового мероприятия сеансов нет по определению. Явно, а не «оставить
    // как было»: переход из карточки существующего события в «Новое» не должен
    // показывать чужие два сеанса.
    sessionCount.value = 0
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
  set('age_limit', form.value.age_limit.trim() || null)
  set('duration_minutes', form.value.duration_minutes || null)
  set('seo_title', form.value.seo_title.trim() || null)
  set('seo_description', form.value.seo_description.trim() || null)
  // `null` — «стандартный вид билета». Отправлять сюда `''` нельзя: правило
  // `integer|nullable` на пустую строку отвечает VALIDATION_ERROR, и карточка
  // переставала бы сохраняться у того, кто просто не выбирал макет.
  set('ticket_template_id', form.value.ticket_template_id || null)
  // `status` НЕ отправляется. На обновлении его вырезает `UpdateEventRequest`
  // (смена состояния — отдельный эндпоинт с политикой), на создании у него
  // больше нет правила, поэтому он всё равно не дойдёт до модели. Слать поле,
  // которое сервер гарантированно игнорирует, значит поддерживать иллюзию, что
  // `<select>` в форме что-то решает.
}

/**
 * Сохранить карточку.
 *
 * @param goToSessions после создания уйти к сеансам
 * @param notify       показать уведомление об успехе
 * @returns true, если запись действительно прошла — на это опирается
 *          `publishFlow()`, который иначе публиковал бы несохранённую карточку.
 */
async function save(goToSessions = false, notify = true): Promise<boolean> {
  if (saving.value) return false
  error.value = null
  if (!validate()) {
    error.value = 'Проверьте выделенные поля.'
    return false
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
    if (notify) ui.notify('mint', isEdit.value ? 'Мероприятие обновлено' : 'Мероприятие создано', title)
    if (goToSessions) {
      router.push(`/admin/sessions?event=${savedId}`)
      return true
    }
    if (!isEdit.value) {
      router.push(`/admin/events/${savedId}`)
    }
    return true
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
    return false
  } finally {
    saving.value = false
  }
}

/* ── Готовность к продаже и переходы состояния ──────────────────────────── */

/** Макеты билетов для `<select>`: пустое значение — «стандартный вид». */
const templateOptions = computed<Array<{ value: string; label: string }>>(() => {
  const options = [{ value: '', label: 'Стандартный вид билета' }]
  for (const template of templates.value) {
    options.push({
      value: String(template.id),
      // Выключенный макет выбирать бессмысленно, но и прятать нельзя: он может
      // быть назначен событию прямо сейчас, и тогда `<select>` показал бы
      // пустое значение и «потерял» назначение при сохранении.
      label: template.active ? template.name : `${template.name} (выключен)`,
    })
  }
  return options
})

/** Выбранный макет, если он есть в списке — для строки готовности. */
const selectedTemplateName = computed<string | null>(() => {
  const id = form.value.ticket_template_id
  if (!id) return null
  return templates.value.find((t) => String(t.id) === id)?.name ?? null
})

const isPublished = computed(() => form.value.status === 'published')
const isCancelled = computed(() => form.value.status === 'cancelled')

/** Публиковать имеет смысл только сохранённое и неопубликованное. */
const canPublish = computed(() => isEdit.value && !isPublished.value && !isCancelled.value)
/** Отменять — сохранённое, ещё не отменённое. */
const canCancel = computed(() => isEdit.value && !isCancelled.value)

/**
 * Тексты отказов политики.
 *
 * Сообщения `EventPublicationPolicy` написаны по-английски и адресованы
 * разработчику («Event 12 has no sessions: publishing it would put up a page
 * with no date, no price and nothing to buy»). Показывать их администратору
 * нельзя, а решать по тексту нельзя тем более — поэтому ветвление идёт по
 * `error.code`, который политика и без того отдаёт (`strtoupper($verdict)`).
 */
const VERDICT_TEXT: Record<string, string> = {
  NO_SESSIONS:
    'Публикация отменена: у мероприятия нет ни одного сеанса. Пока нет сеанса, у страницы нет ни даты, ни цены, ни кнопки «Купить» — публиковать нечего. Создайте сеанс и вернитесь сюда.',
  EVENT_DELETED: 'Публикация отменена: мероприятие помечено удалённым. Сначала восстановите его.',
  PUBLISHED_WITHOUT_A_MOMENT:
    'Мероприятие опубликовано без даты публикации. Нажмите «Готова к продаже» ещё раз — дата проставится, и карта сайта получит корректный lastmod.',
  HAS_SOLD_UNITS:
    'Отмена запрещена: по мероприятию уже есть оплаченные заказы. Билеты нельзя аннулировать раньше, чем покупателям вернут деньги — сначала оформите возвраты.',
}

/**
 * Оформление ответа политики. Те же пары «фон/текст», что в `NToastHost`,
 * чтобы сообщение в форме и всплывающее уведомление читались как одно и то же.
 */
const NOTICE_CLASS: Record<'mint' | 'sun' | 'rose', string> = {
  mint: 'bg-mint-500/10 text-mint-400',
  sun: 'bg-sun-500/10 text-sun-400',
  rose: 'bg-rose-500/10 text-rose-400',
}

/**
 * Показать ответ политики на переход состояния.
 *
 * `no_change` — не ошибка и не «ничего не произошло»: политика сообщает, что
 * писать нечего, потому что состояние уже такое. Это важно показать, иначе
 * повторное нажатие выглядит как сломанная кнопка.
 */
function reportDecision(
  action: 'publish' | 'cancel',
  verdict: string,
  changed: boolean,
): void {
  if (verdict === 'no_change') {
    actionNotice.value = {
      tone: 'sun',
      text:
        action === 'publish'
          ? 'Мероприятие уже было опубликовано — повторной записи не потребовалось, дата публикации не переписана.'
          : 'Мероприятие уже отменено.',
    }
    return
  }

  if (verdict === 'scheduled') {
    actionNotice.value = {
      tone: 'sun',
      text: 'Мероприятие помечено опубликованным, но дата публикации в будущем — покупатели увидят кассу только с этой даты.',
    }
    return
  }

  actionNotice.value =
    action === 'publish'
      ? {
          tone: 'mint',
          text: changed
            ? 'Готово к продаже: мероприятие опубликовано, страница попала в афишу и в карту сайта.'
            : 'Мероприятие уже опубликовано.',
        }
      : { tone: 'mint', text: 'Мероприятие отменено.' }
}

/**
 * Перевести мероприятие в другое состояние.
 *
 * Глагол — `POST`, а не `PATCH`: это переход состояния, а не правка поля.
 * Ответ приходит с `meta.verdict` и `meta.changed`; отказ — 409 в конверте §66
 * с `error.code`, равным вердикту политики.
 */
async function changePublication(action: 'publish' | 'cancel'): Promise<void> {
  if (!isEdit.value || publishing.value || cancelling.value) return
  if (action === 'publish') publishing.value = true
  else cancelling.value = true
  actionNotice.value = null

  try {
    const res = await request<{
      data: Record<string, unknown>
      meta?: { verdict?: string; changed?: boolean }
    }>(`/events/${eventId.value}/${action}`, { method: 'POST' })

    const verdict = res.meta?.verdict ?? 'allowed'
    resetForm(res.data)
    applyServerFacts(res.data)
    // Отказ политики по «нет сеансов» — единственный случай, когда счётчик
    // сеансов заведомо известен, даже если `sessions` не пришёл.
    if (action === 'publish' && verdict === 'allowed') sessionCount.value = sessionCount.value ?? 0

    reportDecision(action, verdict, res.meta?.changed ?? false)

    if (verdict === 'allowed') {
      ui.notify(
        'mint',
        action === 'publish' ? 'Готово к продаже' : 'Мероприятие отменено',
        form.value.title,
      )
    }
  } catch (err) {
    if (err instanceof ApiError && err.code && VERDICT_TEXT[err.code]) {
      actionNotice.value = { tone: 'rose', text: VERDICT_TEXT[err.code]! }
      if (err.code === 'NO_SESSIONS') sessionCount.value = 0
    } else if (err instanceof ApiError) {
      actionNotice.value = { tone: 'rose', text: err.message }
    } else {
      actionNotice.value = {
        tone: 'rose',
        text: err instanceof Error ? err.message : String(err),
      }
    }
  } finally {
    publishing.value = false
    cancelling.value = false
  }
}

/**
 * «Готова к продаже»: сначала сохранить карточку, потом публиковать.
 *
 * Публикация не требует сохранения — но администратор, поправивший название и
 * сразу нажавший «Готова к продаже», ожидает, что поправка уйдёт вместе с
 * публикацией. Без этого шага он увидел бы опубликованное событие со старым
 * названием и не понял бы, почему.
 */
async function publishFlow(): Promise<void> {
  if (publishing.value || saving.value) return
  // `notify = false`: одно действие — одно уведомление. Второе придёт от
  // `changePublication()` и будет про публикацию, а не про сохранение.
  if (!(await save(false, false))) return
  await changePublication('publish')
}

/** Отмена — необратимо для покупателя, поэтому подтверждение. */
async function cancelFlow(): Promise<void> {
  if (cancelling.value || saving.value) return
  if (!window.confirm('Отменить мероприятие? Покупатели перестанут видеть кассу. Действие затронет страницу события.')) {
    return
  }
  await changePublication('cancel')
}

/** Дата публикации в читаемом виде. */
const publishedAtText = computed<string | null>(() => {
  if (!publishedAt.value) return null
  const date = new Date(publishedAt.value)
  if (Number.isNaN(date.getTime())) return publishedAt.value
  return date.toLocaleString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
})
</script>

<template>
  <div class="mx-auto max-w-[860px]">
    <div class="mb-5">
      <button type="button" class="text-sm text-muted hover:text-content" @click="router.push('/admin/events')">← Мероприятия</button>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-content">{{ isEdit ? 'Редактировать мероприятие' : 'Новое мероприятие' }}</h1>
      <p v-if="!isEdit" class="mt-1 text-sm text-muted">
        Заполните карточку. Сеансы — площадка, зал и дата — появятся здесь же после сохранения.
      </p>
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
            <!--
              Статус показан, но НЕ выбирается. Раньше здесь стоял `<select>`,
              который на обновлении ничего не сохранял (`UpdateEventRequest`
              вырезает `status`): администратор выбирал «Опубликовано», видел
              «Мероприятие обновлено» и оставался с черновиком. Смена состояния
              — отдельный переход с проверками, поэтому кнопками ниже.
            -->
            <div>
              <span class="mb-1.5 block text-sm font-medium text-content">Статус</span>
              <div class="flex h-11 flex-wrap items-center gap-2">
                <NStatusBadge kind="event" :status="form.status" size="md" />
                <span v-if="publishedAtText" class="text-xs text-subtle">с {{ publishedAtText }}</span>
              </div>
              <p class="mt-1.5 text-xs text-subtle">
                <template v-if="isEdit">Меняется кнопками в блоке «Готова к продаже» ниже.</template>
                <template v-else>Новое мероприятие создаётся черновиком — публикация отдельным шагом.</template>
              </p>
            </div>
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

      <!--
        ── Дополнительные фото и видео ──
        Раздела не было вообще: «Дополнительные фото и видео» отсутствовало
        полностью, а таблица `media_links` — та, для этого и предназначенная, —
        стояла пустой, потому что модуль Media не отдавал ни одного маршрута.
        Только в режиме правки: у нового мероприятия ещё нет id, к которому
        можно привязать файл.
      -->
      <EventGallerySection v-if="isEdit && eventId" :event-id="eventId" />

      <!--
        ── Сеансы: где и когда ──
        Требование звучало как «на этой странице нет выбора площадки, назначения
        даты и времени». Колонок для них в `events` не существует (см. docblock),
        поэтому здесь не поля, а список сеансов и создание нового — то есть тот
        же путь, что и на экране «Сеансы», но не покидая карточку.
      -->
      <section v-if="isEdit" ref="sessionsSection" class="rounded-xl border border-line p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-xs font-semibold uppercase tracking-wide text-subtle">Сеансы — где и когда</h2>
          <NButton variant="secondary" size="sm" @click="openNewSession">Добавить сеанс</NButton>
        </div>

        <p class="mb-3 text-xs leading-relaxed text-subtle">
          Площадка, зал и дата хранятся в сеансах, а не в карточке: одно мероприятие может
          идти несколько дней в разных залах. Из сеанса покупатель видит дату, зал и цены.
        </p>

        <p
          v-if="sessionsError"
          class="mb-3 rounded-lg border border-sun-500/30 bg-sun-500/10 px-3 py-2 text-xs leading-relaxed text-sun-400"
        >
          Список сеансов не загрузился: {{ sessionsError }}
        </p>

        <p v-else-if="sessionsLoading" class="text-sm text-muted">Загрузка сеансов…</p>

        <p
          v-else-if="sessions.length === 0"
          class="rounded-lg border border-dashed border-line bg-surface-2 px-3 py-4 text-sm leading-relaxed text-muted"
        >
          Сеансов нет — у мероприятия пока нет ни даты, ни зала, и опубликовать его нельзя:
          покупателю нечего показать. Нажмите
          <b class="text-content">«Добавить сеанс»</b> и выберите площадку, зал и дату.
        </p>

        <ul v-else class="divide-y divide-line">
          <li v-for="s in sessions" :key="s.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
            <button type="button" class="min-w-0 flex-1 text-left" @click="openEditSession(s)">
              <span class="block text-sm font-medium text-content tabular-nums">
                {{ dateFull(s.starts_at) }}, {{ time(s.starts_at) }}
              </span>
              <span class="block truncate text-xs text-muted">
                {{ venueNameOf(s) }}<template v-if="s.hall?.name"> · {{ s.hall.name }}</template>
              </span>
            </button>
            <NStatusBadge kind="session" :status="s.status" />
            <NButton variant="ghost" size="sm" @click="router.push(`/admin/sessions/${s.id}/prices`)">Цены</NButton>
          </li>
        </ul>
      </section>

      <!-- ── Шаблон билета ── -->
      <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Шаблон билета</h2>
        <NSelect
          v-model="form.ticket_template_id"
          label="Макет"
          :options="templateOptions"
          :disabled="templatesLoading"
          :hint="templatesLoading ? 'Загружаем список макетов…' : 'Макет попадает в письмо с билетом и на страницу билета. «Стандартный вид» — когда своего макета нет.'"
        />
        <!--
          Ошибка загрузки справочника НЕ блокирует сохранение: шаблон
          необязателен (NULL = стандартный вид). Поэтому это отдельная строка,
          а не общий `error` в шапке формы.
        -->
        <p v-if="templatesError" class="mt-2 text-xs text-sun-400">
          Список макетов не загрузился: {{ templatesError }}. Карточку это сохранить не мешает — макет можно выбрать позже.
        </p>
        <p v-else-if="!templatesLoading && templates.length === 0" class="mt-2 text-xs text-subtle">
          Своих макетов пока нет —
          <button type="button" class="text-brand-500 hover:underline" @click="router.push('/admin/ticket-templates')">собрать макет билета</button>.
        </p>
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

      <!--
        ── Готова к продаже ──
        Требование звучало как «кнопки нет, есть только поле в теле запроса».
        Поле убрано (см. `fillFields()`), кнопка появилась здесь, а вместе с ней
        — три факта, по которым администратор понимает, ПОЧЕМУ публикация
        пройдёт или не пройдёт. Без них отказ политики («нет сеансов»)
        выглядел бы как поломка, а не как ответ на состояние события.
      -->
      <section v-if="isEdit" class="rounded-xl border border-line p-4">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Готова к продаже</h2>

        <dl class="mb-4 space-y-2 text-sm">
          <div class="flex flex-wrap gap-x-2">
            <dt class="w-44 shrink-0 text-muted">Сеансы</dt>
            <dd class="text-content">
              <span v-if="sessionCount === null" class="text-subtle">—</span>
              <span v-else-if="sessionCount === 0" class="text-sun-400">нет — публиковать нечего</span>
              <span v-else>
                {{ sessionCount }} ·
                <button type="button" class="text-brand-500 hover:underline" @click="scrollToSessions">настроить</button>
              </span>
            </dd>
          </div>
          <div class="flex flex-wrap gap-x-2">
            <dt class="w-44 shrink-0 text-muted">Опубликовано</dt>
            <dd class="text-content">
              <span v-if="publishedAtText">{{ publishedAtText }}</span>
              <span v-else class="text-subtle">ещё не публиковалось</span>
            </dd>
          </div>
          <div class="flex flex-wrap gap-x-2">
            <dt class="w-44 shrink-0 text-muted">Шаблон билета</dt>
            <dd class="text-content">
              <span v-if="selectedTemplateName">{{ selectedTemplateName }}</span>
              <span v-else class="text-subtle">стандартный вид</span>
            </dd>
          </div>
        </dl>

        <p v-if="actionNotice" class="mb-3 rounded-lg px-3 py-2 text-sm leading-relaxed" :class="NOTICE_CLASS[actionNotice.tone]">
          {{ actionNotice.text }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
          <NButton v-if="canPublish" variant="primary" :loading="publishing" :disabled="saving" @click="publishFlow">
            Готова к продаже
          </NButton>
          <NButton v-if="canCancel" variant="ghost" :loading="cancelling" :disabled="saving" @click="cancelFlow">
            Отменить мероприятие
          </NButton>
          <p v-if="!canPublish && !canCancel" class="text-sm text-muted">
            Мероприятие отменено — продажа закрыта, покупатели кассу не видят.
          </p>
        </div>

        <p class="mt-3 text-xs leading-relaxed text-subtle">
          Публикация запускает проверки: без сеанса (дата, зал, цены) страница вышла бы без даты и без кнопки «Купить».
          «Готова к продаже» сначала сохраняет карточку, потом публикует.
        </p>
      </section>

      <aside v-else class="rounded-xl border border-line bg-surface-2 px-4 py-3 text-xs leading-relaxed text-muted">
        Чтобы продавать билеты: создайте <b class="text-content">сеанс</b> (дата, время, зал, схема),
        установите <b class="text-content">цены на ряды</b> и нажмите
        <b class="text-content">«Готова к продаже»</b> на странице мероприятия. Без опубликованного сеанса покупатели не увидят кассу.
      </aside>

      <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
        <NButton variant="secondary" @click="router.push('/admin/events')">Отмена</NButton>
        <NButton v-if="isEdit" variant="ghost" @click="router.push(`/admin/sessions?event=${eventId}`)">К сеансам →</NButton>
        <NButton type="submit" variant="accent" :loading="saving">{{ isEdit ? 'Сохранить' : 'Создать' }}</NButton>
        <NButton v-if="!isEdit" variant="primary" :loading="saving" @click.prevent="save(true)">Создать и настроить сеанс →</NButton>
      </div>
    </form>

    <!--
      Форма сеанса та же, что на экране «Сеансы»: держать вторую копию нельзя —
      они разъедутся ровно так же, как разъехались выбор зала и вывод площадки
      на сервере. `lock-event-id` фиксирует мероприятие: мы уже в его карточке.
    -->
    <SessionFormModal
      v-model:open="sessionModalOpen"
      :session="editingSession"
      :lock-event-id="eventId"
      :lock-event-title="form.title"
      @saved="onSessionSaved"
    />
  </div>
</template>
