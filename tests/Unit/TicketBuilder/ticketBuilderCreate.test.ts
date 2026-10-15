/**
 * Репродукция: конструктор в режиме создания (`templateId = null`,
 * страница `/admin/ticket-templates?template=new`) обязан монтироваться —
 * в браузере он показывал пустую страницу.
 */
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TicketBuilder from '../../../resources/js/components/TicketBuilder.vue'

vi.stubGlobal('fetch', vi.fn(async () => ({
  ok: true,
  status: 200,
  headers: new Headers(),
  json: async () => ({ data: [] }),
})) as unknown as typeof fetch)

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn(() => ({
    matches: true,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })),
})

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('TicketBuilder в режиме создания', () => {
  it('монтируется с templateId=null и показывает кнопку сохранения', () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    expect(wrapper.find('.ticket-builder-container').exists()).toBe(true)
    expect(wrapper.text()).toContain('Сохранить шаблон')
    // Подсказка синтаксиса переменных — литералом, а не вычислением.
    expect(wrapper.text()).toContain('{{event.name}}')
  })

  it('показывает заготовки на вкладке шаблонов', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const tabs = wrapper.findAll('button').filter((b) => b.text().includes('Шаблоны'))
    expect(tabs.length).toBeGreaterThan(0)
    await tabs[0]!.trigger('click')
    expect(wrapper.text()).toContain('Заготовки')
    expect(wrapper.text()).toContain('Классический концерт')
  })

  it('предпросмотр прячет панели и умеет выключать тестовые данные', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    expect(wrapper.find('.sidebar').exists()).toBe(true)

    const previewBtn = wrapper.findAll('button').find((b) => b.text().includes('Предпросмотр'))
    expect(previewBtn).toBeTruthy()
    await previewBtn!.trigger('click')

    expect(wrapper.find('.sidebar').exists()).toBe(false)
    expect(wrapper.find('.preview-bar').exists()).toBe(true)
    expect(wrapper.text()).toContain('Тестовые данные')
  })

  it('текстовый элемент предлагает выбор шрифта', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    // Добавляем заголовок: addElement сразу выбирает его.
    const addBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Заголовок'))
    expect(addBtn).toBeTruthy()
    await addBtn!.trigger('click')

    const fontSelect = wrapper.findAll('select').find((s) =>
      s.findAll('option').some((o) => o.text().includes('Georgia')),
    )
    expect(fontSelect).toBeTruthy()
    // Пустое значение + пять стеков.
    expect(fontSelect!.findAll('option')).toHaveLength(6)
  })

  it('фигура правится шириной и высотой', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const addBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Прямоугольник'))
    expect(addBtn).toBeTruthy()
    await addBtn!.trigger('click')

    expect(wrapper.text()).toContain('Размер')
  })

  it('пикер медиатеки открывается для изображения', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const addBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Изображение'))
    expect(addBtn).toBeTruthy()
    await addBtn!.trigger('click')

    const pickBtn = wrapper.findAll('button').find((b) => b.text().includes('Выбрать из медиатеки'))
    expect(pickBtn).toBeTruthy()
    await pickBtn!.trigger('click')

    expect(wrapper.text()).toContain('Изображение из медиатеки')
  })

  it('без выбранного элемента видны настройки холста с фоном', () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    expect(wrapper.text()).toContain('Фон холста')
  })

  it('у выбранного бокса четыре ручки ресайза, у QR — одна', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const rectBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Прямоугольник'))
    await rectBtn!.trigger('click')
    expect(wrapper.findAll('.resize-handle')).toHaveLength(4)

    const qrBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('QR Код'))
    await qrBtn!.trigger('click')
    // Выбран теперь QR: ручка одна, юго-восточная.
    const handles = wrapper.findAll('.resize-handle')
    expect(handles).toHaveLength(1)
    expect(handles[0]!.classes()).toContain('resize-se')
  })

  it('тянучка за угол меняет размер изображения', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const imgBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Изображение'))
    await imgBtn!.trigger('click')

    // Габариты изображения живут на внутреннем .element-image, не на контейнере.
    const before = wrapper.find('.element-image').attributes('style') ?? ''
    expect(before).toContain('width')

    const handle = wrapper.find('.resize-handle.resize-se')
    expect(handle.exists()).toBe(true)
    await handle.trigger('mousedown', { clientX: 100, clientY: 100 })

    const canvas = wrapper.find('#ticket-canvas')
    await canvas.trigger('mousemove', { clientX: 150, clientY: 130 })
    await canvas.trigger('mouseup')

    const after = wrapper.find('.element-image').attributes('style') ?? ''
    expect(after).not.toBe(before)
  })

  it('вкладка слоёв показывает порядок сверху вниз и двигает слой', async () => {
    const wrapper = mount(TicketBuilder, {
      props: { templateId: null },
    })
    const rectBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('Прямоугольник'))
    await rectBtn!.trigger('click')
    const qrBtn = wrapper.findAll('.element-item').find((b) => b.text().includes('QR Код'))
    await qrBtn!.trigger('click')

    const layersTab = wrapper
      .findAll('.tab-btn')
      .find((b) => b.text().includes('Слои'))
    expect(layersTab).toBeTruthy()
    await layersTab!.trigger('click')

    const rows = wrapper.findAll('.layer-row')
    expect(rows).toHaveLength(2)
    // Верх списка — последний добавленный (QR).
    expect(rows[0]!.text()).toContain('QR-код')
    expect(rows[1]!.text()).toContain('Прямоугольник')

    // Опускаем QR ниже прямоугольника.
    const downBtn = rows[0]!.findAll('.layer-btn')[1]!
    await downBtn.trigger('click')

    const after = wrapper.findAll('.layer-row')
    expect(after[0]!.text()).toContain('Прямоугольник')
    expect(after[1]!.text()).toContain('QR-код')
  })
})
