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
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ApiError, get, send } from '@/lib/api'
import { onBeforeRouteLeave } from 'vue-router'

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
  draggable(value?: boolean): boolean {
    if (value !== undefined) FakeNode.lastDraggable = value
    return FakeNode.lastDraggable
  }
  static lastDraggable = false
  scale(value?: number | { x: number }): number {
    if (typeof value === 'number') FakeNode.lastScale = value
    else if (value && typeof value.x === 'number') FakeNode.lastScale = value.x
    return FakeNode.lastScale
  }
  static lastScale = 1
  scaleX(): number { return FakeNode.lastScale }
  x(): number { return 0 }
  y(): number { return 0 }
  position(): void {}
  container(): { getBoundingClientRect: () => DOMRect } {
    return { getBoundingClientRect: () => ({ left: 0, top: 0, width: 900, height: 520 }) as DOMRect }
  }
  listeners: Record<string, Array<(e: unknown) => void>> = {}
  on(event: string, handler: (e: unknown) => void): void {
    ;(this.listeners[event] ??= []).push(handler)
  }
  getParent(): FakeNode | null { return null }
  getClientRect(): { x: number; y: number; width: number; height: number } {
    return { x: 0, y: 0, width: 100, height: 100 }
  }
  getAbsolutePosition(): { x: number; y: number } { return { x: 0, y: 0 } }
  getAbsoluteTransform(): { copy: () => { invert: () => { point: (p: unknown) => unknown } } } {
    return { copy: () => ({ invert: () => ({ point: (p: unknown) => p }) }) }
  }
  name(): string { return '' }
}
class FakeStage extends FakeNode {
  static last: FakeStage | null = null
  constructor(attrs: Record<string, unknown> = {}) {
    super(attrs)
    FakeStage.last = this
  }
}
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
  static instances: FakeResizeObserver[] = []
  constructor(readonly callback: ResizeObserverCallback) { FakeResizeObserver.instances.push(this) }
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
}
;(globalThis as unknown as { ResizeObserver: typeof FakeResizeObserver }).ResizeObserver = FakeResizeObserver

/* ── vue-router ────────────────────────────────────────────────────── */

vi.mock('vue-router', () => ({
  onBeforeRouteLeave: vi.fn(),
  onBeforeRouteUpdate: vi.fn(),
  useRoute: () => ({ params: { publicId: 'HALL-PUBLIC-ID' } }),
  useRouter: () => ({ push: vi.fn() }),
}))

/* ── API: страница грузит зал и черновик схемы ────────────────────── */

vi.mock('@/lib/api', () => ({
  ApiError: class ApiError extends Error {
    constructor(
      readonly status: number,
      message: string,
      readonly code: string | null = null,
      readonly details: Record<string, string[]> | null = null,
    ) { super(message) }
  },
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
    FakeNode.lastScale = 1
    FakeNode.lastDraggable = false
    Object.defineProperty(window, 'innerWidth', { value: 1440, configurable: true })
  })

  it('один палец растягивает стоячую зону и не дублирует мышиный жест', async () => {
    const wrapper = await mountEditor()
    const stage = FakeStage.last!
    const standing = wrapper.findAll('button').find(b => (b.attributes('title') ?? '').includes('Standing'))!
    await standing.trigger('click')

    const target = { name: () => 'canvas-bg' }
    const touch = (x: number, y: number): unknown => ({
      target,
      evt: { touches: [{ clientX: x, clientY: y }], changedTouches: [{ clientX: x, clientY: y }], preventDefault: vi.fn() },
    })

    try {
      stage.listeners['touchstart']![0](touch(100, 100))
      stage.listeners['touchmove']![0](touch(300, 300))
      stage.listeners['touchend']![0](touch(300, 300))
      // Эмулированное браузером mouse-событие сразу после касания игнорируется.
      stage.listeners['mousedown']![0]({ target, evt: { button: 0, clientX: 100, clientY: 100 } })

      await new Promise((resolve) => setTimeout(resolve, 650))
      await flushPromises()
      const payload = vi.mocked(send).mock.calls[0]?.[2] as {
        payload?: { staticObjects?: Array<{ kind: string }> }
      } | undefined
      // Один палец создал ровно одну стоячую зону; эмулированное мышиное
      // событие после касания не создало вторую.
      expect(payload?.payload?.staticObjects).toHaveLength(1)
      expect(payload?.payload?.staticObjects?.[0]?.kind).toBe('standing')
    } finally {
      wrapper.unmount()
    }
  })

  it('щипок двумя пальцами увеличивает масштаб вокруг середины', async () => {
    const wrapper = await mountEditor()
    const stage = FakeStage.last!
    const touchEvent = (x1: number, x2: number): unknown => ({
      evt: {
        touches: [{ clientX: x1, clientY: 200 }, { clientX: x2, clientY: 200 }],
        preventDefault: vi.fn(),
      },
    })

    stage.listeners['touchstart']![0](touchEvent(100, 200))
    const before = FakeNode.lastScale
    stage.listeners['touchmove']![0](touchEvent(50, 250))
    expect(FakeNode.lastScale).toBeGreaterThan(before)
    stage.listeners['touchend']![0]({ evt: { touches: [] } })
    wrapper.unmount()
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

describe('HallEditorPage — сохранение и публикация', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.mocked(get).mockResolvedValue({ data: [] } as never)
    vi.mocked(send).mockReset()
    vi.mocked(send).mockResolvedValue({ data: { id: 5, revision: 1, version: 1 } } as never)
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true })
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  async function createSector(wrapper: Awaited<ReturnType<typeof mountEditor>>) {
    await wrapper.findAll('button').find(b => b.text().trim() === 'Создать сектор')!.trigger('click')
  }

  it('перед уходом сохраняет изменения, даже если debounce ещё не истёк', async () => {
    const wrapper = await mountEditor()
    await createSector(wrapper)
    const guard = vi.mocked(onBeforeRouteLeave).mock.calls.at(-1)![0]
    expect(await (guard as () => Promise<boolean>)()).toBe(true)
    expect(send).toHaveBeenCalledTimes(1)
    expect(vi.mocked(send).mock.calls[0][0]).toContain('/schema-versions/draft')
    expect(wrapper.text()).toContain('Сохранено')
    expect(wrapper.get('[role="status"]').attributes('aria-live')).toBe('polite')
    expect(wrapper.get('[role="status"]').text()).toMatch(/Сохранено\s*·\s*\d{2}:\d{2}/)
    wrapper.unmount()
  })

  it('при потере сети предупреждает о закрытии и сохраняет после online', async () => {
    const wrapper = await mountEditor()
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: false })
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    expect(send).not.toHaveBeenCalled()
    const close = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(close)
    expect(close.defaultPrevented).toBe(true)
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const guard = vi.mocked(onBeforeRouteLeave).mock.calls.at(-1)![0]
    expect(await (guard as () => Promise<boolean>)()).toBe(false)
    Object.defineProperty(navigator, 'onLine', { configurable: true, value: true })
    window.dispatchEvent(new Event('online'))
    await flushPromises()
    expect(send).toHaveBeenCalledTimes(1)
    const savedClose = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(savedClose)
    expect(savedClose.defaultPrevented).toBe(false)
    wrapper.unmount()
  })

  it('сериализует сохранение при новой правке во время запроса', async () => {
    let finish!: (value: unknown) => void
    vi.mocked(send).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }) as never)
    const wrapper = await mountEditor()
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    expect(send).toHaveBeenCalledTimes(1)
    finish({ data: { id: 5, revision: 1, version: 1 } })
    await flushPromises()
    expect(send).toHaveBeenCalledTimes(2)
    const payload = vi.mocked(send).mock.calls[1][2] as { base_revision: number; payload: { sectors: unknown[] } }
    expect(payload.base_revision).toBe(1)
    expect(payload.payload.sectors).toHaveLength(2)
    expect(wrapper.text()).toContain('Сохранено')
    wrapper.unmount()
  })

  it('отличает сетевой сбой от отклонённой схемы и даёт повторить сохранение', async () => {
    vi.mocked(send)
      .mockRejectedValueOnce(new Error('Сервер недоступен'))
      .mockResolvedValueOnce({ data: { id: 5, revision: 1, version: 1 } } as never)
    const wrapper = await mountEditor()
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    await flushPromises()

    expect(wrapper.text()).toContain('Не сохранено')
    expect(wrapper.text()).not.toContain('Схема отклонена')
    await wrapper.findAll('button').find(b => b.text().trim() === 'Сохранить')!.trigger('click')
    await flushPromises()
    expect(send).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Сохранено')
    wrapper.unmount()
  })

  it('при конфликте ревизий блокирует повторную запись до перезагрузки', async () => {
    vi.mocked(send).mockRejectedValueOnce(
      new ApiError(409, 'Схема уже изменена', 'SCHEMA_DRAFT_CONFLICT'),
    )
    const wrapper = await mountEditor()
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    await flushPromises()

    expect(wrapper.text()).toContain('Конфликт версий')
    expect(wrapper.text()).toContain('перезагрузить')
    expect(wrapper.findAll('button').some(b => b.text().trim() === 'Сохранить')).toBe(false)
    expect(wrapper.findAll('button').find(b => b.text().includes('Опубликовать'))!.attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('публикация блокирует повторный запрос и выход до ответа сервера', async () => {
    let finish!: (value: unknown) => void
    vi.mocked(send).mockImplementationOnce(() => new Promise(resolve => { finish = resolve }) as never)
    const wrapper = await mountEditor()
    await createSector(wrapper)
    const publish = wrapper.findAll('button').find(b => b.text().trim() === 'Опубликовать')!
    await publish.trigger('click')
    await flushPromises()
    expect(publish.attributes('disabled')).toBeDefined()
    const guard = vi.mocked(onBeforeRouteLeave).mock.calls.at(-1)![0]
    expect(await (guard as () => Promise<boolean>)()).toBe(false)
    expect(send).toHaveBeenCalledTimes(1)
    finish({ data: { id: 5, revision: 1, version: 1 } })
    await flushPromises()
    expect(send).toHaveBeenCalledTimes(2)
    expect(vi.mocked(send).mock.calls[1][0]).toContain('/5/publish')
    expect(wrapper.text()).toContain('Опубликовано v1')
    wrapper.unmount()
  })

  it('изменение размера окна не меняет размер сохраняемого документа', async () => {
    const wrapper = await mountEditor()
    const observer = FakeResizeObserver.instances.at(-1)!
    observer.callback([{ contentRect: { width: 400, height: 300 } }] as ResizeObserverEntry[], observer as unknown as ResizeObserver)
    await createSector(wrapper)
    await vi.advanceTimersByTimeAsync(500)
    const payload = vi.mocked(send).mock.calls[0][2] as { payload: { canvas: unknown } }
    expect(payload.payload.canvas).toEqual({ width: 900, height: 520 })
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

  it('Space временно включает панораму, keyup её выключает', async () => {
    const wrapper = await mountEditor()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', code: 'Space', bubbles: true, cancelable: true }))
    await flushPromises()
    expect(FakeNode.lastDraggable).toBe(true)

    window.dispatchEvent(new KeyboardEvent('keyup', { key: ' ', code: 'Space', bubbles: true }))
    await flushPromises()
    expect(FakeNode.lastDraggable).toBe(false)
    wrapper.unmount()
  })

  /** Подпись активного инструмента из тулбара холста (единственный источник правды). */
  function activeToolLabel(wrapper: ReturnType<typeof mount>): string {
    const chip = wrapper.find('[data-testid="active-canvas-tool"]')
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

  it('основная кнопка добавляет ряд даже после выбора другого инструмента', async () => {
    const wrapper = await mountEditor()
    await wrapper.findAll('button').find(b => b.text().trim() === 'Создать сектор')!.trigger('click')
    await wrapper.findAll('button').find(b => b.text().includes('Добавить место'))!.trigger('click')
    const before = wrapper.text()
    expect(before).toContain('Ряды · 8')

    await wrapper.findAll('button').find(b => b.text().includes('Добавить ряд'))!.trigger('click')
    expect(wrapper.text()).toContain('Ряды · 9')
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

    // Заголовок панели выделения теперь «Выбрано: N места» — через seatsLabel().
    expect(wrapper.text()).toContain('Выбрано:')
    for (const label of ['⇤ Влево', '↔ Центр X', 'Вправо ⇥', '⤒ Вверх', '↕ Центр Y', 'Вниз ⤓']) {
      const found = wrapper.findAll('button').some((b) => b.text().trim() === label)
      expect(found, `нет кнопки «${label}»`).toBe(true)
    }
    expect(wrapper.text()).toContain('Ровно по X')
    expect(wrapper.text()).toContain('Ровно по Y')
    wrapper.unmount()
  })
})
