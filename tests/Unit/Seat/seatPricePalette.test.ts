import { describe, expect, it } from 'vitest'
import { SEAT_PRICE_PALETTE, buildSeatPricePalette, groupTableSeatPoints } from '../../../resources/js/lib/hall'

describe('groupTableSeatPoints', () => {
  it('группирует места по столам и исключает стоячую зону', () => {
    const marks = groupTableSeatPoints([
      { sectorName: 'Столы на танцполе · стол 57', x: 0, y: 0 },
      { sectorName: 'Столы на танцполе · стол 57', x: 4, y: 0 },
      { sectorName: 'Столы на танцполе · стол 57', x: 2, y: 4 },
      { sectorName: 'Танцпол', x: 20, y: 30 },
    ])

    expect(marks).toHaveLength(1)
    expect(marks[0]).toMatchObject({ name: 'Столы на танцполе · стол 57', x: 2, y: 4 / 3, label: '57' })
    expect(marks[0].ring).toBeGreaterThan(0)
  })
})

describe('buildSeatPricePalette', () => {
  it('назначает каждому уникальному ценовому уровню отдельный цвет Афиши', () => {
    const palette = buildSeatPricePalette([150000, 70000, 100000, 120000, 100000])

    expect([...palette.keys()]).toEqual([70000, 100000, 120000, 150000])
    expect([...palette.values()]).toEqual(SEAT_PRICE_PALETTE.slice(0, 4))
    expect(palette.get(100000)).toBe(SEAT_PRICE_PALETTE[1])
  })

  it('игнорирует некорректные цены и повторяет палитру для дополнительных уровней', () => {
    const palette = buildSeatPricePalette([NaN, Infinity, 100, 200, 300, 400, 500, 600, 700])

    expect(palette.size).toBe(7)
    expect(palette.get(100)).toBe(palette.get(700))
  })
})
