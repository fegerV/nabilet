/**
 * Форма сеанса: чистая логика, без Vue.
 *
 * Вынесено из компонента намеренно. Здесь единственное место, где форма
 * превращается в тело запроса, и ошибиться тут можно незаметно:
 *
 *  - `starts_at` собирается из ДВУХ полей (дата + время), и «дата есть, время
 *    пустое» — это не «сеанс без времени», а `19:00`-по-умолчанию;
 *  - `schema_version_id` нельзя отправить пустой строкой: правило `integer`
 *    отвечает VALIDATION_ERROR, поэтому пустая строка означает «поле не шлём,
 *    бэкенд возьмёт последнюю опубликованную схему зала»;
 *  - `venue_id` необязателен для сервера (он выводит его из зала), но мы его
 *    шлём всегда: пара «площадка + зал» проверяется на сервере, и лучше пусть
 *    проверка сработает на осмысленном значении, чем на выведенном.
 */

export interface SessionFormState {
  event_id: string
  venue_id: string
  hall_id: string
  schema_version_id: string
  starts_at: string
  starts_time: string
  sales_start_at: string
  sales_start_time: string
  sales_end_at: string
  sales_end_time: string
  status: string
}

/**
 * Сеанс, как его отдаёт `/api/v1/sessions`.
 *
 * Живёт здесь, а не в компоненте формы: тип нужен и списку сеансов, и карточке
 * мероприятия. Из `<script setup>` экспортировать тип нельзя, поэтому общий
 * тип обязан лежать в обычном модуле.
 */
export interface SessionRecord {
  id: number
  event_id: number
  venue_id?: number | null
  hall_id: number
  schema_version_id?: number | null
  starts_at: string
  sales_start_at?: string | null
  sales_end_at?: string | null
  status: string
  event?: { id: number; title: string } | null
  hall?: { id: number; name: string } | null
  venue?: { id: number; name: string } | null
}

export function emptySessionForm(overrides: Partial<SessionFormState> = {}): SessionFormState {
  return {
    event_id: '',
    venue_id: '',
    hall_id: '',
    schema_version_id: '',
    starts_at: '',
    starts_time: '19:00',
    sales_start_at: '',
    sales_start_time: '00:00',
    sales_end_at: '',
    sales_end_time: '23:59',
    status: 'scheduled',
    ...overrides,
  }
}

/** «2026-10-05 19:00:00» → { date: '2026-10-05', time: '19:00' } */
export function splitDateTime(value?: string | null): { date: string; time: string } {
  if (!value) return { date: '', time: '' }
  const iso = value.replace(' ', 'T')
  return { date: iso.slice(0, 10), time: iso.slice(11, 16) }
}

/** { date, time } → «YYYY-MM-DD HH:MM» или null, если дата не заполнена */
export function joinDateTime(date: string, time: string): string | null {
  if (!date) return null
  return `${date} ${time || '00:00'}`
}

export interface SessionPayloadOptions {
  /** Мероприятие зафиксировано (форма открыта из карточки мероприятия). */
  includeEvent?: boolean
}

/**
 * Тело запроса для `POST /sessions` или `PATCH /sessions/{id}`.
 *
 * Бросает `Error` с русским текстом, готовым к показу: проверки дублируют
 * серверные, но экономят круг по сети на очевидных случаях. Сообщения
 * намеренно говорят «выберите», а не «поле обязательно» — администратору нужно
 * действие, а не требование.
 */
export function buildSessionPayload(
  form: SessionFormState,
  options: SessionPayloadOptions = {},
): Record<string, unknown> {
  const includeEvent = options.includeEvent ?? true
  const startsAt = joinDateTime(form.starts_at, form.starts_time)

  if (includeEvent && !form.event_id) throw new Error('Выберите мероприятие.')
  if (!form.venue_id) throw new Error('Выберите площадку — от неё зависит список залов.')
  if (!form.hall_id) throw new Error('Выберите зал.')
  if (!startsAt) throw new Error('Укажите дату начала сеанса.')

  const salesStartAt = joinDateTime(form.sales_start_at, form.sales_start_time)

  if (form.status === 'on_sale' && !salesStartAt) {
    throw new Error('Для статуса «В продаже» укажите дату и время старта продаж.')
  }

  const payload: Record<string, unknown> = {
    venue_id: Number(form.venue_id),
    hall_id: Number(form.hall_id),
    starts_at: startsAt,
    status: form.status,
    sales_start_at: salesStartAt,
    sales_end_at: joinDateTime(form.sales_end_at, form.sales_end_time),
  }

  if (includeEvent) payload.event_id = Number(form.event_id)

  // Пустая строка — «авто»: бэкенд возьмёт последнюю опубликованную схему зала.
  if (form.schema_version_id !== '') payload.schema_version_id = Number(form.schema_version_id)

  return payload
}
