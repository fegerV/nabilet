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
  buildTemplatePayload,
  cloneElement,
  duplicateAsElement,
  flattenVariables,
  normalizeNewElement,
  renderTextContent,
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
  it('has the four presets used by the toolbar', () => {
    expect(CANVAS_PRESETS.mobile).toEqual({ width: 400, height: 600 })
    expect(Object.keys(CANVAS_PRESETS)).toEqual(['mobile', 'desktop', 'square', 'wide'])
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
})
