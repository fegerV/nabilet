/**
 * Юнит-тесты чистой геометрии вписывания зала в полотно (seatViewport).
 *
 * Раньше эта математика была заперта внутри `computed`-цепочек
 * `CoordSeatMap.vue` и не проверялась ничем: чтобы заметить ошибку, нужно было
 * смонтировать SVG и глазами увидеть пустой зал. Ошибка при этом не косметическая
 * — при неверном масштабе карта не рисует ни одного места, и выбрать место
 * (главное действие витрины) невозможно.
 */
import { describe, expect, it } from 'vitest'
import {
  EMPTY_BOUNDS,
  fitPoints,
  pointBounds,
  projectPoint,
} from '../../../resources/js/lib/seatViewport'

/** Реальные параметры `CoordSeatMap.vue`. */
const CANVAS = { width: 620, height: 400, pad: 30, reservedTop: 46 }

describe('pointBounds', () => {
  it('возвращает невырожденный диапазон для пустого набора', () => {
    const b = pointBounds([])

    expect(b).toEqual(EMPTY_BOUNDS)
    expect(b.maxX).toBeGreaterThan(b.minX)
    expect(b.maxY).toBeGreaterThan(b.minY)
  })

  it('расширяет вырожденный диапазон одной точки на единицу', () => {
    const b = pointBounds([{ x: 5, y: 7 }])

    expect(b.minX).toBe(5)
    expect(b.maxX).toBe(6)
    expect(b.minY).toBe(7)
    expect(b.maxY).toBe(8)
  })

  it('расширяет диапазон, когда все точки лежат на одной линии', () => {
    const b = pointBounds([
      { x: 0, y: 10 },
      { x: 100, y: 10 },
    ])

    expect(b.minY).toBe(10)
    expect(b.maxY).toBe(11)
  })

  it('находит настоящие габариты для набора точек', () => {
    const b = pointBounds([
      { x: 3, y: 60 },
      { x: 90, y: 110 },
      { x: 12, y: 85 },
    ])

    expect(b).toEqual({ minX: 3, maxX: 90, minY: 60, maxY: 110 })
  })
})

describe('fitPoints', () => {
  it('берёт меньший из двух масштабов, чтобы зал влез целиком', () => {
    // spanX = 900, spanY = 50; полезная ширина 560, полезная высота 294.
    const fit = fitPoints(
      [
        { x: 0, y: 60 },
        { x: 900, y: 110 },
      ],
      CANVAS,
    )

    expect(fit.scale).toBeCloseTo(560 / 900, 6)
    expect(fit.scale).toBeLessThan(294 / 50)
  })

  it('учитывает полосу сцены в вертикальном отступе', () => {
    const fit = fitPoints([{ x: 0, y: 0 }], CANVAS)

    expect(fit.offsetX).toBe(CANVAS.pad)
    expect(fit.offsetY).toBe(CANVAS.pad + CANVAS.reservedTop)
  })

  it('не делится на ноль при пустом инвентаре', () => {
    const fit = fitPoints([], CANVAS)

    expect(Number.isFinite(fit.scale)).toBe(true)
    expect(fit.scale).toBeGreaterThan(0)
  })

  it('не уходит в бесконечность при вырожденных габаритах', () => {
    const fit = fitPoints([{ x: 42, y: 42 }], CANVAS)

    expect(Number.isFinite(fit.scale)).toBe(true)
    expect(fit.scale).toBeGreaterThan(0)
  })
})

describe('projectPoint', () => {
  it('отображает минимальные координаты в начало области', () => {
    const fit = fitPoints([{ x: 0, y: 0 }], CANVAS)

    expect(projectPoint(0, 0, fit)).toEqual({ x: CANVAS.pad, y: CANVAS.pad + CANVAS.reservedTop })
  })

  it('НЕ инвертирует ось Y: больше y — ниже на экране', () => {
    const fit = fitPoints(
      [
        { x: 0, y: 60 },
        { x: 0, y: 110 },
      ],
      CANVAS,
    )

    const row1 = projectPoint(0, 60, fit)
    const row2 = projectPoint(0, 110, fit)

    // Ряд 1 (y = 60, ближе к сцене) обязан быть ВЫШЕ ряда 2 на экране.
    expect(row1.y).toBeLessThan(row2.y)
  })

  it('сохраняет пропорции зала: масштаб одинаков по обеим осям', () => {
    const fit = fitPoints(
      [
        { x: 0, y: 0 },
        { x: 100, y: 100 },
      ],
      CANVAS,
    )

    const a = projectPoint(0, 0, fit)
    const b = projectPoint(50, 50, fit)

    expect(b.x - a.x).toBeCloseTo(b.y - a.y, 6)
  })
})

/**
 * Регрессия на конкретный дефект: карта рисовала пустой зал.
 *
 * Прежняя формула была `y = PAD + STAGE_H + (sy - minY) * scale` при жёстком
 * `scale = 10` и предполагала «данные 0..40». Для настоящей схемы зала
 * (`hall_schema_versions.schema_json`: полотно 900x520, места на y = 60 и
 * y = 110) масштаб 10 уводил координаты за границы viewBox 620x400.
 */
describe('регрессия: зал реальной схемы помещается в полотно', () => {
  const points = Array.from({ length: 10 }, (_, i) => ({
    x: i * 100,
    y: i < 5 ? 60 : 110,
  }))

  it('все места попадают внутрь viewBox', () => {
    const fit = fitPoints(points, CANVAS)

    for (const p of points) {
      const { x, y } = projectPoint(p.x, p.y, fit)

      expect(x).toBeGreaterThanOrEqual(0)
      expect(x).toBeLessThanOrEqual(CANVAS.width)
      expect(y).toBeGreaterThanOrEqual(0)
      expect(y).toBeLessThanOrEqual(CANVAS.height)
    }
  })

  it('места не заезжают в полосу сцены', () => {
    const fit = fitPoints(points, CANVAS)

    const topMost = Math.min(...points.map((p) => projectPoint(p.x, p.y, fit).y))

    expect(topMost).toBeGreaterThanOrEqual(CANVAS.pad + CANVAS.reservedTop)
  })

  it('старая формула уводила место за верхнюю границу полотна', () => {
    // Фиксация того, что дефект был реальным, а не гипотетическим. Прежняя
    // арифметика: `y = PAD + (40 - sy) * 10`, где 40 — захардкоженный «максимум
    // данных», а 10 — захардкоженный масштаб. Для места ряда 1 (y = 60):
    const legacyY = CANVAS.pad + (40 - 60) * 10

    expect(legacyY).toBe(-170)
    expect(legacyY).toBeLessThan(0)
  })
})
