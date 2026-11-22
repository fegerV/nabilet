/**
 * Конструктор витрины: редактирование (аудит 2026-11-18).
 *
 * Проверяем дефекты, которые не ловятся бэкенд-тестами нормализации:
 *   - дефолты фронта совпадают с дефолтами бэкенда;
 *   - недостающие поля секции дополняются, а не передаются как undefined;
 *   - `once` у hero действительно запрещает второй баннер;
 *   - drag & drop ставит секцию перед целевой независимо от направления;
 *   - предпросмотр показывает шапку и подвал, а не только секции;
 *   - предупреждения нормализации видны сразу после сохранения.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AdminStorefrontPage from '@/pages/admin/AdminStorefrontPage.vue'
import { useStorefrontStore } from '@/stores/storefront'
import { defaultConfig } from '@/lib/storefront'

const getMock = vi.hoisted(() => vi.fn())
const sendMock = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({ get: getMock, send: sendMock }))

// Виджеты в предпросмотре используют useRoute/useRouter — в unit-маунте их нет.
vi.mock('vue-router', () => ({
  onBeforeRouteLeave: vi.fn(),
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))

const config = () => ({
  version: 1,
  theme: { mode: 'auto' },
  branding: { name: 'Моя витрина', tagline: 'Билеты' },
  header: { showSearch: true, showCart: false, showThemeToggle: false, nav: [{ label: 'Афиша', to: '/' }] },
  footer: { text: 'Копирайт X', links: [{ label: 'Помощь', url: '/' }], social: [] },
  sections: [
    { id: 'a', type: 'posters', visible: true, title: 'Афиша', subtitle: '', settings: {} },
    { id: 'b', type: 'categories', visible: true, title: 'Куда пойти', subtitle: '', settings: {} },
    { id: 'c', type: 'promo', visible: true, title: 'Промо', subtitle: '', settings: {} },
  ],
})

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  sendMock.mockReset()
  getMock.mockImplementation(async (url: string) => {
    if (String(url) === '/storefront') {
      return { data: config(), meta: { organization_id: '1' } }
    }
    if (String(url).startsWith('/admin/storefront')) {
      return { data: config(), meta: { is_default: false, organization_id: '1' } }
    }
    return { data: [] }
  })
  sendMock.mockImplementation(async () => ({ data: config(), meta: { warnings: [] } }))
})

async function mountPage() {
  const wrapper = mount(AdminStorefrontPage)
  await flushPromises()
  return wrapper
}

function paletteButton(wrapper: ReturnType<typeof mount>, label: string) {
  const button = wrapper.findAll('button').find((b) => b.text().includes(label))
  if (!button) throw new Error(`Кнопка «${label}» не найдена`)
  return button
}

function dragSections(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('li[draggable="true"]')
}

describe('AdminStorefrontPage / defaults', () => {
  it('reads organization metadata from the API envelope', async () => {
    await mountPage()
    expect(useStorefrontStore().editingOrganizationId).toBe('1')
  })

  it('blocks editing after a failed load and offers retry', async () => {
    getMock.mockRejectedValue(new Error('Настройки недоступны'))
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Повторить загрузку')
    expect(wrapper.find('fieldset').exists()).toBe(false)
    expect(paletteButton(wrapper, 'Сохранить').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('edits footer columns and includes them in the save request', async () => {
    const wrapper = await mountPage()
    await paletteButton(wrapper, 'Меню и подвал').trigger('click')
    const before = wrapper.findAll('input').length
    await paletteButton(wrapper, 'Добавить колонку').trigger('click')
    expect(wrapper.findAll('input').length).toBeGreaterThan(before)
    await paletteButton(wrapper, 'Сохранить').trigger('click')
    await flushPromises()
    const body = sendMock.mock.calls[0][2]
    expect(body.config.footer.columns.at(-1)).toEqual({ title: 'Раздел', links: [{ label: 'Ссылка', url: '/' }] })
    wrapper.unmount()
  })

  it('дефолт фронта повторяет дефолт бэкенда: витрина начинается с афиши', () => {
    expect(defaultConfig().sections.map((s) => s.type)).toEqual([
      'posters', 'categories', 'promo', 'stats', 'subscribe',
    ])
  })

  it('недостающие настройки секции дополняются дефолтами виджета', async () => {
    await mountPage()
    const store = useStorefrontStore()
    expect(store.config.sections[0].settings.columns).toBe(3)
    expect(store.config.sections[0].settings.limit).toBe(12)
  })
})

describe('AdminStorefrontPage / редактирование секций', () => {
  it('hero можно добавить только один раз', async () => {
    const wrapper = await mountPage()
    const before = dragSections(wrapper).length

    await paletteButton(wrapper, 'Герой-баннер').trigger('click')
    await flushPromises()
    await paletteButton(wrapper, 'Герой-баннер').trigger('click')
    await flushPromises()

    expect(dragSections(wrapper)).toHaveLength(before + 1)
  })

  it('перетаскивание вниз ставит секцию перед целевой', async () => {
    const wrapper = await mountPage()
    const dragTransfer = { setData: vi.fn(), clearData: vi.fn(), effectAllowed: '' }
    const list = dragSections(wrapper)

    await list[0].trigger('dragstart', { dataTransfer: dragTransfer })
    await list[2].trigger('drop', { dataTransfer: dragTransfer })
    await flushPromises()

    const titles = dragSections(wrapper).map((li) => li.text())
    expect(titles[0]).toContain('Куда пойти')
    expect(titles[1]).toContain('Афиша')
    expect(titles[2]).toContain('Промо')
  })
})

describe('AdminStorefrontPage / предпросмотр и сохранение', () => {
  it('keeps the draft after a failed save and blocks reset while saving', async () => {
    let fail!: (reason: Error) => void
    sendMock.mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject }))
    const wrapper = await mountPage()
    await paletteButton(wrapper, 'Обратный отсчёт').trigger('click')
    const count = dragSections(wrapper).length
    await paletteButton(wrapper, 'Сохранить').trigger('click')
    expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined()
    expect(paletteButton(wrapper, 'Сбросить').attributes('disabled')).toBeDefined()
    fail(new Error('Offline'))
    await flushPromises()
    expect(dragSections(wrapper)).toHaveLength(count)
    expect(wrapper.text()).toContain('Есть несохранённые изменения')
    expect(wrapper.find('fieldset').attributes('disabled')).toBeUndefined()
    await paletteButton(wrapper, 'Отменить').trigger('click')
    expect(dragSections(wrapper)).toHaveLength(count - 1)
    wrapper.unmount()
  })

  it('предпросмотр показывает брендинг и подвал, а не только секции', async () => {
    const wrapper = await mountPage()
    await paletteButton(wrapper, 'Предпросмотр').trigger('click')
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('Моя витрина')
    expect(text).toContain('Копирайт X')
    expect(text).toContain('Так витрину увидит покупатель')
  })

  it('предупреждения нормализации видны сразу после сохранения', async () => {
    sendMock.mockResolvedValue({
      data: config(),
      meta: { warnings: ['Секции: сохранено 40 из 45'] },
    })
    const wrapper = await mountPage()

    // Делаем черновик грязным добавлением виджета, затем сохраняем.
    await paletteButton(wrapper, 'Обратный отсчёт').trigger('click')
    await flushPromises()
    await paletteButton(wrapper, 'Сохранить').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Сохранено не полностью')
    expect(wrapper.text()).toContain('40 из 45')
  })
})
