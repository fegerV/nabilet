/**
 * Раскладка зоны танцпола на координатной карте зала.
 *
 * Повод: рамка зоны была ЖЁСТКО 150×90 px, а подписи внутри — переменной длины
 * («5 000 ₽ · 150 билетов»). При крупных ценах и больших остатках строка
 * вылезала за пунктирную рамку: текст рисовался поверх мест и соседних
 * подписей. Проверено на банкетном зале (52 билета) — подпись цены и остатка
 * шире рамки уже там.
 *
 * Здесь размер рамки считается ПО ТЕКСТУ: сначала оценивается ширина самой
 * длинной строки, затем рамка растягивается под неё. Плюс цена и остаток
 * разнесены по отдельным строкам — так ширина перестаёт зависеть от разрядности
 * чисел (раньше «9 000 ₽ · 52 билетов» была одной строкой, а «1 200 000 ₽ ·
 * 100 000 билетов» — уже вдвое длиннее).
 *
 * Модуль чистый (никаких Vue/Konva), поэтому раскладка покрыта юнит-тестами.
 */

/** Вертикальные смещения базовых линий строк от верхнего края рамки (px). */
export const DANCE_LINE_Y = {
  title: 25,
  price: 43,
  count: 60,
  hint: 78,
} as const

/** Размеры шрифтов строк — те же, что в шаблоне CoordSeatMap. */
export const DANCE_FONT = {
  title: 14,
  price: 12,
  count: 12,
  hint: 11,
} as const

/** Высота рамки: последняя строка (hint, 78) + запас на выносные элементы. */
export const DANCE_BOX_HEIGHT = 90

/** Горизонтальные внутренние отступы рамки (px с каждой стороны). */
export const DANCE_BOX_PAD_X = 18

/** Минимальная ширина рамки (px) — чтобы короткая подпись не выглядела обрезанной. */
export const DANCE_BOX_MIN_WIDTH = 150

/**
 * Оценка ширины строки в пикселях.
 *
 * Точное измерение требует canvas-метрик (`measureText`), недоступных до
 * монтирования, и зависит от фактически загруженного шрифта — рамка «прыгала»
 * бы между кадрами. Поэтому берём детерминированную оценку СВЕРХУ: средняя
 * ширина глифа кириллицы/цифры в системном sans-serif ≈ 0.62em. Оценка сверху
 * безопасна — рамка окажется чуть шире текста, но текст в неё гарантированно
 * поместится.
 */
export function estimateTextWidth(text: string, fontSize: number): number {
  return text.length * fontSize * 0.62
}

export interface DanceZoneText {
  title: string
  price: string
  count: string
  hint: string
}

export interface DanceZoneBox {
  x: number
  y: number
  w: number
  h: number
  /** Базовые линии строк в координатах холста. */
  titleY: number
  priceY: number
  countY: number
  hintY: number
  /** Ширина самой длинной строки — нужна тестам и диагностике. */
  textWidth: number
}

export interface DanceZoneOptions {
  /** Ширина холста: рамка не может быть шире него. */
  canvasWidth: number
  /** Верхний край рамки в координатах холста. */
  y: number
  padX?: number
  minWidth?: number
}

/**
 * Рамка зоны танцпола, растянутая под фактический текст.
 *
 * Рамка центрируется по холсту и ограничена его шириной: на узком холсте
 * подпись может не поместиться целиком, но за границы холста рамка не выйдет.
 */
export function danceZoneLayout(text: DanceZoneText, options: DanceZoneOptions): DanceZoneBox {
  const padX = options.padX ?? DANCE_BOX_PAD_X
  const minWidth = options.minWidth ?? DANCE_BOX_MIN_WIDTH

  const textWidth = Math.max(
    estimateTextWidth(text.title, DANCE_FONT.title),
    estimateTextWidth(text.price, DANCE_FONT.price),
    estimateTextWidth(text.count, DANCE_FONT.count),
    estimateTextWidth(text.hint, DANCE_FONT.hint),
  )

  const maxWidth = Math.max(minWidth, options.canvasWidth - padX * 2)
  // ceil, а не round: рамка не имеет права оказаться УЖЕ текста — ради этого
  // модуль и существует. Дробная ширина для SVG нормальна.
  const w = Math.ceil(Math.min(maxWidth, Math.max(minWidth, textWidth + padX * 2)))
  const y = options.y

  return {
    // Без округления: при нечётной ширине round смещал центр на 0.5 px, и
    // рамка переставала быть симметричной относительно холста.
    x: (options.canvasWidth - w) / 2,
    y,
    w,
    h: DANCE_BOX_HEIGHT,
    titleY: y + DANCE_LINE_Y.title,
    priceY: y + DANCE_LINE_Y.price,
    countY: y + DANCE_LINE_Y.count,
    hintY: y + DANCE_LINE_Y.hint,
    textWidth,
  }
}
