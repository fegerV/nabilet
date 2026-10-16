/**
 * Конструктор витрины и тема интерфейса.
 *
 * Регрессия: раньше `watch(draft.theme)` вызывал `ui.setTheme(theme.mode)` —
 * вход в конструктор «переключал» всю админку на тему из черновика витрины,
 * и после выхода пользователь оставался в чужой теме. Черновик витрины
 * значим только для предпросмотра (`previewVars` красит контейнер), а
 * глобальная тема — личная настройка интерфейса.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AdminStorefrontPage from '@/pages/admin/AdminStorefrontPage.vue'
import { useUiStore } from '@/stores/ui'
import { cloneConfig, type StorefrontConfig } from '@/lib/storefront'

const getMock = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  get: getMock,
}))

const DRAFT: Partial<StorefrontConfig> = { theme: { mode: 'light' } } as Partial<StorefrontConfig>

async function mountPage() {
  const wrapper = mount(AdminStorefrontPage)

  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())

  getMock.mockReset()

  getMock.mockImplementation(async (url: string) => {
    if (String(url).startsWith('/admin/storefront')) {
      return { data: DRAFT, meta: { is_default: false } }
    }

    if (String(url).startsWith('/events')) {
      return { data: [] }
    }

    return { data: [] }
  })
})

describe('AdminStorefrontPage / theme', () => {
  it('вход в конструктор не переключает тему админки', async () => {
    const ui = useUiStore()
    const before = ui.theme
    const spy = vi.spyOn(ui, 'setTheme')

    await mountPage()

    // Черновик витрины приходит с mode='light'; глобальная тема осталась
    // прежней, и сеттер не вызывался (старый watcher звал его на загрузке).
    expect(ui.theme).toBe(before)
    expect(spy).not.toHaveBeenCalled()
  })

  it('в брендинге вместо строковых полей картинок — пикер медиатеки', async () => {
    const wrapper = await mountPage()

    const brandingTab = wrapper.findAll('button').find((b) => b.text() === 'Брендинг')
    expect(brandingTab).toBeTruthy()

    await brandingTab!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Выбрать из медиатеки')

    // Два пикера: логотип и иконка сайта (URL-инпут живёт внутри каждого).
    expect(wrapper.text().split('Выбрать из медиатеки').length - 1).toBe(2)
  })
})
