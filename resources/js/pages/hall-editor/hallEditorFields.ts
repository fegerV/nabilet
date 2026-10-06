/**
 * Чистые правила полей редактора залов: разбор ввода, границы, ошибки.
 *
 * Выделено из `HallEditorPage.vue` (P2). В файле на 2582 строки эти правила были
 * размазаны по сеттерам инспектора вперемешку с мутациями модели и снимками
 * истории — и не проверялись ничем, потому что для теста пришлось бы монтировать
 * страницу с Konva-холстом.
 *
 * Здесь остаются только функции «вход → число или отказ». Никакой Vue, никакого
 * состояния: сеттер на странице решает, что делать с результатом (снять снимок
 * истории, записать в модель, показать ошибку).
 *
 * Границы согласованы с сервером (`HallService::validateSchemaPayload`): цена —
 * не меньше 1 копейки, размеры и вместимость — не меньше 1.
 */

/** Сколько копеек в рубле. */
const MINOR_PER_RUBLE = 100

/**
 * Рубли → копейки для цены. `null` — значение негодное и в модель попадать не
 * должно (не число, ноль, отрицательное).
 *
 * Нижняя граница 1 копейка, а не 0: нулевая цена на сервере отвергается, и
 * молча записанный ноль дал бы расхождение «на экране 0 ₽, автосохранение
 * падает с ошибкой валидации».
 */
export function rubToMinor(value: unknown): number | null {
  const n = Number(value)
  if (!Number.isFinite(n) || n <= 0) return null

  return Math.max(1, Math.round(n * MINOR_PER_RUBLE))
}

/**
 * Цена ряда: `undefined/null/''` — сброс индивидуальной цены (ряд берёт цену
 * сектора), это НЕ ошибка. Всё остальное проверяется как цена.
 */
export function rowPriceError(value: unknown): string | undefined {
  if (value === '' || value === null || value === undefined) return undefined

  const n = Number(value)
  if (!Number.isFinite(n)) return 'Введите число'
  if (n < 0) return 'Цена не может быть отрицательной'
  if (Math.round(n * MINOR_PER_RUBLE) < 1) return 'Цена должна быть больше нуля'

  return undefined
}

/** Обязательная неотрицательная целая величина (копейки, размеры, вместимость). */
export function requiredNonNegativeError(value: unknown, unitLabel = 'Значение'): string | undefined {
  const n = Number(value)

  if (value === '' || value === null || value === undefined || !Number.isFinite(n)) {
    return `${unitLabel}: введите число`
  }
  if (n < 0) return `${unitLabel} не может быть отрицательным`

  return undefined
}

/** Целое в границах `[min, max]`. `null` — значение не число и писать его нельзя. */
export function clampIntOrNull(value: unknown, min: number, max: number): number | null {
  const n = Number(value)
  if (!Number.isFinite(n)) return null

  return Math.min(max, Math.max(min, Math.round(n)))
}

/** Дробное в границах `[min, max]` (без округления). `null` — значение не число. */
export function clampFloatOrNull(value: unknown, min: number, max: number): number | null {
  const n = Number(value)
  if (!Number.isFinite(n)) return null

  return Math.min(max, Math.max(min, n))
}

/** Округлённое целое без границ. `null` — значение не число. */
export function roundOrNull(value: unknown): number | null {
  const n = Number(value)
  if (!Number.isFinite(n)) return null

  return Math.round(n)
}

/**
 * Угол поворота, приведённый к диапазону `[0, 360)`.
 *
 * Именно приведение, а не `n % 360`: для отрицательного угла (поворот против
 * часовой стрелки) остаток в JS тоже отрицательный, и `-30` уехало бы в модель
 * как есть, разойдясь с серверной валидацией.
 */
export function normalizeRotation(degrees: number): number {
  return ((degrees % 360) + 360) % 360
}
