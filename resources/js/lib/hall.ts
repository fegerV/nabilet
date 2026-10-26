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

/** Цвета свободных мест следуют ценовым уровням, как в Яндекс Афише. */
export const SEAT_PRICE_PALETTE = ['#24B8E8', '#4FC45D', '#FFC928', '#63D4E9', '#D879B8', '#F27C5B'] as const

/** Один цвет на один уникальный прайс: одинаковая цена читается одинаково на любой карте. */
export function buildSeatPricePalette(prices: readonly number[]): Map<number, string> {
  const tiers = [...new Set(prices.map(Number).filter(Number.isFinite).map(Math.round))].sort((a, b) => a - b)
  return new Map(tiers.map((price, index) => [price, SEAT_PRICE_PALETTE[index % SEAT_PRICE_PALETTE.length]]))
}

/**
 * Действующая цена узла схемы — зеркало серверного `Venues\Domain\SchemaPrice`.
 *
 * ЗАЧЕМ ЗЕРКАЛО. Схема зала описывает одну и ту же цену двумя именами:
 * `priceMinor` — поле редактора (§50), `price` — легаси сидеров и импорта
 * Афиши. На сервере приоритет владеет `SchemaPrice`; клиент читает те же узлы,
 * и без общей функции цепочка «`priceMinor` ?? `price`» была скопирована в
 * `schemaSerialization` дважды. Копия на клиенте опаснее серверной: она не
 * проходит гейты и расходится молча — редактор показал бы ноль там, где
 * сервер продаёт по легаси-полю.
 *
 * ПОЧЕМУ ОКРУГЛЕНИЕ, ХОТЯ СЕРВЕР ВЕЩЕСТВЕННОЕ ЧИСЛО ОТВЕРГАЕТ. Клиент цену не
 * НАЗНАЧАЕТ, а показывает: `schemaSerialization` уже приводит значения к целым
 * копейкам при загрузке, поэтому округление здесь — не решение о цене, а
 * страховка от `NaN` в цветовой шкале. Владелец правила «деньги только целые» —
 * сервер (`HallService`), и он по-прежнему отвергает `2999.5`.
 */
export const NODE_PRICE_KEYS = ['priceMinor', 'price'] as const

export interface PriceNode {
  priceMinor?: unknown
  /** БД-формат (`rows[].price_amount`, `rows[].seats[].price_amount`). */
  price_amount?: unknown
  /** Легаси-имя (сидеры, импорт Афиши). */
  price?: unknown
}

/** Значение цены в минорных единицах или null, если это не цена. */
function parseMinor(value: unknown): number | null {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.round(value) : null
  if (typeof value === 'string' && /^-?\d{1,15}$/.test(value.trim())) return Number(value.trim())
  return null
}

/** Цена узла (сектор, стоячая зона): `priceMinor` побеждает легаси `price`. */
export function nodePriceMinor(node: PriceNode | null | undefined): number {
  return nodePriceOrNull(node) ?? 0
}

/**
 * То же, но «цены нет» отличается от нулевой цены.
 *
 * Нужно там, где модель различает «поле не задано» и «задано 0»: у статического
 * объекта `undefined` означает «цены нет» (поле не уедет в payload), а `0`
 * уехало бы и было отвергнуто сервером как непродаваемая стоячая зона.
 */
export function nodePriceOrNull(node: PriceNode | null | undefined): number | null {
  for (const key of NODE_PRICE_KEYS) {
    const parsed = parseMinor(node?.[key])
    if (parsed !== null) return parsed
  }
  return null
}

/**
 * Имена поля индивидуальной цены места, в порядке приоритета.
 *
 * Три, а не два, потому что место встречается в двух форматах: `priceMinor` —
 * редактор, `price_amount` — БД-формат (`rows[].seats[]`), `price` — легаси.
 * Порядок совпадает с серверным (`HallService` проверяет `price_amount`, затем
 * `price`); раньше два места в этом файле перечисляли их в РАЗНОМ порядке, и
 * схема с обоими полями читалась по-разному в зависимости от формата.
 */
export const SEAT_PRICE_KEYS = ['priceMinor', 'price_amount', 'price'] as const

/**
 * Индивидуальная цена места — зеркало `SchemaPrice::seatOverride()`.
 *
 * Возвращает `undefined`, если своей цены нет: место наследует цену ряда.
 * Некорректное значение (ноль, отрицательное, нечисло) — тоже «нет своей
 * цены», а не «цена ноль», иначе место продавалось бы бесплатно.
 */
export function seatPriceOverride(node: PriceNode | null | undefined): number | undefined {
  for (const key of SEAT_PRICE_KEYS) {
    const raw = node?.[key]
    if (raw === null || raw === undefined) continue

    const parsed = parseMinor(raw)

    return parsed !== null && parsed >= 1 ? parsed : undefined
  }
  return undefined
}

/**
 * Цена, по которой место продаётся: место → ряд (`rowPrices`) → сектор.
 *
 * Нулевое значение — это ОТВЕТ, а не «не задано»: цепочка останавливается на
 * первом объявленном уровне, ровно как `??` на сервере. Иначе место с явным
 * нулём молча показывало бы цену ряда, которой его не продают.
 */
export function effectiveSeatPrice(
  seat: { priceMinor?: number | null; row: number },
  sector: PriceNode & { rowPrices?: Record<number, number> | null },
): number {
  const override = seat.priceMinor
  if (override !== null && override !== undefined) return override
  const byRow = sector.rowPrices?.[seat.row]
  if (byRow !== null && byRow !== undefined) return byRow
  return nodePriceMinor(sector)
}

export interface TableSeatPoint {
  sectorName?: string | null
  x: number
  y: number
}

export interface TableMark {
  name: string
  x: number
  y: number
  ring: number
  label: string
}

/** Собирает точки мест в отдельные круглые столы, не путая с standing-зонами. */
export function groupTableSeatPoints(points: readonly TableSeatPoint[]): TableMark[] {
  const groups = new Map<string, TableSeatPoint[]>()
  for (const point of points) {
    const name = String(point.sectorName ?? '')
    if (!name.toLocaleLowerCase('ru').includes('стол')) continue
    if (!groups.has(name)) groups.set(name, [])
    groups.get(name)!.push(point)
  }

  return [...groups.entries()].map(([name, seats]) => {
    const x = seats.reduce((sum, seat) => sum + seat.x, 0) / seats.length
    const y = seats.reduce((sum, seat) => sum + seat.y, 0) / seats.length
    const ring = Math.max(...seats.map((seat) => Math.hypot(seat.x - x, seat.y - y)), 0)
    return { name, x, y, ring, label: name.match(/стол\s*№?\s*(\d+)/i)?.[1] ?? '' }
  })
}

export const SEAT_SIZE = 16
export const SEAT_GAP = 8
export const ROW_GAP = 14
const ROW_LABEL_WIDTH = 30
const SECTOR_GAP = 48

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
  // Тестовый концертный зал: один сектор, 5 рядов по 12 мест.
  {
    name: 'Зал',
    priceMinor: 500000,
    rows: 5,
    seatsPerRow: 12,
    soldRatio: 0.0,
    vipRows: 1,
  },
]

/** Цена места по номеру ряда, копейки. Соответствует hall_rows БД. */
const PRICE_BY_ROW: number[] = [500000, 350000, 250000, 150000, 100000]

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
                  priceMinor: PRICE_BY_ROW[r] ?? spec.priceMinor,
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
