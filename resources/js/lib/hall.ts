/**
 * Демонстрационные данные зала.
 *
 * Схема зала — это то, что в продукте приходит с сервера (HallSchemaVersion),
 * но геометрия и состояния мест описаны здесь ровно так, как их отдаёт API:
 * место — это не «кружок в SVG», а InventoryItem со своим состоянием и ценой.
 * Поэтому состояние места приходит из данных, а не выводится из вёрстки.
 */
import type { SeatState } from './types'

export interface Seat {
  id: string
  row: number
  number: number
  state: SeatState
  priceMinor: number
  /** Категория места: обычное, VIP, для маломобильных. */
  kind: 'standard' | 'vip' | 'accessible'
}

export interface Row {
  index: number
  seats: Seat[]
  /** Горизонтальный сдвиг ряда: залы редко бывают прямоугольными. */
  offset: number
}

export interface Sector {
  id: string
  name: string
  priceMinor: number
  rows: Row[]
}

export const SEAT_SIZE = 22
export const SEAT_GAP = 6
export const ROW_GAP = 12
const ROW_LABEL_WIDTH = 30
const SECTOR_GAP = 40

/** Простой детерминированный ПСЧ: демо-данные должны быть одинаковыми при каждом рендере. */
function makeRandom(seed: number) {
  let state = seed
  return () => {
    state = (state * 1103515245 + 12345) % 2147483648
    return state / 2147483648
  }
}

interface SectorSpec {
  name: string
  priceMinor: number
  rows: number
  seatsPerRow: number
  /** Доля проданных мест: у ближних к сцене секторов она выше. */
  soldRatio: number
  vipRows?: number
  accessible?: boolean
}

const SECTOR_SPECS: SectorSpec[] = [
  { name: 'Партер A', priceMinor: 850000, rows: 8, seatsPerRow: 18, soldRatio: 0.34, vipRows: 2 },
  { name: 'Партер B', priceMinor: 650000, rows: 7, seatsPerRow: 20, soldRatio: 0.22, accessible: true },
  { name: 'Балкон C', priceMinor: 420000, rows: 6, seatsPerRow: 22, soldRatio: 0.12 },
]

export function buildHall(seed = 20260922): Sector[] {
  const rand = makeRandom(seed)
  let seatId = 0

  return SECTOR_SPECS.map((spec, sectorIdx) => {
    const rows: Row[] = []

    for (let r = 0; r < spec.rows; r += 1) {
      const seats: Seat[] = []
      // Ряды к краям короче — так зал читается как зал, а не как таблица.
      const edge = Math.round(Math.abs(r - (spec.rows - 1) / 2) / 2)
      const count = spec.seatsPerRow - edge

      for (let n = 0; n < count; n += 1) {
        seatId += 1
        const isVip = spec.vipRows !== undefined && r < spec.vipRows
        const isAccessible = Boolean(spec.accessible) && r === spec.rows - 1 && (n === 0 || n === count - 1)
        const roll = rand()

        let state: SeatState = 'free'
        if (roll < spec.soldRatio) state = 'sold'
        else if (roll < spec.soldRatio + 0.04) state = 'held'
        else if (roll < spec.soldRatio + 0.055) state = 'unavailable'

        seats.push({
          id: `s${seatId}`,
          row: r + 1,
          number: n + 1,
          state,
          priceMinor: spec.priceMinor + (isVip ? 350000 : 0),
          kind: isVip ? 'vip' : isAccessible ? 'accessible' : 'standard',
        })
      }

      rows.push({ index: r + 1, seats, offset: edge * (SEAT_SIZE + SEAT_GAP) * 0.5 })
    }

    return {
      id: `sector-${sectorIdx}`,
      name: spec.name,
      priceMinor: spec.priceMinor,
      rows,
    }
  })
}

export function rowWidth(row: Row): number {
  return row.seats.length * (SEAT_SIZE + SEAT_GAP) - SEAT_GAP
}

export function sectorWidth(sector: Sector): number {
  return Math.max(...sector.rows.map(rowWidth))
}

export function sectorHeight(sector: Sector): number {
  return sector.rows.length * (SEAT_SIZE + ROW_GAP) - ROW_GAP
}

export function seatLeft(row: Row, seatIndex: number): number {
  return ROW_LABEL_WIDTH + row.offset + seatIndex * (SEAT_SIZE + SEAT_GAP)
}

export function seatTop(rowIndex: number): number {
  return (rowIndex - 1) * (SEAT_SIZE + ROW_GAP)
}

export const SEAT_GEOMETRY = { SEAT_SIZE, SEAT_GAP, ROW_GAP, ROW_LABEL_WIDTH, SECTOR_GAP }

/** Легенда — единственный источник правды о том, что означает цвет места. */
export const SEAT_LEGEND: Array<{ state: SeatState; label: string }> = [
  { state: 'free', label: 'Свободно' },
  { state: 'selected', label: 'Ваш выбор' },
  { state: 'held', label: 'Держит другой' },
  { state: 'sold', label: 'Продано' },
  { state: 'vip', label: 'VIP' },
  { state: 'accessible', label: 'Для маломобильных' },
  { state: 'unavailable', label: 'Недоступно' },
]
