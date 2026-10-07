/**
 * Чистая геометрия раскладки мест в секторе редактора залов.
 *
 * Извлечено из HallEditorPage.vue: функции не зависят от Vue-состояния,
 * поэтому их можно тестировать изолированно (vitest) и переиспользовать
 * между генератором секторов, добавлением рядов и рисованием превью.
 */
import type { ESector, ESeat, SeatKind } from './editorTypes'

/** Размер стороны места (px). */
export const SEAT = 16
/** Горизонтальный шаг между местами (px). */
export const GAP = 6
/** Вертикальный шаг между рядами (px). */
export const ROW_GAP = 12
/** Максимальный угол дуги: полукруг не сводит крайние места в одну точку. */
export const MAX_ARC_SPREAD_DEG = 180

function safeArcSpread(degrees: number): number {
  return Math.min(MAX_ARC_SPREAD_DEG, Math.max(10, Number.isFinite(degrees) ? degrees : 180))
}

/** Найти ближайшую свободную позицию места, не перекрывающую существующие. */
export function findFreeSeatPosition(
  seats: Pick<ESeat, 'x' | 'y'>[],
  x: number,
  y: number,
  seatSize = SEAT,
  horizontalStep = SEAT + GAP,
): { x: number; y: number } {
  const collides = (candidateX: number, candidateY: number): boolean => seats.some((seat) =>
    Math.abs(seat.x - candidateX) < seatSize && Math.abs(seat.y - candidateY) < seatSize,
  )

  let candidateX = x
  for (let attempt = 0; attempt <= seats.length * 2; attempt += 1) {
    if (!collides(candidateX, y)) return { x: candidateX, y }
    candidateX += horizontalStep
  }

  // A deterministic fallback below the lowest existing seat guarantees a free
  // slot even when the clicked row is densely packed.
  const nextY = seats.length > 0
    ? Math.max(...seats.map((seat) => seat.y + seatSize)) + ROW_GAP
    : y
  candidateX = x
  while (collides(candidateX, nextY)) candidateX += horizontalStep
  return { x: candidateX, y: nextY }
}

export interface SeatGridConfig {
  seat: number
  gap: number
  rowGap: number
}

const DEFAULT_CONFIG: SeatGridConfig = { seat: SEAT, gap: GAP, rowGap: ROW_GAP }

/** Координата места на дуге (амфитеатр). Центр кривизны — в (arcOffsetX, arcOffsetY). */
export function arcSeatXY(sector: ESector, r: number, n: number, seatsPerRow: number): { x: number; y: number } {
  const theta = (safeArcSpread(sector.arcSpread) * Math.PI) / 180
  const R = sector.arcBaseR + r * sector.arcRowGap
  const a = seatsPerRow > 1 ? -theta / 2 + (n * theta) / (seatsPerRow - 1) : 0
  return { x: R * Math.sin(a) + sector.arcOffsetX, y: R * Math.cos(a) + sector.arcOffsetY }
}

/** Ряды к сцене (первые vipRows) — VIP; крайние места последнего ряда — доступные. */
export function seatKindFor(r: number, n: number, seatsPerRow: number, vipRows: number): SeatKind {
  if (r < vipRows) return 'vip'
  if (n === 0 || n === seatsPerRow - 1) return 'accessible'
  return 'standard'
}

/**
 * Параметры дуговой раскладки по числу рядов/мест (§49): равный шаг вдоль дуги,
 * радиус растёт на каждый ряд. Центр кривизны — внизу под залом, поэтому ряды
 * «смотрят» выпуклостью к сцене (вверх).
 */
export function arcLayoutParams(
  rows: number,
  seatsPerRow: number,
  arcSpreadDeg: number,
  config: SeatGridConfig = DEFAULT_CONFIG,
): Pick<ESector, 'arcBaseR' | 'arcRowGap' | 'arcOffsetX' | 'arcOffsetY'> {
  const theta = (safeArcSpread(arcSpreadDeg) * Math.PI) / 180
  const rowGap = config.seat + config.rowGap
  const R0 = seatsPerRow > 1 ? ((seatsPerRow - 1) * (config.seat + config.gap)) / theta : 60
  const maxR = R0 + (rows - 1) * rowGap
  return {
    arcBaseR: R0,
    arcRowGap: rowGap,
    arcOffsetX: maxR * Math.sin(theta / 2),
    arcOffsetY: -R0 * Math.cos(theta / 2),
  }
}

/** Места прямоугольной сетки сектора. */
export function buildGridSeats(
  rows: number,
  seatsPerRow: number,
  vipRows: number,
  nextId: (prefix: string) => string,
  config: SeatGridConfig = DEFAULT_CONFIG,
): ESeat[] {
  const seats: ESeat[] = []
  for (let r = 0; r < rows; r += 1) {
    for (let n = 0; n < seatsPerRow; n += 1) {
      seats.push({
        id: nextId('seat'),
        row: r + 1,
        number: n + 1,
        kind: seatKindFor(r, n, seatsPerRow, vipRows),
        x: n * (config.seat + config.gap),
        y: r * (config.seat + config.rowGap),
      })
    }
  }
  return seats
}

/** Места дуговой раскладки (амфитеатр). Сектор должен уже содержать arc*-параметры. */
export function buildArcSeats(
  sector: ESector,
  rows: number,
  seatsPerRow: number,
  vipRows: number,
  nextId: (prefix: string) => string,
): ESeat[] {
  const seats: ESeat[] = []
  for (let r = 0; r < rows; r += 1) {
    for (let n = 0; n < seatsPerRow; n += 1) {
      const { x, y } = arcSeatXY(sector, r, n, seatsPerRow)
      seats.push({ id: nextId('seat'), row: r + 1, number: n + 1, kind: seatKindFor(r, n, seatsPerRow, vipRows), x, y })
    }
  }
  return seats
}

/**
 * Новый ряд для существующего сектора. Для дуги пересчитывается центровка
 * по X и старые места сдвигаются, чтобы новый (самый широкий) ряд остался
 * симметричным. Мутирует переданный сектор (arcOffsetX и координаты мест),
 * как это делал исходный addRowToSelected.
 */
export function appendRowToSector(
  sector: ESector,
  seatsPerRow: number,
  nextId: (prefix: string) => string,
  config: SeatGridConfig = DEFAULT_CONFIG,
): { row: number; seats: ESeat[] } {
  const nextRow = (sector.seats.reduce((m, s) => Math.max(m, s.row), 0) || 0) + 1
  const newSeats: ESeat[] = []

  if (sector.shape === 'arc') {
    const theta = (safeArcSpread(sector.arcSpread) * Math.PI) / 180
    const newMaxR = sector.arcBaseR + (nextRow - 1) * sector.arcRowGap
    const newOffsetX = newMaxR * Math.sin(theta / 2)
    const dx = newOffsetX - sector.arcOffsetX
    if (dx) for (const seat of sector.seats) seat.x += dx
    sector.arcOffsetX = newOffsetX
    const r = nextRow - 1
    for (let n = 0; n < seatsPerRow; n += 1) {
      const { x, y } = arcSeatXY(sector, r, n, seatsPerRow)
      const seat = { id: nextId('seat'), row: nextRow, number: n + 1, kind: 'standard' as SeatKind, x, y }
      sector.seats.push(seat)
      newSeats.push(seat)
    }
  } else {
    for (let n = 0; n < seatsPerRow; n += 1) {
      const seat: ESeat = {
        id: nextId('seat'),
        row: nextRow,
        number: n + 1,
        kind: 'standard',
        x: n * (config.seat + config.gap),
        y: (nextRow - 1) * (config.seat + config.rowGap),
      }
      sector.seats.push(seat)
      newSeats.push(seat)
    }
  }

  return { row: nextRow, seats: newSeats }
}

/* ── Банкетный стол (§47: «стол с местами») ────────────────────────── */

export interface TableBuildResult {
  seats: ESeat[]
  /** Центр кольца мест в локальных координатах сектора. */
  cx: number
  cy: number
  /** Радиус кольца (до центра места). */
  ring: number
}

/**
 * Места вокруг стола (банкет): раскладываем count мест по окружности так,
 * чтобы они не перекрывались. Радиус кольца растёт с числом мест.
 * Все места — в одном ряду (row 1), нумерация по часовой стрелке от «верха».
 */
export function buildTableSeats(
  count: number,
  nextId: (prefix: string) => string,
  config: SeatGridConfig = DEFAULT_CONFIG,
): TableBuildResult {
  const eff = Math.max(1, Math.floor(count))
  const r = Math.max(48, (eff * (config.seat + config.gap)) / (2 * Math.PI))
  const cx = r + config.seat + 10
  const cy = r + config.seat + 10
  const seats: ESeat[] = []
  for (let i = 0; i < eff; i += 1) {
    const a = -Math.PI / 2 + (i * 2 * Math.PI) / eff
    seats.push({
      id: nextId('seat'),
      row: 1,
      number: i + 1,
      kind: 'standard',
      x: Math.round(cx + r * Math.cos(a) - config.seat / 2),
      y: Math.round(cy + r * Math.sin(a) - config.seat / 2),
    })
  }
  return { seats, cx, cy, ring: r }
}

/** Геометрия банкетного стола, вычисленная по уже расставленным местам. */
export function tableLayout(sector: ESector): TableBuildResult {
  if (sector.seats.length === 0) {
    return { seats: [], cx: 0, cy: 0, ring: 0 }
  }
  let sx = 0
  let sy = 0
  for (const s of sector.seats) {
    sx += s.x + SEAT / 2
    sy += s.y + SEAT / 2
  }
  const cx = sx / sector.seats.length
  const cy = sy / sector.seats.length
  let ring = 0
  for (const s of sector.seats) {
    const d = Math.hypot(s.x + SEAT / 2 - cx, s.y + SEAT / 2 - cy)
    if (d > ring) ring = d
  }
  return { seats: sector.seats, cx, cy, ring: ring + SEAT / 2 }
}

/**
 * Пересобрать кольцо мест стола под новое число мест, сохраняя центр стола
 * неподвижным на холсте (корректируем sector.x/y — координаты группы).
 * Мутирует переданный сектор.
 */
export function rebuildTableSeats(
  sector: ESector,
  count: number,
  nextId: (prefix: string) => string,
  config: SeatGridConfig = DEFAULT_CONFIG,
): void {
  const before = tableLayout(sector)
  const canvasCx = sector.x + before.cx
  const canvasCy = sector.y + before.cy
  const eff = Math.max(1, Math.min(60, Math.floor(count)))
  const { seats, cx, cy, ring } = buildTableSeats(eff, nextId, config)
  sector.seats = seats
  sector.x = Math.round(canvasCx - cx)
  sector.y = Math.round(canvasCy - cy)
  sector.tableCx = cx
  sector.tableCy = cy
  sector.tableRing = ring
}

/* ── Операции над рядами (§48) ─────────────────────────────────────── */

/** Номера рядов сектора по порядку (без дыр). */
export function rowNumbers(sector: ESector): number[] {
  return [...new Set(sector.seats.map((s) => s.row))].sort((a, b) => a - b)
}

/** Места конкретного ряда, отсортированные слева направо. */
export function seatsInRow(sector: ESector, row: number): ESeat[] {
  return sector.seats.filter((s) => s.row === row).sort((a, b) => a.x - b.x)
}

/**
 * Удалить ряд целиком. Оставшиеся ряды ПЕРЕНУМЕРОВЫВАЮТСЯ, чтобы не осталось
 * дыры (ряд 3 после удаления ряда 2 обязан стать рядом 2 — иначе подписи на
 * схеме и в билетах расходятся, а билеты печатаются по человеческим номерам).
 * Возвращает число удалённых мест.
 */
export function deleteRow(sector: ESector, row: number): number {
  const removed = sector.seats.filter((s) => s.row === row).length
  if (removed === 0) return 0
  sector.seats = sector.seats.filter((s) => s.row !== row)

  // Сдвигаем номера вниз у всех рядов выше удалённого и пересобираем rowPrices.
  const shifted: Record<number, number> = {}
  for (const [rawRow, price] of Object.entries(sector.rowPrices)) {
    const r = Number(rawRow)
    if (r === row) continue
    shifted[r > row ? r - 1 : r] = price
  }
  sector.rowPrices = shifted
  for (const seat of sector.seats) {
    if (seat.row > row) seat.row -= 1
  }
  return removed
}

/**
 * Вставить пустой ряд ниже указанного. Вставленный ряд начинается как копия
 * соседа по числу мест: пустой ряд нечем ни нарисовать, ни продать, поэтому
 * «вставить ряд» без мест — это операция, после которой ничего не происходит.
 * Возвращает номер нового ряда.
 */
export function insertRowAfter(
  sector: ESector,
  row: number,
  seatsPerRow: number,
  nextId: (prefix: string) => string,
  config: SeatGridConfig = DEFAULT_CONFIG,
): number {
  const target = row + 1
  // Освобождаем номер: сдвигаем все ряды от target и выше вверх на один.
  for (const seat of sector.seats) {
    if (seat.row >= target) seat.row += 1
  }
  const rebased: Record<number, number> = {}
  for (const [rawRow, price] of Object.entries(sector.rowPrices)) {
    const r = Number(rawRow)
    rebased[r >= target ? r + 1 : r] = price
  }
  sector.rowPrices = rebased

  const count = Math.max(0, Math.floor(seatsPerRow))
  for (let n = 0; n < count; n += 1) {
    sector.seats.push({
      id: nextId('seat'),
      row: target,
      number: n + 1,
      kind: 'standard',
      x: n * (config.seat + config.gap),
      y: (target - 1) * (config.seat + config.rowGap),
    })
  }
  return target
}

/**
 * Перенумеровать места в рядах по позиции X (слева направо), 1..N.
 * Нужна после ручных правок: места, расставленные кликами, легко получают
 * «место 7» перед «местом 3», и на билете это выглядит как ошибка.
 * Возвращает число рядов, где нумерация изменилась.
 */
export function renumberAllSeats(sector: ESector): number {
  let changed = 0
  for (const row of rowNumbers(sector)) {
    const seats = seatsInRow(sector, row)
    let dirty = false
    seats.forEach((seat, i) => {
      if (seat.number !== i + 1) dirty = true
      seat.number = i + 1
    })
    if (dirty) changed += 1
  }
  return changed
}

/**
 * Изменить шаг сетки: переставленные на новый шаг места сектора.
 * Ряды прямоугольных секторов пересобираются от начала координат сектора,
 * чтобы шаг применился единообразно; дуги и столы не трогаем — там шаг задан
 * радиусом, и «подвинуть» его без потери формы нельзя.
 * Возвращает false, если форма сектора не поддерживает перешаг.
 */
export function applySeatStep(
  sector: ESector,
  seatStep: number,
  rowStep: number,
): boolean {
  if (sector.shape !== 'grid') return false
  const c = Math.max(1, seatStep)
  const r = Math.max(1, rowStep)
  for (const seat of sector.seats) {
    seat.x = Math.round((seat.number - 1) * c)
    seat.y = Math.round((seat.row - 1) * r)
  }
  return true
}

/**
 * Выровнять выделенные места по левому/правому/верхнему/нижнему краю или по
 * центру линии. Работает по X или Y в зависимости от оси. Это то, чем чистят
 * схему после ручных правок: ряд, где одно место уехало на 6 px, видно сразу.
 */
export type AlignEdge = 'left' | 'right' | 'top' | 'bottom' | 'center-x' | 'center-y'

export function alignSeats(seats: Pick<ESeat, 'x' | 'y'>[], edge: AlignEdge): boolean {
  if (seats.length < 2) return false
  const xs = seats.map((s) => s.x)
  const ys = seats.map((s) => s.y)
  switch (edge) {
    case 'left': {
      const v = Math.min(...xs)
      for (const s of seats) s.x = v
      return true
    }
    case 'right': {
      const v = Math.max(...xs)
      for (const s of seats) s.x = v
      return true
    }
    case 'top': {
      const v = Math.min(...ys)
      for (const s of seats) s.y = v
      return true
    }
    case 'bottom': {
      const v = Math.max(...ys)
      for (const s of seats) s.y = v
      return true
    }
    case 'center-x': {
      const v = Math.round((Math.min(...xs) + Math.max(...xs)) / 2)
      for (const s of seats) s.x = v
      return true
    }
    case 'center-y': {
      const v = Math.round((Math.min(...ys) + Math.max(...ys)) / 2)
      for (const s of seats) s.y = v
      return true
    }
    default:
      return false
  }
}

/**
 * Равномерно распределить выделенные места между крайними по X или Y.
 * Без этого «выровнять по центру» собирает места в одну точку и ряд пропадает:
 * распределение сохраняет порядок и задаёт одинаковый шаг.
 */
export function distributeSeats(seats: Pick<ESeat, 'x' | 'y'>[], axis: 'x' | 'y'): boolean {
  if (seats.length < 3) return false
  const sorted = [...seats].sort((a, b) => a[axis] - b[axis])
  const first = sorted[0][axis]
  const last = sorted[sorted.length - 1][axis]
  const step = (last - first) / (sorted.length - 1)
  sorted.forEach((seat, i) => { seat[axis] = Math.round(first + step * i) })
  return true
}
