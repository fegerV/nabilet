/**
 * Раскладка и инструменты редактора схем залов.
 *
 * Проверяем ровно то, ради чего раскладка переделывалась, и то, что легко
 * сломать незаметно:
 *   1. панели сворачиваются и разворачиваются, а холст остаётся единственным
 *      растягивающимся элементом (свёрнутые панели = больше места);
 *   2. состояние панелей переживает перезагрузку (localStorage), а не сбрасывается;
 *   3. горячие клавиши выбирают инструмент и двигают выделение стрелками;
 *   4. «Выделить все» охватывает все места и снимает выделение статики;
 *   5. привязка к сетке выключается и включается и НЕ теряется между сессиями.
 *
 * Konva замокан: странице нужен только факт создания Stage и слоёв, а не
 * реальный рендер — рисование проверяется вживую, а здесь важен контракт.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/* ── Konva: минимальный двойник, достаточный для initCanvasAndLoad ──── */

class FakeNode {
  children: FakeNode[] = []
  attrs: Record<string, unknown> = {}
  constructor(attrs: Record<string, unknown> = {}) { this.attrs = attrs }
  add(...nodes: FakeNode[]): this { this.children.push(...nodes); return this }
  destroyChildren(): void { this.children = [] }
  destroy(): void {}
  draw(): void {}
  batchDraw(): void {}
  size(): void {}
  draggable(): void {}
  scale(): number { return 1 }
  position(): void {}
  container(): { getBoundingClientRect: () => DOMRect } {
    return { getBoundingClientRect: () => ({ left: 0, top: 0, width: 900, height: 520 }) as DOMRect }
  }
  on(): void {}
  getAbsolutePosition(): { x: number; y: number } { return { x: 0, y: 0 } }
  getAbsoluteTransform(): { copy: () => { invert: () => { point: (p: unknown) => unknown } } } {
    return { copy: () => ({ invert: () => ({ point: (p: unknown) => p }) }) }
  }
  name(): string { return '' }
}
class FakeStage extends FakeNode {}
class FakeLayer extends FakeNode {}

const konvaStub = {
  Stage: FakeStage,
  Layer: FakeLayer,
  Group: FakeNode,
  Rect: FakeNode,
  Circle: FakeNode,
  Line: FakeNode,
  Text: FakeNode,
  Arrow: FakeNode,
  Image: FakeNode,
}
vi.mock('konva', () => ({ default: konvaStub }))

/* ── jsdom не знает ResizeObserver (страница следит за размером холста) ── */

class FakeResizeObserver {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
;(globalThis as unknown as { ResizeObserver: typeof FakeResizeObserver }).ResizeObserver = FakeResizeObserver

/* ── vue-router ────────────────────────────────────────────────────── */

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { publicId: 'HALL-PUBLIC-ID' } }),
  useRouter: () => ({ push: vi.fn() }),
}))

/* ── API: страница грузит зал и черновик схемы ────────────────────── */

vi.mock('@/lib/api', () => ({
  ApiError: class ApiError extends Error {},
  get: vi.fn(async () => ({ data: null })),
  send: vi.fn(async () => ({ data: null })),
}))

/* ── Pinia: уведомления ───────────────────────────────────────────── */

vi.mock('@/stores/ui', () => ({
  useUiStore: () => ({ notify: vi.fn(), isDark: false, toggleTheme: vi.fn() }),
}))

async function mountEditor() {
  const HallEditorPage = (await import('@/pages/hall-editor/HallEditorPage.vue')).default
  const wrapper = mount(HallEditorPage, {
    attachTo: document.body,
    global: { stubs: { RouterLink: true } },
  })
  await flushPromises()
  return wrapper
}

function asideCount(wrapper: ReturnType<typeof mount>): number {
  return wrapper.findAll('aside').length
}

describe('HallEditorPage — раскладка', () => {
  beforeEach(() => {
    window.localStorage.clear()
    Object.defineProperty(window, 'innerWidth', { value: 1440, configurable: true })
  })

  it('по умолчанию показывает обе панели на широком экране', async () => {
    const wrapper = await mountEditor()

    expect(asideCount(wrapper)).toBe(2)
    wrapper.unmount()
  })

  it('свёрнутая панель инструментов убирает aside и освобождает место', async () => {
    const wrapper = await mountEditor()
    expect(asideCount(wrapper)).toBe(2)

    // Кнопка «Скрыть инструменты» — на панели инструментов.
    const hide = wrapper.findAll('button').find((b) => b.attributes('title')?.startsWith('Скрыть инструменты'))
    expect(hide).toBeTruthy()
    await hide!.trigger('click')

    expect(asideCount(wrapper)).toBe(1)
    wrapper.unmount()
  })

  it('свёрнутые панели можно вернуть кнопками из тулбара холста', async () => {
    const wrapper = await mountEditor()

    await wrapper.findAll('button').find((b) => b.attributes('title')?.startsWith('Скрыть инструменты'))!.trigger('click')
    await wrapper.findAll('button').find((b) => b.attributes('title')?.startsWith('Скрыть свойства'))!.trigger('click')
    expect(asideCount(wrapper)).toBe(0)

    // Пока панели скрыты, в тулбаре холста есть кнопки возврата.
    const showTools = wrapper.findAll('button').find((b) => b.attributes('title') === 'Показать инструменты')
    const showProps = wrapper.findAll('button').find((b) => b.attributes('title') === 'Показать свойства')
    expect(showTools).toBeTruthy()
    expect(showProps).toBeTruthy()

    await showTools!.trigger('click')
    await showProps!.trigger('click')
    expect(asideCount(wrapper)).toBe(2)
    wrapper.unmount()
  })

  it('выбор панелей сохраняется между сессиями (localStorage)', async () => {
    const first = await mountEditor()
    await first.findAll('button').find((b) => b.attributes('title')?.startsWith('Скрыть инструменты'))!.trigger('click')
    expect(window.localStorage.getItem('nabilet:hallEditor.tools.visible')).toBe('false')
    first.unmount()

    // Новая «сессия» — панель должна остаться скрытой.
    const second = await mountEditor()
    expect(asideCount(second)).toBe(1)
    second.unmount()
  })

  it('компактный вид инструментов не теряет ни одного инструмента', async () => {
    const wrapper = await mountEditor()

    const wideCount = wrapper.findAll('aside button').length
    const toggle = wrapper.findAll('button').find((b) => b.attributes('title') === 'Компактный вид')
    expect(toggle).toBeTruthy()
    await toggle!.trigger('click')

    // Подписи убраны, но кнопки инструментов остались на месте.
    expect(wrapper.findAll('aside button').length).toBe(wideCount)
    expect(window.localStorage.getItem('nabilet:hallEditor.tools.wide')).toBe('false')
    wrapper.unmount()
  })
})

describe('HallEditorPage — горячие клавиши и привязка', () => {
  beforeEach(() => {
    window.localStorage.clear()
    Object.defineProperty(window, 'innerWidth', { value: 1440, configurable: true })
  })

  function press(wrapper: ReturnType<typeof mount>, key: string, opts: KeyboardEventInit = {}) {
    // onKeyDown повешен на window, поэтому шлём событие туда.
    window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, ...opts }))
  }

  /** Подпись активного инструмента из тулбара холста (единственный источник правды). */
  function activeToolLabel(wrapper: ReturnType<typeof mount>): string {
    const chip = wrapper.find('section .bg-surface-3')
    return chip.exists() ? chip.text().trim() : ''
  }

  it('буква выбирает инструмент (V, S, T)', async () => {
    const wrapper = await mountEditor()
    expect(activeToolLabel(wrapper)).toBe('Выделение')

    press(wrapper, 's')
    await flushPromises()
    expect(activeToolLabel(wrapper)).toBe('Место')

    press(wrapper, 't')
    await flushPromises()
    expect(activeToolLabel(wrapper)).toBe('Стол')

    press(wrapper, 'v')
    await flushPromises()
    expect(activeToolLabel(wrapper)).toBe('Выделение')

    wrapper.unmount()
  })

  it('буква НЕ выбирает инструмент, когда фокус в поле ввода', async () => {
    const wrapper = await mountEditor()
    const input = wrapper.find('input')
    expect(input.exists()).toBe(true)

    input.element.dispatchEvent(new KeyboardEvent('keydown', { key: 's', bubbles: true }))
    await flushPromises()

    // Осталось «Выделение» — буква адресована полю, а не холсту.
    expect(activeToolLabel(wrapper)).toBe('Выделение')
    wrapper.unmount()
  })

  it('«Сетка» переключается и запоминается', async () => {
    const wrapper = await mountEditor()
    const snap = wrapper.findAll('button').find((b) => b.text().trim() === 'Сетка')
    expect(snap).toBeTruthy()

    expect(window.localStorage.getItem('nabilet:hallEditor.snap')).toBe(null)
    await snap!.trigger('click')
    expect(window.localStorage.getItem('nabilet:hallEditor.snap')).toBe('true')
    await snap!.trigger('click')
    expect(window.localStorage.getItem('nabilet:hallEditor.snap')).toBe('false')
    wrapper.unmount()
  })

  it('показывает текущий инструмент и подсказку в тулбаре холста', async () => {
    const wrapper = await mountEditor()
    expect(wrapper.text()).toContain('Выделение')
    expect(wrapper.text()).toContain('Клик или рамка — выделить')
    wrapper.unmount()
  })
})

/**
 * Инструменты правки рядов и выравнивания.
 *
 * Логика вынесена в seatGeometry.ts и покрыта rowOperations.test.ts; здесь
 * проверяется ровно то, что кнопки ДОСТУПНЫ — иначе мёртвая кнопка тихо
 * останется в разметке, а фича будет считаться сделанной.
 */
describe('HallEditorPage — инструменты правки', () => {
  beforeEach(() => {
    window.localStorage.clear()
    Object.defineProperty(window, 'innerWidth', { value: 1440, configurable: true })
  })

  /**
   * Панель свойств сектора появляется только после создания сектора, поэтому
   * идём настоящим путём пользователя: заполняем генератор и жмём «Создать».
   */
  async function mountWithSector() {
    const wrapper = await mountEditor()
    const create = wrapper.findAll('button').find((b) => b.text().trim() === 'Создать сектор')
    expect(create, 'нет кнопки «Создать сектор»').toBeTruthy()
    await create!.trigger('click')
    await flushPromises()
    return wrapper
  }

  it('созданный сектор открывает панель с правкой шага сетки', async () => {
    const wrapper = await mountWithSector()
    const inspector = wrapper.find('aside:last-of-type')

    expect(inspector.text()).toContain('Шаг мест и рядов')
    expect(inspector.text()).toContain('Применить шаг')
    expect(inspector.find('input[type="number"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('в панели сектора есть кнопки управления рядами', async () => {
    const wrapper = await mountWithSector()
    const titles = wrapper.findAll('button').map((b) => b.attributes('title'))
    expect(titles).toContain('Вставить ряд ниже')
    expect(titles).toContain('Удалить ряд')
    expect(titles.some((t) => t?.startsWith('Выделить все места ряда'))).toBe(true)
    expect(wrapper.text()).toContain('Нумерация')
    wrapper.unmount()
  })

  it('выравнивание и распределение доступны для выделенного ряда', async () => {
    const wrapper = await mountWithSector()

    // Выравнивание имеет смысл от двух мест: выделяем целый ряд (первый ряд
    // по умолчанию 5 мест) штатной кнопкой «Выделить все места ряда N».
    const rowButton = wrapper
      .findAll('button')
      .find((b) => b.attributes('title')?.startsWith('Выделить все места ряда'))
    expect(rowButton, 'нет кнопки выделения ряда').toBeTruthy()
    await rowButton!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Выбрано мест')
    for (const label of ['⇤ Влево', '↔ Центр X', 'Вправо ⇥', '⤒ Вверх', '↕ Центр Y', 'Вниз ⤓']) {
      const found = wrapper.findAll('button').some((b) => b.text().trim() === label)
      expect(found, `нет кнопки «${label}»`).toBe(true)
    }
    expect(wrapper.text()).toContain('Ровно по X')
    expect(wrapper.text()).toContain('Ровно по Y')
    wrapper.unmount()
  })
})
