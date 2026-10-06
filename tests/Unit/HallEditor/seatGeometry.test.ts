/**
 * Юнит-тесты чистой геометрии редактора залов (seatGeometry).
 *
 * Раньше эта логика была заперта внутри HallEditorPage.vue и не тестировалась:
 * проверяем round-trip формул дуги, раскладку сетки и добавление ряда.
 */
import { describe, expect, it } from 'vitest'
import type { ESector } from '../../../resources/js/pages/hall-editor/editorTypes'
import {
  GAP,
  MAX_ARC_SPREAD_DEG,
  ROW_GAP,
  SEAT,
  appendRowToSector,
  findFreeSeatPosition,
  arcLayoutParams,
  arcSeatXY,
  buildArcSeats,
  buildGridSeats,
  seatKindFor,
} from '../../../resources/js/pages/hall-editor/seatGeometry'
import { unwrapSchemaRoot } from '../../../resources/js/pages/hall-editor/schemaImport'

let counter = 0
const nextId = (prefix: string) => `${prefix}-${(counter += 1)}`

function makeArcSector(): ESector {
  const rows = 5
  const seatsPerRow = 10
  const spread = 160
  return {
    id: 'sec-1',
    name: 'Партер A',
    priceMinor: 850000,
    x: 0,
    y: 0,
    seats: [],
    rowPrices: {},
    shape: 'arc',
    arcSpread: spread,
    ...arcLayoutParams(rows, seatsPerRow, spread),
  }
}

describe('seatGeometry: константы шага', () => {
  it('сохраняют размеры исходного редактора', () => {
    expect(SEAT).toBe(16)
    expect(GAP).toBe(6)
    expect(ROW_GAP).toBe(12)
  })
})

describe('seatGeometry: buildGridSeats', () => {
  it('раскладывает прямоугольную сетку с шагом SEAT+GAP / SEAT+ROW_GAP', () => {
    const seats = buildGridSeats(3, 4, 1, nextId)
    expect(seats).toHaveLength(12)
    expect(seats[0]).toMatchObject({ row: 1, number: 1, x: 0, y: 0 })
    expect(seats[1].x).toBe(SEAT + GAP)
    expect(seats[4].y).toBe(SEAT + ROW_GAP)
  })

  it('назначает VIP первые vipRows рядов и доступные места по краям', () => {
    const seats = buildGridSeats(2, 4, 1, nextId)
    expect(seats.slice(0, 4).every((s) => s.kind === 'vip')).toBe(true)
    expect(seats[4].kind).toBe('accessible')
    expect(seats[7].kind).toBe('accessible')
    expect(seats[5].kind).toBe('standard')
  })
})

describe('seatGeometry: дуга (амфитеатр)', () => {
  it('arcLayoutParams задаёт растущий радиус и положительный arcOffsetX', () => {
    const p = arcLayoutParams(5, 10, 160)
    expect(p.arcRowGap).toBe(SEAT + ROW_GAP)
    expect(p.arcBaseR).toBeGreaterThan(0)
    expect(p.arcOffsetX).toBeGreaterThan(0)
  })

  it('места одного ряда лежат на одной окружности', () => {
    const sector = makeArcSector()
    const seats = buildArcSeats(sector, 3, 9, 0, nextId)
    const cx = sector.arcOffsetX
    const cy = sector.arcOffsetY
    const r0 = Math.hypot(seats[0].x - cx, seats[0].y - cy)
    for (const s of seats.slice(1, 9)) {
      expect(Math.hypot(s.x - cx, s.y - cy)).toBeCloseTo(r0, 6)
    }
  })

  it('arcSeatXY симметричен относительно центра дуги', () => {
    const sector = makeArcSector()
    const n = 7
    const first = arcSeatXY(sector, 2, 0, n)
    const last = arcSeatXY(sector, 2, n - 1, n)
    const mid = arcSeatXY(sector, 2, (n - 1) / 2, n)
    expect((first.x + last.x) / 2).toBeCloseTo(mid.x, 6)
  })

  it('ограничивает полный круг полукругом, не совмещая крайние места', () => {
    const sector = makeArcSector()
    sector.arcSpread = 360
    const seats = buildArcSeats(sector, 1, 10, 0, nextId)
    expect(MAX_ARC_SPREAD_DEG).toBe(180)
    expect(Math.hypot(seats[0].x - seats[9].x, seats[0].y - seats[9].y)).toBeGreaterThan(SEAT)
    expect(arcLayoutParams(1, 10, 360).arcBaseR).toBe(arcLayoutParams(1, 10, 180).arcBaseR)
  })
})

describe('seatGeometry: appendRowToSector', () => {
  it('добавляет ряд в сетку с корректной нумерацией', () => {
    const sector = makeArcSector()
    sector.shape = 'grid'
    sector.seats = buildGridSeats(2, 5, 0, nextId)
    const { row, seats } = appendRowToSector(sector, 5, nextId)
    expect(row).toBe(3)
    expect(seats).toHaveLength(5)
    expect(seats.map((s) => s.number)).toEqual([1, 2, 3, 4, 5])
    expect(sector.seats).toHaveLength(15)
  })

  it('в дуге добавляет новый ряд и сохраняет непрерывность нумерации', () => {
    const sector = makeArcSector()
    sector.seats = buildArcSeats(sector, 4, 9, 0, nextId)
    const beforeX = sector.seats[0].x
    const oldOffset = sector.arcOffsetX
    const { row, seats } = appendRowToSector(sector, 12, nextId)
    expect(row).toBe(5)
    expect(seats).toHaveLength(12)
    expect(sector.seats.filter((s) => s.row === row)).toHaveLength(12)
    // Если центровка дуги изменилась — старые места сдвинулись синхронно.
    if (sector.arcOffsetX !== oldOffset) {
      expect(sector.seats[0].x).not.toBe(beforeX)
    }
  })
})

describe('seatGeometry: seatKindFor', () => {
  it('крайние места обычных рядов — accessible, остальные — standard', () => {
    expect(seatKindFor(1, 0, 8, 1)).toBe('accessible')
    expect(seatKindFor(1, 3, 8, 1)).toBe('standard')
    expect(seatKindFor(0, 3, 8, 1)).toBe('vip')
  })
})

describe('seatGeometry: поиск свободного места', () => {
  it('сдвигает новое место, если предложенная позиция пересекается с существующей', () => {
    const existing = [{ x: 22, y: 0 }, { x: 44, y: 0 }]
    const free = findFreeSeatPosition(existing, 22, 0)
    expect(free).toEqual({ x: 66, y: 0 })
    expect(existing.some((seat) => Math.abs(seat.x - free.x) < SEAT && Math.abs(seat.y - free.y) < SEAT)).toBe(false)
  })

  it('уходит на свободный ряд, если горизонтальная полоса плотно занята', () => {
    const existing = Array.from({ length: 4 }, (_, i) => ({ x: i * 22, y: 0 }))
    const free = findFreeSeatPosition(existing, 0, 0, SEAT, 4)
    expect(free.y).toBeGreaterThan(Math.max(...existing.map((seat) => seat.y + SEAT)))
  })
})

describe('schemaImport: envelope', () => {
  it('возвращает внутреннюю схему для { schema }', () => {
    const schema = { sectors: [{ name: 'Партер', seats: [] }] }
    expect(unwrapSchemaRoot({ schema })).toBe(schema)
  })

  it('оставляет обычный JSON схемы без изменений и отклоняет не-объекты', () => {
    const schema = { sectors: [] }
    expect(unwrapSchemaRoot(schema)).toBe(schema)
    expect(unwrapSchemaRoot([])).toBeNull()
    expect(unwrapSchemaRoot(null)).toBeNull()
  })
})
