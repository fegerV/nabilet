/**
 * Пикер медиатеки (MediaPickerField) в модалке: ленивый список, выбор →
 * emit URL, очистка, ошибка сети. Компонент нужен трём формам (hero
 * конструктора витрины, логотип и иконка сайта в брендинге).
 *
 * NModal телепортирует содержимое в document.body, поэтому пикер ищется
 * не через wrapper, а в body.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import MediaPickerField from '@/components/admin/MediaPickerField.vue'
import { get } from '@/lib/api'

const getMock = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  get: getMock,
}))

const IMAGES = [
  { id: 1, url: 'https://cdn.test/a.png', filename: 'a.png', mime_type: 'image/png' },
  { id: 2, url: 'https://cdn.test/b.jpg', filename: 'b.jpg', mime_type: 'image/jpeg' },
]

function mountField(modelValue = '') {
  return mount(MediaPickerField, {
    props: { modelValue, label: 'Фон', hint: 'подсказка' },
    attachTo: document.body,
  })
}

function pickerInBody(): HTMLElement | null {
  const heading = Array.from(document.body.querySelectorAll('h2')).find((h) => h.textContent === 'Медиатека')

  return (heading?.closest('div[role="dialog"]') as HTMLElement | null) ?? null
}

function tile(filename: string): HTMLButtonElement | null {
  const dialog = pickerInBody()

  if (!dialog) return null

  return Array.from(dialog.querySelectorAll('button')).find((b) => b.textContent?.includes(filename)) ?? null
}

beforeEach(() => {
  getMock.mockReset()
  getMock.mockResolvedValue({ data: IMAGES })
  document.body.innerHTML = ''
})

// NModal телепортирует в body: без размонтирования DOM прошлого теста
// оставался в body, и «закрытая» модалка находилась по селектору вечноживо.
enableAutoUnmount(afterEach)

describe('MediaPickerField (модалка)', () => {
  it('кнопка открывает модалку и лениво подгружает медиатеку', async () => {
    const wrapper = mountField()

    expect(getMock).not.toHaveBeenCalled()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const dialog = pickerInBody()

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(getMock).toHaveBeenCalledWith('/media?per_page=48')
    expect(dialog).not.toBeNull()
    expect(dialog!.textContent).toContain('a.png')
    expect(dialog!.textContent).toContain('b.jpg')
  })

  it('клик по превью возвращает URL файла и закрывает модалку', async () => {
    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    await tile('b.jpg')!.click()
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')).toEqual([['https://cdn.test/b.jpg']])
    expect(pickerInBody()).toBeNull()
  })

  it('второе открытие не перезапрашивает список', async () => {
    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    // Отмена из футера модалки.
    const cancel = Array.from(pickerInBody()!.querySelectorAll('button')).find((b) => b.textContent === 'Отмена')

    await cancel!.click()

    expect(pickerInBody()).toBeNull()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(pickerInBody()!.textContent).toContain('a.png')
  })

  it('Escape закрывает модалку', async () => {
    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(pickerInBody()).not.toBeNull()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await flushPromises()

    expect(pickerInBody()).toBeNull()
  })

  it('очищает поле отдельной кнопкой', async () => {
    const wrapper = mountField('https://cdn.test/a.png')

    const clear = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Очистить поле')
    expect(clear).toBeTruthy()

    await clear!.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
  })

  it('показывает ошибку загрузки и не ломается', async () => {
    getMock.mockRejectedValue(new Error('boom'))

    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(pickerInBody()!.textContent).toContain('boom')
  })
})
