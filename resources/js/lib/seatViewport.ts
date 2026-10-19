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

/**
 * Ближайшее расстояние между точками в ЭКРАННЫХ единицах полотна.
 *
 * Раньше это была внутренняя арифметика `fitSeatRadius`. Вынесена отдельно,
 * потому что у неё появился второй потребитель: радиус невидимой зоны нажатия
 * (`fitSeatHitRadius`). Зону нажатия делаем крупнее видимого кружка — иначе на
 * телефоне палец не попадает по месту, — но она не должна перекрывать соседей,
 * иначе выбор уедет на чужое место.
 */
export function nearestPointDistance(
  points: readonly { x: number; y: number }[],
  scale: number,
): number {
  const sorted = points
    .filter((point) => Number.isFinite(point.x) && Number.isFinite(point.y))
    .map((point) => ({ x: point.x, y: point.y }))
    .sort((a, b) => a.x - b.x || a.y - b.y)
  if (sorted.length < 2) return Number.POSITIVE_INFINITY

  let nearest = Number.POSITIVE_INFINITY

  for (let i = 0; i < sorted.length - 1; i += 1) {
    for (let j = i + 1; j < Math.min(sorted.length, i + 24); j += 1) {
      const dx = (sorted[j].x - sorted[i].x) * scale
      if (dx > 24) break
      const dy = (sorted[j].y - sorted[i].y) * scale
      const distance = Math.hypot(dx, dy)
      if (distance > 0 && distance < nearest) nearest = distance
    }
  }

  return nearest
}

/**
 * Подбирать радиус точки по фактическому шагу после вписывания координат.
 * Импортёр Афиши может нормировать очень большой план до диапазона 0..60;
 * постоянный радиус 6 тогда больше расстояния между креслами и сливает ряды.
 */
export function fitSeatRadius(
  points: readonly { x: number; y: number }[],
  scale: number,
  maxRadius = 6,
  minRadius = 2,
): number {
  const nearest = nearestPointDistance(points, scale)
  if (!Number.isFinite(nearest)) return maxRadius
  return Math.min(maxRadius, Math.max(minRadius, nearest * 0.34))
}

/**
 * Радиус НЕВИДИМОЙ зоны нажатия на место.
 *
 * Зачем. На телефоне схема зала вписывается целиком, и видимый кружок места
 * получается ~2–3 CSS px — палец по нему не попадает. Крутить зум перед каждым
 * выбором некомфортно, поэтому под каждым местом лежит прозрачный кружок
 * побольше, и тап ловится он.
 *
 * Почему не «максимально большой». Зона нажатия — конкурентный захват:
 * перекрытие двух соседей означает, что выбор уедет на чужое место, а это хуже
 * промаха. Потолок — 45% шага между местами (видимый кружок занимает 34%) и 12
 * единиц полотна.
 */
export function fitSeatHitRadius(
  points: readonly { x: number; y: number }[],
  scale: number,
  maxRadius = 12,
): number {
  const visible = fitSeatRadius(points, scale)
  const nearest = nearestPointDistance(points, scale)
  if (!Number.isFinite(nearest)) return Math.min(maxRadius, visible + 1.5)
  return Math.min(maxRadius, Math.max(visible + 1.5, nearest * 0.45))
}

/* ── Приближение к участку зала ───────────────────────────────────────────
 * «Показать весь зал» удобен для ориентира, но на телефоне вписанный целиком
 * амфитеатр даёт точки по 2–3 px. Как это решает Яндекс Афиша (проверено на
 * widget.afisha.yandex.ru, экран 390×844): над схемой стоят чипы ценовых
 * категорий, выбор чипа оставляет на карте только свои места, а масштаб при этом
 * подтягивается к ним — затем место тапается без предварительного зума. Ниже —
 * ровно эта математика, вынесенная из компонента, чтобы её можно было проверить
 * тестом, а не скриншотом живого стенда.
 */

/** CSS-бокс, в который вписан SVG (сам вьюпорт карты). */
export interface ElementBox {
  width: number
  height: number
}

/**
 * Как viewBox ложится в CSS-бокс при `preserveAspectRatio="xMidYMid meet"`.
 *
 * Считается, а не измеряется: viewBox — константа компонента, а бокс известен,
 * поэтому лишний `getBoundingClientRect()` не нужен.
 */
export function meetFit(
  box: ElementBox,
  viewBox: { width: number; height: number },
): { scale: number; offsetX: number; offsetY: number } {
  const scale = Math.min(box.width / viewBox.width, box.height / viewBox.height)
  return {
    scale,
    offsetX: (box.width - viewBox.width * scale) / 2,
    offsetY: (box.height - viewBox.height * scale) / 2,
  }
}

/**
 * Масштаб и панорама, при которых участок зала занимает долю `fill` вьюпорта.
 *
 * Габариты `bounds` задаются в единицах viewBox (после `projectPoint`), потому
 * что трансформ `translate() scale()` применяется к SVG-элементу, а не к данным
 * зала. Возвращаемые `panX/panY` совместимы с `zoomBy`/`resetView`: те же
 * `panX/panY`, тот же `transform-origin: top left`.
 */
export function focusTransform(
  bounds: PointBounds,
  box: ElementBox,
  viewBox: { width: number; height: number },
  options: { minZoom?: number; maxZoom?: number; fill?: number } = {},
): { zoom: number; panX: number; panY: number } {
  const { minZoom = 0.6, maxZoom = 6, fill = 0.8 } = options
  const meet = meetFit(box, viewBox)

  // На зуме k один юнит viewBox занимает meet.scale * k CSS px, значит вьюпорт
  // показывает box.width / (meet.scale * k) юнитов.
  const spanX = Math.max(1e-6, bounds.maxX - bounds.minX)
  const spanY = Math.max(1e-6, bounds.maxY - bounds.minY)
  const zoomX = (box.width * fill) / (spanX * meet.scale)
  const zoomY = (box.height * fill) / (spanY * meet.scale)
  const zoom = Math.min(maxZoom, Math.max(minZoom, Math.min(zoomX, zoomY)))

  // Точка viewBox в координатах элемента: letterbox плюс масштаб meet.
  const cx = (bounds.minX + bounds.maxX) / 2
  const cy = (bounds.minY + bounds.maxY) / 2
  const px = meet.offsetX + meet.scale * cx
  const py = meet.offsetY + meet.scale * cy

  // Центр участка должен встать в центр вьюпорта после translate + scale.
  return {
    zoom,
    panX: box.width / 2 - zoom * px,
    panY: box.height / 2 - zoom * py,
  }
}
