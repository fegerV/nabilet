/**
 * Чистая логика конструктора билета (вынесена из TicketBuilder.vue).
 *
 * В компоненте должны остаться только реактивное состояние и рендер.
 * Здесь — сериализация шаблона, клонирование/дефолты элементов, подстановка
 * переменных и генерация URL QR-кода: всё без Vue и DOM, поэтому это можно
 * тестировать в vitest (см. tests/Unit/TicketBuilder/ticketBuilder.test.ts).
 */

export interface BuilderElement {
  id: number | string
  type: 'text' | 'rectangle' | 'circle' | 'line' | 'qr' | 'image' | 'barcode' | string
  x: number
  y: number
  rotation?: number
  opacity?: number
  [key: string]: unknown
}

export interface CanvasConfig {
  backgroundColor: string
  elements: BuilderElement[]
}

export interface TicketVariables {
  event: { name: string; date: string; time: string; venue: string }
  ticket: { number: string; holder: string }
  seat: { row: string; number: string; info: string }
}

/** Пресеты размера холста — единственный источник истины для select в тулбаре. */
export const CANVAS_PRESETS: Record<string, { width: number; height: number }> = {
  mobile: { width: 400, height: 600 },
  desktop: { width: 600, height: 400 },
  square: { width: 500, height: 500 },
  wide: { width: 800, height: 400 },
}

export const DEFAULT_TICKET_VARIABLES: TicketVariables = {
  event: { name: 'Название события', date: '01.01.2024', time: '19:00', venue: 'Концертный зал' },
  ticket: { number: 'A001234', holder: 'Иван Иванов' },
  seat: { row: '5', number: '12', info: 'Ряд 5, Место 12' },
}

/**
 * Сериализация состояния редактора в payload API.
 *
 * Раньше `template_json` строился через JSON.stringify(JSON) — двойное
 * кодирование, несовместимое с кастомизацией `'template_json' => 'array'`
 * в app/Models/TicketTemplate.php (модель ожидала объект, а получала
 * строку со встроенным JSON). Теперь отдаётся структура целиком.
 */
export function buildTemplatePayload(params: {
  name: string
  organizationId: number | string
  canvasWidth: number
  canvasHeight: number
  config: CanvasConfig
}): Record<string, unknown> {
  return {
    name: params.name,
    organization_id: params.organizationId,
    format: 'mobile',
    width: params.canvasWidth,
    height: params.canvasHeight,
    template_json: params.config,
  }
}

/** Глубокий клон элемента через structuredClone с фолбэком на JSON. */
export function cloneElement<T>(value: T): T {
  if (typeof structuredClone === 'function') {
    return structuredClone(value)
  }
  return JSON.parse(JSON.stringify(value)) as T
}

/**
 * Нормализация нового элемента: координаты производных точек (cx/cy, x2/y2)
 * вычисляются от позиции, чтобы перетаскивание и рендер видели согласованные
 * координаты.
 */
export function normalizeNewElement(item: { type: string; defaultData?: Record<string, unknown> }): BuilderElement {
  const el: BuilderElement = {
    id: Date.now(),
    type: item.type,
    x: 50,
    y: 50,
    ...(item.defaultData ?? {}),
    rotation: 0,
    opacity: 1,
  }

  const dd = (item.defaultData ?? {}) as Record<string, number | undefined>

  if (el.type === 'text' && el.width === undefined) {
    el.width = 200
    el.height = 30
  } else if (el.type === 'rectangle' && el.x2 === undefined && !('x2' in el)) {
    el.x2 = el.x + Number(dd.width ?? 100)
    el.y2 = el.y + Number(dd.height ?? 100)
  } else if (el.type === 'circle') {
    const r = Number(dd.r ?? 50)
    el.cx = el.x + r
    el.cy = el.y + r
  } else if (el.type === 'line') {
    el.x2 = el.x + Number(dd.x2 ?? 200)
    el.y2 = el.y
  } else if (el.type === 'qr' || el.type === 'barcode') {
    el.width = Number(dd.size ?? 100)
    el.height = el.type === 'qr' ? Number(dd.size ?? 100) : Number(dd.height ?? 50)
  }

  return el
}

/** Обновление связанных координат после перемещения элемента. */
export function syncDerivedCoordinates(el: BuilderElement): void {
  if (el.type === 'circle') {
    const r = Number(el.r ?? 50)
    el.cx = el.x + r
    el.cy = el.y + r
  } else if (el.type === 'line') {
    el.x2 = el.x + 200
  } else if (el.type === 'rectangle' || el.type === 'image') {
    el.x2 = el.x + Number(el.width ?? 100)
    el.y2 = el.y + Number(el.height ?? 100)
  }
}

/** Клон выбранного элемента со смещением; null — если ничего не выбрано. */
export function duplicateAsElement(selected: BuilderElement | null): BuilderElement | null {
  if (!selected) return null
  const copy = cloneElement(selected)
  copy.id = Date.now()
  copy.x = selected.x + 20
  copy.y = selected.y + 20
  return copy
}

/** Плоский вид переменных: «event.name» → значение. */
export function flattenVariables(vars: TicketVariables): Record<string, string> {
  return {
    'event.name': vars.event.name,
    'event.date': vars.event.date,
    'event.time': vars.event.time,
    'event.venue': vars.event.venue,
    'ticket.number': vars.ticket.number,
    'ticket.holder': vars.ticket.holder,
    'seat.row': vars.seat.row,
    'seat.number': vars.seat.number,
    'seat.info': vars.seat.info,
  }
}

/** Подстановка {{variable}} в текст элемента (для превью на холсте). */
export function renderTextContent(content: string | undefined, vars: TicketVariables): string {
  if (!content) return ''
  const flat = flattenVariables(vars)
  return content.replace(/\{\{([\w.]+)\}\}/g, (whole, key: string) => flat[key] ?? whole)
}

/** URL внешнего сервиса генерации QR-кодов для превью. */
export function getQrCodeUrl(data?: string): string {
  const qrData = encodeURIComponent(data || 'https://nabilet.com')
  return `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${qrData}`
}

