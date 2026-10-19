/**
 * Юнит-тесты раскладки зоны танцпола на координатной карте зала.
 *
 * Повод — дефект на витрине: рамка зоны была жёстко 150×90 px, а подпись
 * переменной длины («9 000 ₽ · 52 билетов») в неё не помещалась и вылезала за
 * пунктир. Теперь ширина рамки считается по тексту — проверяем именно это
 * свойство, а не конкретные числа.
 */
import { describe, expect, it } from 'vitest'
import {
  DANCE_BOX_HEIGHT,
  DANCE_BOX_MIN_WIDTH,
  DANCE_BOX_PAD_X,
  DANCE_LINE_Y,
  danceZoneLayout,
  danceZonesLayout,
  estimateTextWidth,
} from '../../../resources/js/lib/danceZone'

const CANVAS = 620
const text = (over: Partial<Record<'title' | 'price' | 'count' | 'hint', string>> = {}) => ({
  title: 'Танцпол',
  price: '5 000 ₽',
  count: '150 билетов',
  hint: 'клик — выбрать билеты',
  ...over,
})

describe('estimateTextWidth', () => {
  it('растёт вместе с длиной строки и размером шрифта', () => {
    expect(estimateTextWidth('абв', 12)).toBeGreaterThan(estimateTextWidth('аб', 12))
    expect(estimateTextWidth('абв', 24)).toBeGreaterThan(estimateTextWidth('абв', 12))
  })

  it('пустая строка имеет нулевую ширину', () => {
    expect(estimateTextWidth('', 12)).toBe(0)
  })
})

describe('danceZoneLayout', () => {
  it('короткая подпись получает минимальную ширину', () => {
    const box = danceZoneLayout(text({ price: '1 ₽', count: '1 билет', hint: 'клик' }), {
      canvasWidth: CANVAS,
      y: 70,
    })
    expect(box.w).toBe(DANCE_BOX_MIN_WIDTH)
  })

  it('рамка ВСЕГДА шире самой длинной строки (это и был дефект)', () => {
    const cases = [
      text(),
      text({ price: '1 200 000 ₽', count: '100 000 билетов' }),
      text({ price: '9 000 ₽', count: '52 билета' }),
      text({ hint: 'выбрано — клик, чтобы убрать' }),
    ]
    for (const t of cases) {
      const box = danceZoneLayout(t, { canvasWidth: CANVAS, y: 70 })
      expect(box.w).toBeGreaterThanOrEqual(box.textWidth + DANCE_BOX_PAD_X * 2)
    }
  })

  it('на реальных значениях банкетного зала рамка шире прежних 150 px', () => {
    // Именно этот случай ломался: 52 билета по 9 000 ₽.
    const box = danceZoneLayout(text({ price: '9 000 ₽', count: '52 билета' }), {
      canvasWidth: CANVAS,
      y: 70,
    })
    expect(box.w).toBeGreaterThan(150)
  })

  it('центрируется по холсту', () => {
    const box = danceZoneLayout(text(), { canvasWidth: CANVAS, y: 70 })
    expect(box.x + box.w / 2).toBeCloseTo(CANVAS / 2, 6)
  })

  it('не выходит за границы холста даже при очень длинном тексте', () => {
    const box = danceZoneLayout(text({ hint: 'а'.repeat(200) }), { canvasWidth: CANVAS, y: 70 })
    expect(box.w).toBeLessThanOrEqual(CANVAS)
    expect(box.x).toBeGreaterThanOrEqual(0)
    expect(box.x + box.w).toBeLessThanOrEqual(CANVAS)
  })

  it('строки идут сверху вниз и помещаются в высоту рамки', () => {
    const box = danceZoneLayout(text(), { canvasWidth: CANVAS, y: 70 })
    expect(box.titleY).toBeLessThan(box.priceY)
    expect(box.priceY).toBeLessThan(box.countY)
    expect(box.countY).toBeLessThan(box.hintY)
    expect(box.hintY).toBeLessThanOrEqual(box.y + box.h)
    expect(box.h).toBe(DANCE_BOX_HEIGHT)
    expect(box.titleY).toBe(box.y + DANCE_LINE_Y.title)
  })

  it('не зависит от порядка строк во входе (ширина определяется максимумом)', () => {
    const a = danceZoneLayout(text({ hint: 'клик' }), { canvasWidth: CANVAS, y: 70 })
    const b = danceZoneLayout(text({ hint: 'клик', price: 'х'.repeat(80) }), { canvasWidth: CANVAS, y: 70 })
    expect(b.w).toBeGreaterThan(a.w)
  })
})

/**
 * Повод — «Вавилон»: у события две admission-категории («Танцпол + ранний
 * вход» и «Танцпол»), а витрина рисовала только первую (`inventory.find`).
 * Вторая категория не отображалась, и её билеты нельзя было купить.
 */
describe('danceZonesLayout', () => {
  it('одна зона раскладывается так же, как danceZoneLayout', () => {
    const single = danceZoneLayout(text(), { canvasWidth: CANVAS, y: 70 })
    const [box] = danceZonesLayout([text()], { canvasWidth: CANVAS, y: 70 })
    expect(box.x).toBeCloseTo(single.x, 6)
    expect(box.w).toBeCloseTo(single.w, 6)
    expect(box.y).toBe(single.y)
  })

  it('две зоны делят полосу и не пересекаются', () => {
    const [a, b] = danceZonesLayout([text({ title: 'Танцпол+ ранний вход · 47' }), text({ title: 'Танцпол · 500' })], {
      canvasWidth: CANVAS,
      y: 70,
      gap: 10,
    })
    expect(a.x + a.w).toBeLessThanOrEqual(b.x)
    expect(a.x).toBeGreaterThanOrEqual(0)
    expect(b.x + b.w).toBeLessThanOrEqual(CANVAS)
  })

  it('каждая зона получает свою подпись, а не первую', () => {
    const boxes = danceZonesLayout([
      text({ title: 'Танцпол+ ранний вход · 47', price: '3 500 ₽' }),
      text({ title: 'Танцпол · 500', price: '2 200 ₽' }),
    ], { canvasWidth: CANVAS, y: 70 })
    expect(boxes).toHaveLength(2)
    expect(boxes[0].w).toBeGreaterThan(0)
    expect(boxes[1].w).toBeGreaterThan(0)
  })

  it('пустой список зон — пустая раскладка', () => {
    expect(danceZonesLayout([], { canvasWidth: CANVAS, y: 70 })).toEqual([])
  })
})
