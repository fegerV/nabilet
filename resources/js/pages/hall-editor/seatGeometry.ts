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

export interface SeatGridConfig {
  seat: number
  gap: number
  rowGap: number
}

const DEFAULT_CONFIG: SeatGridConfig = { seat: SEAT, gap: GAP, rowGap: ROW_GAP }

/** Координата места на дуге (амфитеатр). Центр кривизны — в (arcOffsetX, arcOffsetY). */
export function arcSeatXY(sector: ESector, r: number, n: number, seatsPerRow: number): { x: number; y: number } {
  const theta = (sector.arcSpread * Math.PI) / 180
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
  const theta = (arcSpreadDeg * Math.PI) / 180
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
    const theta = (sector.arcSpread * Math.PI) / 180
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
