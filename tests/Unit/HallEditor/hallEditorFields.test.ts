/**
 * Юнит-тесты чистых правил полей редактора залов (hallEditorFields).
 *
 * До выноса (P2) эти правила были размазаны по сеттерам инспектора внутри
 * HallEditorPage.vue и не проверялись ничем: чтобы дотянуться до них, тесту
 * пришлось бы монтировать страницу с Konva-холстом и jsdom-заглушками.
 *
 * Здесь фиксируем ровно те инварианты, из-за которых поля вообще существуют:
 *  - цена переводится в копейки и никогда не становится нулевой (сервер
 *    отвергает `price < 1` — молча записанный ноль дал бы «на экране 0 ₽,
 *    автосохранение падает»);
 *  - пустой ввод цены ряда — это СНЯТИЕ переопределения, а не ошибка;
 *  - границы полей фона/статики совпадают с тем, что ждёт сервер;
 *  - отрицательный угол поворота приводится к [0, 360), а не остаётся
 *    отрицательным, как дал бы `% 360`.
 */
import { describe, expect, it } from 'vitest'
import {
  clampFloatOrNull,
  clampIntOrNull,
  normalizeRotation,
  requiredNonNegativeError,
  roundOrNull,
  rowPriceError,
  rubToMinor,
} from '../../../resources/js/pages/hall-editor/hallEditorFields'

describe('rubToMinor', () => {
  it('переводит рубли в копейки', () => {
    expect(rubToMinor(850)).toBe(85_000)
    expect(rubToMinor('1250.50')).toBe(125_050)
  })

  it('округляет дробные копейки, а не обрезает их', () => {
    expect(rubToMinor(0.015)).toBe(2)
    expect(rubToMinor(1.004)).toBe(100)
  })

  it('никогда не отдаёт ноль или отрицательное значение', () => {
    // 0.001 ₽ = 0.1 копейки → округление дало бы 0, но нижняя граница 1 копейка.
    expect(rubToMinor(0.001)).toBe(1)
    expect(rubToMinor(0)).toBeNull()
    expect(rubToMinor(-5)).toBeNull()
  })

  it('отклоняет нечисловой ввод', () => {
    expect(rubToMinor('')).toBeNull()
    expect(rubToMinor('abc')).toBeNull()
    expect(rubToMinor(null)).toBeNull()
    expect(rubToMinor(undefined)).toBeNull()
    expect(rubToMinor(Number.NaN)).toBeNull()
    expect(rubToMinor(Number.POSITIVE_INFINITY)).toBeNull()
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
