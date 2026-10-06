/**
 * Вписывание координатной раскладки зала в полотно фиксированного размера.
 *
 * Зачем отдельный модуль. Эта математика жила прямо в `CoordSeatMap.vue` внутри
 * `computed`-цепочек и потому была недостижима для тестов: чтобы проверить
 * «попадает ли место внутрь viewBox», пришлось бы монтировать SVG-компонент и
 * разбирать разметку. А ошибка здесь не косметическая — при неверном масштабе
 * зал рисуется пустым, и выбрать место (главное действие витрины) физически
 * невозможно. Именно так и было: `ny = 40 - sy` при данных `y = 60` уводило
 * координату за верхнюю границу viewBox, и карта показывала пустой зал с одной
 * надписью «СЦЕНА». Проверено скриншотом живого стенда.
 *
 * Здесь только чистые функции без Vue и DOM, поэтому они тестируются напрямую
 * (см. `tests/Unit/Seat/seatViewport.test.ts`).
 *
 * Отношение к `pages/hall-editor/seatGeometry.ts`: это РАЗНЫЕ задачи, а не
 * дубликаты. `seatGeometry` СИНТЕЗИРУЕТ раскладку (дуга, сетка, стол) для
 * редактора зала. Этот модуль ВПИСЫВАЕТ уже готовые координаты в область
 * просмотра для рендера. Общего кода между ними нет, поэтому и копии не
 * возникло; витрина не должна зависеть от внутренностей страницы редактора.
 */

/** Габариты набора точек в координатах зала. */
export interface PointBounds {
  minX: number
  maxX: number
  minY: number
  maxY: number
}

/** Область, в которую нужно вписать точки. */
export interface FitBox {
  /** Ширина полотна. */
  width: number
  /** Высота полотна. */
  height: number
  /** Отступ по всем краям. */
  pad: number
  /** Полоса сверху, которую точки не должны занимать (например, сцена). */
  reservedTop?: number
  /** Полоса снизу. */
  reservedBottom?: number
}

/** Результат вписывания: габариты, масштаб и точка начала координат. */
export interface FittedViewport {
  bounds: PointBounds
  /** Единый масштаб по обеим осям — иначе пропорции зала исказятся. */
  scale: number
  /** Экранная координата `bounds.minX`. */
  offsetX: number
  /** Экранная координата `bounds.minY`. */
  offsetY: number
}

/**
 * Габариты по умолчанию, когда точек нет. Диапазон не вырожден (`max > min`),
 * поэтому `scale` остаётся конечным и деление на ноль не возникает.
 */
export const EMPTY_BOUNDS: PointBounds = { minX: 0, maxX: 1, minY: 0, maxY: 1 }

/**
 * Габариты набора точек.
 *
 * Вырожденный случай (одна точка или все точки на одной линии) расширяется на
 * единицу: иначе `maxX - minX === 0`, масштаб обращается в бесконечность, и
 * единственное место рисуется за пределами полотна.
 */
export function pointBounds(points: readonly { x: number; y: number }[]): PointBounds {
  if (points.length === 0) return { ...EMPTY_BOUNDS }

  const xs = points.map((p) => p.x)
  const ys = points.map((p) => p.y)

  const minX = Math.min(...xs)
  const maxX = Math.max(...xs)
  const minY = Math.min(...ys)
  const maxY = Math.max(...ys)

  return {
    minX,
    maxX: maxX > minX ? maxX : minX + 1,
    minY,
    maxY: maxY > minY ? maxY : minY + 1,
  }
}

/**
 * Вписать габариты точек в область.
 *
 * Y НЕ инвертируется: направление оси совпадает с редактором зала
 * (`y = (clientY - rect.top - stage.y()) / scale`, то есть «ниже по экрану —
 * больше y»). Инверсия зеркалила зал относительно того, что администратор видит
 * при расстановке, и меняла ряды местами: более дорогой ряд с меньшим `y`
 * оказывался бы дальше от сцены, чем дешёвый.
 */
export function fitPoints(
  points: readonly { x: number; y: number }[],
  box: FitBox,
): FittedViewport {
  const bounds = pointBounds(points)

  // `Math.max(1, …)` защищает от отрицательной «полезной» площади, когда
  // отступы и зарезервированные полосы больше самого полотна.
  const usableWidth = Math.max(1, box.width - box.pad * 2)
  const usableHeight = Math.max(
    1,
    box.height - box.pad * 2 - (box.reservedTop ?? 0) - (box.reservedBottom ?? 0),
  )

  const spanX = bounds.maxX - bounds.minX
  const spanY = bounds.maxY - bounds.minY

  return {
    bounds,
    scale: Math.min(usableWidth / spanX, usableHeight / spanY),
    offsetX: box.pad,
    offsetY: box.pad + (box.reservedTop ?? 0),
  }
}

/** Координата зала → пиксели полотна. */
export function projectPoint(
  x: number,
  y: number,
  fit: FittedViewport,
): { x: number; y: number } {
  return {
    x: fit.offsetX + (x - fit.bounds.minX) * fit.scale,
    y: fit.offsetY + (y - fit.bounds.minY) * fit.scale,
  }
}
