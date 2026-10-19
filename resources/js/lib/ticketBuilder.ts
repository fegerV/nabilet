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

export interface CanvasGradientConfig {
  from: string
  to: string
  direction?: string
}

export interface CanvasConfig {
  backgroundColor: string
  elements: BuilderElement[]
  backgroundGradient?: CanvasGradientConfig | null
}

export interface TicketVariables {
  event: { name: string; date: string; time: string; venue: string }
  ticket: { number: string; holder: string; price: string; currency: string }
  seat: { row: string; number: string; info: string }
  /** Номер заказа: печатается в футере билета рядом с номером билета. */
  order: { id: string }
}

/** Пресеты размера холста — единственный источник истины для select в тулбаре. */
export const CANVAS_PRESETS: Record<string, { width: number; height: number }> = {
  mobile: { width: 400, height: 600 },
  story: { width: 450, height: 800 },
  desktop: { width: 600, height: 400 },
  square: { width: 500, height: 500 },
  wide: { width: 800, height: 400 },
}

/**
 * Шрифты для текстовых элементов.
 *
 * В `template_json` хранится готовый CSS-стек (`css`), а не ключ: письмо
 * (`TicketPalette`/генератор) и конструктор читают одно поле без таблицы
 * соответствия. Пустая строка — «по умолчанию» (наследование).
 */
export const FONT_STACKS: ReadonlyArray<{ key: string; label: string; css: string }> = [
  { key: 'system', label: 'Системный', css: "-apple-system, 'Segoe UI', Roboto, Arial, sans-serif" },
  { key: 'arial', label: 'Arial / Helvetica', css: 'Arial, Helvetica, sans-serif' },
  { key: 'georgia', label: 'Georgia (с засечками)', css: "Georgia, 'Times New Roman', serif" },
  { key: 'courier', label: 'Моноширинный', css: "'Courier New', Courier, monospace" },
  { key: 'verdana', label: 'Verdana', css: 'Verdana, Geneva, sans-serif' },
]

export const DEFAULT_TICKET_VARIABLES: TicketVariables = {
  event: { name: 'Название события', date: '01.01.2024', time: '19:00', venue: 'Концертный зал' },
  ticket: { number: 'A001234', holder: 'Иван Иванов', price: '3 500', currency: '₽' },
  seat: { row: '5', number: '12', info: 'Ряд 5, Место 12' },
  order: { id: '1042' },
}

/**
 * Сериализация состояния редактора в payload API.
 *
 * Раньше `template_json` строился через JSON.stringify(JSON) — двойное
 * кодирование, несовместимое с кастомизацией `'template_json' => 'array'`
 * в app/Models/TicketTemplate.php (модель ожидала объект, а получала
 * строку со встроенным JSON). Теперь отдаётся структура целиком.
 *
 * `organization_id` в payload ОТСУТСТВУЕТ намеренно. Раньше он брался из
 * пропа компонента и уезжал в тело запроса, но сервер его игнорирует:
 * `TicketTemplateController::store()` берёт организацию из токена. Оставлять
 * поле значило бы давать клиенту возможность назвать чужого арендатора —
 * правило проекта «организацию брать из токена, не из тела запроса».
 */
export function buildTemplatePayload(params: {
  name: string
  canvasWidth: number
  canvasHeight: number
  config: CanvasConfig
}): Record<string, unknown> {
  return {
    name: params.name,
    format: 'mobile',
    width: params.canvasWidth,
    height: params.canvasHeight,
    template_json: params.config,
  }
}

/**
 * Глубокий клон элемента.
 * structuredClone запрещён для Vue-прокси (Proxy — экзотический объект, алгоритм
 * структурного клонирования кидает DataCloneError), поэтому непереносимое
 * значение тихо уходит в JSON-фолбэк: stringify читает свойства через прокси
 * и даёт такой же глубокий чистый клон.
 */
export function cloneElement<T>(value: T): T {
  if (typeof structuredClone === 'function') {
    try {
      return structuredClone(value)
    } catch {
      // Proxy и прочие нестандартные объекты — клонируем через JSON ниже.
    }
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

/**
 * Счётчик автоматических id для элементов, пришедших без него.
 *
 * Строковый префикс, а не число: `Date.now()` у соседних элементов совпадает,
 * а коллизия id означала бы «выделение одного — подсветка другого».
 */
let autoIdSeq = 0
function nextAutoId(): string {
  autoIdSeq += 1
  return `auto-${autoIdSeq}`
}

/**
 * Дефолты элемента, загруженного из БД.
 *
 * Старые и ручные записи `template_json` могут не содержать полей, которые
 * конструктор считает обязательными (rotation, opacity, fontSize, size…):
 * рендер тогда считает `NaN`, а панель свойств показывает «NaN%»/«undefined°».
 * Новые элементы дефолты получают в `normalizeNewElement()`, загруженные —
 * здесь, сразу после чтения, чтобы дальше код работал с полным объектом.
 */
export function ensureElementDefaults(el: BuilderElement): BuilderElement {
  if (el.rotation === undefined) el.rotation = 0
  if (el.opacity === undefined) el.opacity = 1
  // У элемента из `template_json` может не быть `id`: макет собирают и сидером,
  // и руками в JSON. Без id выделение ломалось — `selectedElement?.id` давал
  // `undefined`, `element.id` тоже, и ВСЕ элементы сразу выглядели выбранными
  // (синяя обводка на каждом блоке). Присваиваем при загрузке.
  if (el.id === undefined || el.id === null || el.id === '') {
    el.id = nextAutoId()
  }

  switch (el.type) {
    case 'text': {
      if (el.fontSize === undefined) el.fontSize = 16
      if (el.color === undefined) el.color = '#000000'
      if (el.fontWeight === undefined) el.fontWeight = 'normal'
      if (el.fontFamily === undefined) el.fontFamily = ''
      if (el.content === undefined) el.content = ''
      break
    }
    case 'rectangle':
    case 'image':
    case 'barcode': {
      if (el.width === undefined) el.width = 100
      if (el.height === undefined) el.height = el.type === 'barcode' ? 50 : 100
      if (el.type === 'rectangle') {
        if (el.fill === undefined) el.fill = 'transparent'
        if (el.strokeWidth === undefined) el.strokeWidth = 0
      }
      break
    }
    case 'circle': {
      if (el.r === undefined) el.r = 50
      if (el.fill === undefined) el.fill = '#cccccc'
      if (el.strokeWidth === undefined) el.strokeWidth = 0
      break
    }
    case 'line': {
      if (el.x2 === undefined) el.x2 = el.x + 200
      if (el.y2 === undefined) el.y2 = el.y
      if (el.stroke === undefined) el.stroke = '#000000'
      if (el.strokeWidth === undefined) el.strokeWidth = 2
      break
    }
    case 'qr': {
      if (el.size === undefined) el.size = 120
      el.width = el.size
      el.height = el.size
      break
    }
    default:
      break
  }

  return el
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

/** Угол ресайза. У боксов (прямоугольник/картинка/штрих-код) — все четыре. */
export type ResizeCorner = 'nw' | 'ne' | 'sw' | 'se'

/**
 * Какие ручки ресайза показать у элемента.
 *
 * Боксы тянутся за любой угол (меняются x/y/width/height). Круг, QR, текст и
 * линия — только за правый нижний: у них нет независимых сторон (радиус,
 * квадрат, кегль, конечная точка), и четыре ручки врали бы про возможности.
 */
export function resizeHandlesFor(type: string): ResizeCorner[] {
  if (type === 'rectangle' || type === 'image' || type === 'barcode') {
    return ['nw', 'ne', 'sw', 'se']
  }
  return ['se']
}

/**
 * Применить тянучку за угол: dx/dy — сдвиг курсора в пикселях ХОЛСТА
 * (компонент уже поделил экранные пиксели на зум).
 *
 * Мутирует элемент и возвращает его. Минимумы зашиты здесь, привязку к
 * границам холста делает компонент (он знает размер) — деление
 * ответственности: математика тестируется без DOM.
 */
export function applyCornerResize(
  el: BuilderElement,
  corner: ResizeCorner,
  dx: number,
  dy: number,
): BuilderElement {
  const MIN_BOX = 10

  switch (el.type) {
    case 'image': {
      // Картинка тянется строго пропорционально: аспект — из снапшота жеста
      // (компонент перед вызовом откатывает элемент к снапшоту, так что
      // предмутационные значения здесь = стартовые).
      const w = Math.max(MIN_BOX, Number(el.width ?? 100))
      const h = Math.max(MIN_BOX, Number(el.height ?? 100))
      const right = el.x + w
      const bottom = el.y + h
      const minScale = Math.max(MIN_BOX / w, MIN_BOX / h)

      // Сдвиги «наружу» положительны у любого угла.
      const dw = corner === 'ne' || corner === 'se' ? dx : -dx
      const dh = corner === 'se' || corner === 'sw' ? dy : -dy

      // Доминирующая ось ведёт тянучку: берём больший из двух масштабов,
      // иначе при диагональном жесте коробка отставала бы от курсора.
      const scale = Math.max(minScale, 1 + dw / w, 1 + dh / h)

      const width = Math.max(MIN_BOX, Math.round(w * scale))
      const height = Math.max(MIN_BOX, Math.round(h * scale))

      el.width = width
      el.height = height
      // Противоположный угол стоит на месте.
      if (corner === 'nw' || corner === 'sw') el.x = Math.round(right - width)
      if (corner === 'nw' || corner === 'ne') el.y = Math.round(bottom - height)
      break
    }
    case 'barcode': {
      let x = el.x
      let y = el.y
      let width = Math.max(MIN_BOX, Number(el.width ?? 100))
      let height = Math.max(MIN_BOX, Number(el.height ?? 100))

      if (corner === 'se' || corner === 'ne') width = Math.max(MIN_BOX, width + dx)
      if (corner === 'sw' || corner === 'nw') {
        const next = Math.max(MIN_BOX, width - dx)
        x += width - next
        width = next
      }
      if (corner === 'se' || corner === 'sw') height = Math.max(MIN_BOX, height + dy)
      if (corner === 'ne' || corner === 'nw') {
        const next = Math.max(MIN_BOX, height - dy)
        y += height - next
        height = next
      }

      el.x = Math.round(x)
      el.y = Math.round(y)
      el.width = Math.round(width)
      el.height = Math.round(height)
      break
    }
    case 'rectangle': {
      let width = Math.max(MIN_BOX, Number(el.width ?? 100))
      let height = Math.max(MIN_BOX, Number(el.height ?? 100))
      let x = el.x
      let y = el.y

      if (corner === 'se' || corner === 'ne') width = Math.max(MIN_BOX, width + dx)
      if (corner === 'sw' || corner === 'nw') {
        const next = Math.max(MIN_BOX, width - dx)
        x += width - next
        width = next
      }
      if (corner === 'se' || corner === 'sw') height = Math.max(MIN_BOX, height + dy)
      if (corner === 'ne' || corner === 'nw') {
        const next = Math.max(MIN_BOX, height - dy)
        y += height - next
        height = next
      }

      el.x = Math.round(x)
      el.y = Math.round(y)
      el.width = Math.round(width)
      el.height = Math.round(height)
      break
    }
    case 'circle': {
      // Радиус растёт, когда тянут наружу: знак зависит от угла.
      const outward = (corner === 'se' || corner === 'nw' ? dx + dy : dx - dy)
        * (corner === 'nw' || corner === 'sw' ? -1 : 1)
      const r = Math.max(5, Number(el.r ?? 50) + outward / 2)
      el.r = Math.round(r)
      break
    }
    case 'qr': {
      const size = Math.max(20, Number(el.size ?? 120) + (dx + dy) / 2)
      el.size = Math.round(size)
      el.width = Math.round(size)
      el.height = Math.round(size)
      break
    }
    case 'text': {
      // У текста нет ширины в модели (рендер — `width: auto`), поэтому угол
      // масштабирует кегль: движение наружу (+) растит, внутрь (−) уменьшает.
      const fontSize = Math.min(200, Math.max(8, Number(el.fontSize ?? 16) + (dx + dy) * 0.1))
      el.fontSize = Math.round(fontSize)
      break
    }
    case 'line': {
      // У линии тянучка двигает конечную точку, начальная стоит.
      el.x2 = Math.round(el.x + Math.max(10, Number(el.x2 ?? el.x + 200) - el.x + dx))
      el.y2 = Math.round(el.y + (Number(el.y2 ?? el.y) - el.y + dy))
      break
    }
    default:
      break
  }

  return el
}

/** Направление сдвига в стопке слоёв. В массиве конец = верх. */
export type LayerMove = 'up' | 'down' | 'top' | 'bottom'

/**
 * Сдвинуть элемент в порядке слоёв. Возвращает true, если порядок изменился.
 *
 * Мутирует массив через splice — реактивность Vue подхватывает перестановку,
 * выбранный элемент остаётся тем же объектом, выделение не слетает.
 */
export function moveElementInList(
  list: BuilderElement[],
  id: BuilderElement['id'],
  direction: LayerMove,
): boolean {
  const index = list.findIndex((el) => el.id === id)
  if (index === -1) return false

  if (direction === 'up' && index < list.length - 1) {
    const [el] = list.splice(index, 1)
    list.splice(index + 1, 0, el!)
    return true
  }
  if (direction === 'down' && index > 0) {
    const [el] = list.splice(index, 1)
    list.splice(index - 1, 0, el!)
    return true
  }
  if (direction === 'top' && index < list.length - 1) {
    const [el] = list.splice(index, 1)
    list.push(el!)
    return true
  }
  if (direction === 'bottom' && index > 0) {
    const [el] = list.splice(index, 1)
    list.unshift(el!)
    return true
  }
  return false
}

/** Человеческое имя типа элемента — для списка слоёв. */
export function elementTypeLabel(type: string): string {
  switch (type) {
    case 'text': return 'Текст'
    case 'rectangle': return 'Прямоугольник'
    case 'circle': return 'Круг'
    case 'line': return 'Линия'
    case 'qr': return 'QR-код'
    case 'image': return 'Изображение'
    case 'barcode': return 'Штрих-код'
    default: return String(type)
  }
}

/**
 * Подпись элемента в списке слоёв: текст — по содержимому, остальное — по типу.
 *
 * Переменные показываем сырыми (`{{event.name}}`, а не «Название события»):
 * в списке важна формула, а не превью значения — иначе два разных текста
 * с одной переменной выглядели бы одинаково.
 */
export function elementLayerLabel(el: BuilderElement): string {
  if (el.type === 'text') {
    const content = String(el.content ?? '').trim()
    return content !== '' ? content.slice(0, 24) : 'Текст'
  }
  return elementTypeLabel(el.type)
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
    // Цена и валюта — раздельно: «3 500» + «₽» позволяет поставить символ
    // отдельным блоком или вовсе убрать его, не трогая число.
    'ticket.price': vars.ticket.price,
    'ticket.currency': vars.ticket.currency,
    'seat.row': vars.seat.row,
    'seat.number': vars.seat.number,
    'seat.info': vars.seat.info,
    'order.id': vars.order.id,
  }
}

/** Подстановка {{variable}} в текст элемента (для превью на холсте). */
export function renderTextContent(content: string | undefined, vars: TicketVariables): string {
  if (!content) return ''
  const flat = flattenVariables(vars)
  return content.replace(/\{\{([\w.]+)\}\}/g, (whole, key: string) => flat[key] ?? whole)
}

// ЗДЕСЬ БЫЛА `getQrCodeUrl()`, ВОЗВРАЩАВШАЯ ССЫЛКУ НА api.qrserver.com.
//
// Она подставляла `qr_payload` билета — подписанный токен входа — в
// query-параметр чужого сервиса, то есть отдавала третьей стороне и сам токен,
// и IP покупателя. Функция удалена целиком, а не «переписана на локальный
// рендер», чтобы прежний вызов нельзя было случайно вернуть: рендер QR теперь
// живёт в `@/lib/qr` (`renderQrDataUrl` / `renderQrSvg`), и это единственная
// точка генерации кода в проекте.
//
// `TicketBuilder.vue` берёт функцию оттуда; в шаблоне это обычный `import`.

