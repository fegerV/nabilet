/**
 * Юнит-тесты чистых правил полей редактора залов (hallEditorFields).
 *
 * До выноса (P2) эти правила были размазаны по сеттерам инспектора внутри
 * HallEditorPage.vue и не проверялись ничем: чтобы дотянуться до них, тесту
 * пришлось бы монтировать страницу с Konva-холстом и jsdom-заглушками.
 *
 * Здесь фиксируем ровно те инварианты, из-за которых поля вообще существуют:
 *  - цена показывается в поле ЦЕЛЫМИ рублями (дробные копейки там не
 *    редактируются);
 *  - пустой ввод цены ряда — это СНЯТИЕ переопределения, а не ошибка;
 *  - границы полей фона/статики совпадают с тем, что ждёт сервер;
 *  - отрицательный угол поворота приводится к [0, 360), а не остаётся
 *    отрицательным, как дал бы `% 360`.
 *
 * САМА КОНВЕРСИЯ РУБЛЕЙ В КОПЕЙКИ ПРОВЕРЯЕТСЯ В `tests/Unit/money.test.ts`:
 * она переехала во владельца (`@/lib/money`), и держать её тесты здесь значило
 * бы держать вторую копию уже и в тестах.
 */
import { describe, expect, it } from 'vitest'
import {
  clampFloatOrNull,
  clampIntOrNull,
  normalizeRotation,
  priceRubField,
  requiredNonNegativeError,
  roundOrNull,
  rowPriceError,
} from '../../../resources/js/pages/hall-editor/hallEditorFields'

describe('priceRubField', () => {
  it('показывает копейки в рублях', () => {
    expect(priceRubField(150_000)).toBe('1500')
    expect(priceRubField(85_000)).toBe('850')
  })

  it('округляет до целых рублей — поле правит целые', () => {
    expect(priceRubField(155_050)).toBe('1551')
    expect(priceRubField(1)).toBe('0')
  })

  it('НЕ делит на 100 дважды', () => {
    // Регрессия: здесь стояло `String(Math.round((groupSeatPriceRub) / 100))`,
    // где `groupSeatPriceRub` уже был в рублях. Место за 1500 ₽ показывалось
    // как 15 ₽ — в сто раз дешевле, чем оно стоит.
    expect(priceRubField(150_000)).not.toBe('15')
  })

  it('ноль — это «0», а не пустая строка', () => {
    expect(priceRubField(0)).toBe('0')
  })
})

describe('rowPriceError', () => {
  it('пустой ввод — это сброс переопределения, а не ошибка', () => {
    expect(rowPriceError('')).toBeUndefined()
    expect(rowPriceError(null)).toBeUndefined()
    expect(rowPriceError(undefined)).toBeUndefined()
  })

  it('принимает корректную цену', () => {
    expect(rowPriceError(850)).toBeUndefined()
    expect(rowPriceError('1250.50')).toBeUndefined()
    // Ровно одна копейка — нижняя допустимая граница.
    expect(rowPriceError(0.01)).toBeUndefined()
  })

  it('сообщает о нечисловом вводе', () => {
    expect(rowPriceError('abc')).toBe('Введите число')
    expect(rowPriceError(Number.NaN)).toBe('Введите число')
  })

  it('сообщает об отрицательной цене', () => {
    expect(rowPriceError(-1)).toBe('Цена не может быть отрицательной')
  })

  it('сообщает о нулевой цене как о «больше нуля»', () => {
    expect(rowPriceError(0)).toBe('Цена должна быть больше нуля')
    expect(rowPriceError(0.001)).toBe('Цена должна быть больше нуля')
  })
})

describe('requiredNonNegativeError', () => {
  it('требует число', () => {
    expect(requiredNonNegativeError('', 'Вместимость')).toBe('Вместимость: введите число')
    expect(requiredNonNegativeError(null, 'Ширина')).toBe('Ширина: введите число')
    expect(requiredNonNegativeError('abc', 'Ширина')).toBe('Ширина: введите число')
  })

  it('запрещает отрицательные значения, но разрешает ноль', () => {
    expect(requiredNonNegativeError(-1, 'Вместимость')).toBe('Вместимость не может быть отрицательным')
    expect(requiredNonNegativeError(0, 'Вместимость')).toBeUndefined()
    expect(requiredNonNegativeError(120, 'Вместимость')).toBeUndefined()
  })

  it('подставляет подпись по умолчанию', () => {
    expect(requiredNonNegativeError('abc')).toBe('Значение: введите число')
  })
})

describe('clampIntOrNull', () => {
  it('зажимает значение в границы и округляет', () => {
    expect(clampIntOrNull(5, 1, 60)).toBe(5)
    expect(clampIntOrNull(0, 1, 60)).toBe(1)
    expect(clampIntOrNull(999, 1, 60)).toBe(60)
    expect(clampIntOrNull(7.6, 1, 60)).toBe(8)
  })

  it('возвращает null для нечислового ввода', () => {
    expect(clampIntOrNull('abc', 1, 60)).toBeNull()
    expect(clampIntOrNull(Number.NaN, 1, 60)).toBeNull()
    expect(clampIntOrNull(undefined, 1, 60)).toBeNull()
  })

  it('не превращает пустую строку в ноль', () => {
    // Number('') === 0 — сеттер обязан отличить «пусто» от «ноль».
    expect(clampIntOrNull('', 1, 60)).toBe(1)
  })
})

describe('clampFloatOrNull', () => {
  it('зажимает в границы без округления', () => {
    expect(clampFloatOrNull(0.5, 0, 1)).toBe(0.5)
    expect(clampFloatOrNull(-3, 0, 1)).toBe(0)
    expect(clampFloatOrNull(9, 0, 1)).toBe(1)
  })

  it('возвращает null для нечислового ввода', () => {
    expect(clampFloatOrNull('abc', 0, 1)).toBeNull()
    expect(clampFloatOrNull(Number.NaN, 0, 1)).toBeNull()
  })
})

describe('roundOrNull', () => {
  it('округляет до целого', () => {
    expect(roundOrNull(7.4)).toBe(7)
    expect(roundOrNull(7.5)).toBe(8)
    expect(roundOrNull(-7.5)).toBe(-7)
  })

  it('возвращает null для нечислового ввода', () => {
    expect(roundOrNull('abc')).toBeNull()
    expect(roundOrNull(Number.POSITIVE_INFINITY)).toBeNull()
  })
})

describe('normalizeRotation', () => {
  it('оставляет угол в диапазоне [0, 360) без изменений', () => {
    expect(normalizeRotation(0)).toBe(0)
    expect(normalizeRotation(90)).toBe(90)
    expect(normalizeRotation(359)).toBe(359)
  })

  it('приводит отрицательный угол, а не оставляет его отрицательным', () => {
    // Регресс: `-30 % 360` в JS равно -30, и такой угол уехал бы в модель,
    // разойдясь с серверной валидацией (0..359).
    expect(normalizeRotation(-30)).toBe(330)
    expect(normalizeRotation(-360)).toBe(0)
    expect(normalizeRotation(-450)).toBe(270)
  })

  it('сворачивает угол больше полного оборота', () => {
    expect(normalizeRotation(360)).toBe(0)
    expect(normalizeRotation(450)).toBe(90)
    expect(normalizeRotation(1080)).toBe(0)
  })

  it('результат всегда лежит в [0, 360)', () => {
    for (const deg of [-1000, -7, 0, 7, 359.9, 1000]) {
      const out = normalizeRotation(deg)
      expect(out).toBeGreaterThanOrEqual(0)
      expect(out).toBeLessThan(360)
    }
  })
})
