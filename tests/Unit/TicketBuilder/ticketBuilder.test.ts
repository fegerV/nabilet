/**
 * Юнит-тесты чистой логики конструктора билета (@/lib/ticketBuilder).
 *
 * Раньше вся эта логика (payload шаблона, нормализация элементов, подстановка
 * переменных) была заперта внутри TicketBuilder.vue на 1300+ строк и не
 * тестировалась. Проверяем контракты, включая исправление двойного
 * кодирования template_json.
 */
import { describe, expect, it } from 'vitest'
import {
  CANVAS_PRESETS,
  DEFAULT_TICKET_VARIABLES,
  FONT_STACKS,
  applyCornerResize,
  buildTemplatePayload,
  cloneElement,
  duplicateAsElement,
  elementLayerLabel,
  elementTypeLabel,
  ensureElementDefaults,
  flattenVariables,
  moveElementInList,
  normalizeNewElement,
  renderTextContent,
  resizeHandlesFor,
  syncDerivedCoordinates,
} from '../../../resources/js/lib/ticketBuilder'

describe('buildTemplatePayload', () => {
  it('sends template_json as an object, not a double-encoded string', () => {
    const payload = buildTemplatePayload({
      name: 'Тест',
      canvasWidth: 600,
      canvasHeight: 400,
      config: { backgroundColor: '#fff', elements: [{ id: 1, type: 'text', x: 0, y: 0 }] },
    })
    expect(typeof payload.template_json).toBe('object')
    expect((payload.template_json as any).elements).toHaveLength(1)
    expect(payload.width).toBe(600)
    expect(payload.height).toBe(400)
    expect(payload.name).toBe('Тест')
  })

  it('does NOT send organization_id — the tenant comes from the token', () => {
    // Клиент не должен называть арендатора: сервер берёт организацию из токена
    // (TicketTemplateController::organizationId()). Поле в payload означало бы
    // возможность записать шаблон в чужую организацию.
    const payload = buildTemplatePayload({
      name: 'Тест',
      canvasWidth: 600,
      canvasHeight: 400,
      config: { backgroundColor: '#fff', elements: [] },
    })
    expect('organization_id' in payload).toBe(false)
  })
})

describe('normalizeNewElement', () => {
  it('derives circle center from position', () => {
    const el = normalizeNewElement({ type: 'circle', defaultData: { r: 50 } })
    expect(el.cx).toBe(100)
    expect(el.cy).toBe(100)
  })
  it('gives text default size and qr square size', () => {
    expect(normalizeNewElement({ type: 'text', defaultData: {} }).width).toBe(200)
    const qr = normalizeNewElement({ type: 'qr', defaultData: { size: 120 } })
    expect(qr.width).toBe(120)
    expect(qr.height).toBe(120)
  })
})

describe('syncDerivedCoordinates', () => {
  it('keeps rectangle x2/y2 in sync after move', () => {
    const el = { id: 1, type: 'rectangle', x: 10, y: 20, width: 100, height: 50 }
    syncDerivedCoordinates(el as any)
    expect(el.x2).toBe(110)
    expect((el as any).y2).toBe(70)
  })
})

describe('duplicateAsElement', () => {
  it('returns null without selection and deep-clones with offset', () => {
    expect(duplicateAsElement(null)).toBeNull()
    const src = { id: 1, type: 'text', x: 10, y: 10, meta: { a: 1 } } as any
    const copy = duplicateAsElement(src)!
    expect(copy.x).toBe(30)
    copy.meta.a = 2
    expect(src.meta.a).toBe(1)
  })
})

describe('renderTextContent', () => {
  it('substitutes known variables and leaves unknown ones intact', () => {
    const v = DEFAULT_TICKET_VARIABLES
    expect(renderTextContent('{{event.name}} — {{seat.info}}', v)).toBe('Название события — Ряд 5, Место 12')
    expect(renderTextContent('{{unknown.var}}', v)).toBe('{{unknown.var}}')
    expect(renderTextContent(undefined, v)).toBe('')
  })
  it('flattenVariables exposes all nine keys', () => {
    expect(Object.keys(flattenVariables(DEFAULT_TICKET_VARIABLES))).toHaveLength(9)
  })
})

describe('CANVAS_PRESETS', () => {
  it('has the presets used by the toolbar', () => {
    expect(CANVAS_PRESETS.mobile).toEqual({ width: 400, height: 600 })
    expect(CANVAS_PRESETS.story).toEqual({ width: 450, height: 800 })
    expect(Object.keys(CANVAS_PRESETS)).toEqual(['mobile', 'story', 'desktop', 'square', 'wide'])
  })
  // QR-рендер вынесен в @/lib/qr и покрыт tests/Unit/TicketBuilder/qr.test.ts:
  // прежняя getQrCodeUrl() отдавала ссылку на внешний api.qrserver.com вместе
  // с подписанным токеном входа, поэтому её удалили целиком.
  it('cloneElement is deep', () => {
    const src = { a: { b: [1] } }
    const c = cloneElement(src)
    c.a.b.push(2)
    expect(src.a.b).toEqual([1])
  })
  it('клонирует Vue-прокси (structuredClone их запрещает)', () => {
    const src = { a: { b: [1] } }
    const reactive = new Proxy(src, {})
    const c = cloneElement(reactive)
    expect(c).toEqual({ a: { b: [1] } })
    c.a.b.push(2)
    expect(src.a.b).toEqual([1])
  })
})

describe('FONT_STACKS', () => {
  it('отдаёт пять готовых CSS-стеков для select шрифта', () => {
    expect(FONT_STACKS).toHaveLength(5)
    for (const stack of FONT_STACKS) {
      expect(stack.key).toBeTruthy()
      expect(stack.label).toBeTruthy()
      expect(stack.css).toContain(',')
    }
  })
})

describe('ensureElementDefaults', () => {
  it('заполняет общие поля и текстовые дефолты', () => {
    const el = ensureElementDefaults({ id: 1, type: 'text', x: 0, y: 0 } as any)
    expect(el.rotation).toBe(0)
    expect(el.opacity).toBe(1)
    expect(el.fontSize).toBe(16)
    expect(el.color).toBe('#000000')
    expect(el.fontWeight).toBe('normal')
    expect(el.fontFamily).toBe('')
    expect(el.content).toBe('')
  })
  it('не трогает явно заданные значения', () => {
    const el = ensureElementDefaults({
      id: 1, type: 'text', x: 0, y: 0, fontSize: 42, opacity: 0.5, rotation: 90,
    } as any)
    expect(el.fontSize).toBe(42)
    expect(el.opacity).toBe(0.5)
    expect(el.rotation).toBe(90)
  })
  it('достраивает геометрию фигур, линий и QR', () => {
    const line = ensureElementDefaults({ id: 2, type: 'line', x: 10, y: 20 } as any)
    expect(line.x2).toBe(210)
    expect(line.y2).toBe(20)
    expect(line.strokeWidth).toBe(2)

    const qr = ensureElementDefaults({ id: 3, type: 'qr', x: 0, y: 0 } as any)
    expect(qr.size).toBe(120)
    expect(qr.width).toBe(120)
    expect(qr.height).toBe(120)

    const rect = ensureElementDefaults({ id: 4, type: 'rectangle', x: 0, y: 0 } as any)
    expect(rect.width).toBe(100)
    expect(rect.height).toBe(100)
  })
})

describe('resizeHandlesFor', () => {
  it('боксы тянутся за четыре угла, остальные — за правый нижний', () => {
    expect(resizeHandlesFor('rectangle')).toEqual(['nw', 'ne', 'sw', 'se'])
    expect(resizeHandlesFor('image')).toEqual(['nw', 'ne', 'sw', 'se'])
    expect(resizeHandlesFor('barcode')).toEqual(['nw', 'ne', 'sw', 'se'])
    expect(resizeHandlesFor('circle')).toEqual(['se'])
    expect(resizeHandlesFor('qr')).toEqual(['se'])
    expect(resizeHandlesFor('text')).toEqual(['se'])
    expect(resizeHandlesFor('line')).toEqual(['se'])
  })
})

describe('applyCornerResize', () => {
  it('тянет бокс за юго-восток наружу', () => {
    const el = applyCornerResize(
      { id: 1, type: 'rectangle', x: 10, y: 20, width: 100, height: 50 } as any,
      'se', 30, 10,
    )
    expect(el.width).toBe(130)
    expect(el.height).toBe(60)
    expect(el.x).toBe(10)
    expect(el.y).toBe(20)
  })
  it('тянет бокс за северо-запад: двигаются x/y, размер не уходит ниже минимума', () => {
    const el = applyCornerResize(
      { id: 1, type: 'rectangle', x: 10, y: 20, width: 100, height: 50 } as any,
      'nw', -5, -5,
    )
    expect(el.x).toBe(5)
    expect(el.y).toBe(15)
    expect(el.width).toBe(105)
    expect(el.height).toBe(55)

    const tiny = applyCornerResize(
      { id: 2, type: 'image', x: 0, y: 0, width: 12, height: 12 } as any,
      'nw', 100, 100,
    )
    expect(tiny.width).toBe(10)
    expect(tiny.height).toBe(10)
    expect(tiny.x).toBe(2)
    expect(tiny.y).toBe(2)
  })
  it('масштабирует квадрат QR и кегль текста', () => {
    const qr = applyCornerResize({ id: 3, type: 'qr', x: 0, y: 0, size: 100 } as any, 'se', 20, 20)
    expect(qr.size).toBe(120)
    expect(qr.width).toBe(120)
    expect(qr.height).toBe(120)

    const text = applyCornerResize(
      { id: 4, type: 'text', x: 0, y: 0, fontSize: 16 } as any, 'se', 40, 0,
    )
    expect(text.fontSize).toBe(20)
  })
  it('двигает конечную точку линии', () => {
    const line = applyCornerResize(
      { id: 5, type: 'line', x: 10, y: 10, x2: 110, y2: 10 } as any, 'se', 20, 5,
    )
    expect(line.x2).toBe(130)
    expect(line.y2).toBe(15)
    expect(line.x).toBe(10)
  })
})

describe('пропорциональный ресайз картинки', () => {
  const img = () => ({ id: 9, type: 'image', x: 0, y: 0, width: 100, height: 50 }) as any

  it('se: высота следует за шириной (аспект 2:1)', () => {
    const el = applyCornerResize(img(), 'se', 50, 0)
    expect(el.width).toBe(150)
    expect(el.height).toBe(75)
  })
  it('nw: противоположный угол (110,70) стоит на месте', () => {
    const el = applyCornerResize({ ...img(), x: 10, y: 20 }, 'nw', -50, -25)
    expect(el.width).toBe(150)
    expect(el.height).toBe(75)
    expect(el.x).toBe(-40)
    expect(el.y).toBe(-5)
    expect(el.x + el.width).toBe(110)
    expect(el.y + el.height).toBe(70)
  })
  it('доминирующая ось задаёт масштаб', () => {
    const el = applyCornerResize(img(), 'se', 0, 10)
    // dy/h = 1.2 против dx/w = 1 → коробка выросла на 20%.
    expect(el.width).toBe(120)
    expect(el.height).toBe(60)
  })
  it('внутрь — только до минимума, без выворота', () => {
    const el = applyCornerResize(img(), 'se', -500, -500)
    // minScale = max(10/100, 10/50) = 0.2 → 20x10.
    expect(el.width).toBe(20)
    expect(el.height).toBe(10)
  })
  it('прямоугольник остаётся свободным (не пропорциональным)', () => {
    const el = applyCornerResize(
      { id: 10, type: 'rectangle', x: 0, y: 0, width: 100, height: 50 } as any, 'se', 30, 0,
    )
    expect(el.width).toBe(130)
    expect(el.height).toBe(50)
  })
})

describe('moveElementInList', () => {
  function list() {
    return [
      { id: 1, type: 'text', x: 0, y: 0 },
      { id: 2, type: 'qr', x: 0, y: 0 },
      { id: 3, type: 'image', x: 0, y: 0 },
    ] as any[]
  }
  it('двигает выше/ниже обменом с соседом', () => {
    const items = list()
    expect(moveElementInList(items, 2, 'up')).toBe(true)
    expect(items.map((el) => el.id)).toEqual([1, 3, 2])
    expect(moveElementInList(items, 2, 'down')).toBe(true)
    expect(items.map((el) => el.id)).toEqual([1, 2, 3])
  })
  it('кидает наверх/вниз и сообщает о границе', () => {
    const items = list()
    expect(moveElementInList(items, 1, 'top')).toBe(true)
    expect(items.map((el) => el.id)).toEqual([2, 3, 1])
    expect(moveElementInList(items, 1, 'top')).toBe(false)
    expect(moveElementInList(items, 1, 'bottom')).toBe(true)
    expect(items.map((el) => el.id)).toEqual([1, 2, 3])
    expect(moveElementInList(items, 1, 'down')).toBe(false)
  })
  it('не трогает чужой id', () => {
    const items = list()
    expect(moveElementInList(items, 999, 'up')).toBe(false)
    expect(items.map((el) => el.id)).toEqual([1, 2, 3])
  })
})

describe('подписи слоёв', () => {
  it('типы называются по-русски', () => {
    expect(elementTypeLabel('qr')).toBe('QR-код')
    expect(elementTypeLabel('image')).toBe('Изображение')
    expect(elementTypeLabel('nope')).toBe('nope')
  })
  it('текст подписывается содержимым, остальное — типом', () => {
    expect(elementLayerLabel({ id: 1, type: 'text', x: 0, y: 0, content: '{{event.name}}!' } as any))
      .toBe('{{event.name}}!')
    expect(elementLayerLabel({ id: 2, type: 'text', x: 0, y: 0, content: '   ' } as any)).toBe('Текст')
    expect(elementLayerLabel({ id: 3, type: 'qr', x: 0, y: 0 } as any)).toBe('QR-код')
  })
})
