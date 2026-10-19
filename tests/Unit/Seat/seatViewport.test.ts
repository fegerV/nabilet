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
  fitSeatHitRadius,
  fitSeatRadius,
  focusTransform,
  meetFit,
  nearestPointDistance,
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

describe('fitSeatRadius', () => {
  it('keeps normalized dense seats distinct after projection', () => {
    expect(fitSeatRadius([{ x: 10, y: 8 }, { x: 10.5, y: 8 }], 6)).toBe(2)
  })

  it('uses the maximum dot size for sparse maps and a single point', () => {
    expect(fitSeatRadius([{ x: 0, y: 0 }, { x: 100, y: 100 }], 1)).toBe(6)
    expect(fitSeatRadius([{ x: 2, y: 4 }], 1)).toBe(6)
  })
})

describe('nearestPointDistance', () => {
  it('считает расстояние в экранных единицах, а не в единицах зала', () => {
    // 0.5 юнита зала при масштабе 6 = 3 экранных.
    expect(nearestPointDistance([{ x: 10, y: 8 }, { x: 10.5, y: 8 }], 6)).toBeCloseTo(3, 6)
  })

  it('для одной точки расстояния нет — иначе нельзя отличить от разреженной карты', () => {
    expect(nearestPointDistance([{ x: 1, y: 1 }], 5)).toBe(Number.POSITIVE_INFINITY)
    expect(nearestPointDistance([], 5)).toBe(Number.POSITIVE_INFINITY)
  })

  it('не ищет «соседа» через ползала: обход ограничен шагом 24', () => {
    // Место в начале зала и место в конце: 225 юнитов при масштабе 1. Обход
    // рвётся на `dx > 24`, поэтому такой пары не существует — иначе радиус
    // считался бы по всемвозможным дальним парам и занижался.
    expect(nearestPointDistance([{ x: 0, y: 0 }, { x: 225, y: 0 }], 1)).toBe(Number.POSITIVE_INFINITY)
  })
})

/*
 * Зона нажатия — единственная защита выбора места на телефоне.
 *
 * Замер живого стенда (390×844): видимый кружок = 2.3 CSS px, палец по нему не
 * попадает. Зона обязана быть крупнее, но НЕ перекрывать соседа: перекрытие —
 * это выбор чужого места, что хуже промаха.
 */
describe('fitSeatHitRadius', () => {
  it('крупнее видимого кружка на плотной сетке', () => {
    const points = [{ x: 10, y: 8 }, { x: 10.5, y: 8 }]
    const radius = fitSeatHitRadius(points, 6)

    expect(radius).toBeGreaterThan(fitSeatRadius(points, 6))
  })

  it('не перекрывает соседей: потолок — 45% шага', () => {
    const points = [{ x: 0, y: 0 }, { x: 20, y: 0 }, { x: 40, y: 0 }]
    const hit = fitSeatHitRadius(points, 1)
    const pitch = nearestPointDistance(points, 1)

    expect(hit).toBeLessThanOrEqual(pitch * 0.45)
    // Две зоны нажатия соседей не пересекаются: 2*hit < pitch.
    expect(hit * 2).toBeLessThan(pitch)
  })

  it('не раздувается на разрежённой карте', () => {
    const points = [{ x: 0, y: 0 }, { x: 500, y: 500 }]

    expect(fitSeatHitRadius(points, 1)).toBeLessThanOrEqual(12)
  })

  it('одно место — зона всё равно крупнее кружка', () => {
    expect(fitSeatHitRadius([{ x: 3, y: 3 }], 1)).toBeGreaterThan(fitSeatRadius([{ x: 3, y: 3 }], 1))
  })
})

/*
 * Приближение к группе мест после тапа по чипу цены (паттерн Яндекс Афиши).
 * Проверяем ровно ту арифметику, ошибка в которой незаметна глазом: точка
 * уезжает из центра вьюпорта, и масштаб не подтягивается.
 */
describe('meetFit', () => {
  it('узкий вьюпорт масштабирует по ширине и центрирует letterbox по вертикали', () => {
    const fit = meetFit({ width: 390, height: 460 }, { width: 620, height: 400 })

    expect(fit.scale).toBeCloseTo(390 / 620, 6)
    expect(fit.offsetX).toBe(0)
    expect(fit.offsetY).toBeCloseTo((460 - 400 * (390 / 620)) / 2, 6)
  })

  it('широкий вьюпорт масштабирует по высоте и центрирует по горизонтали', () => {
    const fit = meetFit({ width: 1200, height: 300 }, { width: 620, height: 400 })

    expect(fit.scale).toBeCloseTo(300 / 400, 6)
    expect(fit.offsetY).toBe(0)
    expect(fit.offsetX).toBeCloseTo((1200 - 620 * 0.75) / 2, 6)
  })
})

describe('focusTransform', () => {
  const VIEWBOX = { width: 620, height: 400 }

  it('на зуме 1 центр зала стоит в центре вьюпорта (паритет со «весь зал»)', () => {
    const bounds = { minX: 30, maxX: 590, minY: 76, maxY: 330 }
    const box = { width: 390, height: 460 }
    const { zoom, panX, panY } = focusTransform(
      { ...bounds },
      box,
      VIEWBOX,
      { minZoom: 1, maxZoom: 1 },
    )
    const meet = meetFit(box, VIEWBOX)

    const centerX = meet.offsetX + meet.scale * ((bounds.minX + bounds.maxX) / 2)
    const centerY = meet.offsetY + meet.scale * ((bounds.minY + bounds.maxY) / 2)

    // pan + zoom * точка = центр вьюпорта.
    expect(panX + zoom * centerX).toBeCloseTo(box.width / 2, 6)
    expect(panY + zoom * centerY).toBeCloseTo(box.height / 2, 6)
  })

  it('участок занимает заданную долю SmallerSide — тап по месту достижим', () => {
    // Один ряд из 5 мест шириной 100 юнитов viewBox.
    const bounds = pointBounds([
      { x: 100, y: 200 },
      { x: 200, y: 200 },
    ])
    const box = { width: 390, height: 460 }
    const { zoom } = focusTransform(bounds, box, VIEWBOX, { fill: 0.8, maxZoom: 8 })
    const meet = meetFit(box, VIEWBOX)

    // Видимая ширина в юнитах viewBox на этом зуме:
    const visibleUnits = box.width / (meet.scale * zoom)

    expect(visibleUnits).toBeCloseTo(100 / 0.8, 6)
  })

  it('не выходит за разрешённые границы зума', () => {
    const huge = { minX: 0, maxX: 600, minY: 0, maxY: 400 }
    const tiny = { minX: 100, maxX: 101, minY: 100, maxY: 101 }

    expect(focusTransform(huge, { width: 390, height: 460 }, VIEWBOX, { minZoom: 0.6, maxZoom: 8 }).zoom).toBeGreaterThanOrEqual(0.6)
    expect(focusTransform(tiny, { width: 390, height: 460 }, VIEWBOX, { minZoom: 0.6, maxZoom: 8 }).zoom).toBeLessThanOrEqual(8)
  })

  it('зум ограничивается высотой: высокий узкий участок не растягивается на всю ширину', () => {
    // Габариты 10×380 юнитов: по ширине зум был бы 49, по высоте — 1.5. Берётся
    // меньший, иначе участок вылезал бы за нижнюю границу вьюпорта.
    const bounds = { minX: 300, maxX: 310, minY: 20, maxY: 400 }
    const box = { width: 390, height: 460 }
    const { zoom } = focusTransform(bounds, box, VIEWBOX, { fill: 0.8, maxZoom: 8 })
    const meet = meetFit(box, VIEWBOX)

    expect(box.height / (meet.scale * zoom)).toBeCloseTo(380 / 0.8, 6)
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
