/**
 * Пикер медиатеки (MediaPickerField): ленивый список, выбор → emit URL,
 * очистка поля. Компонент нужен трём формам (hero конструктора витрины,
 * логотип и иконка сайта в брендинге), поэтому проверяется отдельным файлом.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
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

function mountField(modelValue = '', props: Record<string, string> = {}) {
  return mount(MediaPickerField, {
    props: { modelValue, label: 'Фон', hint: 'подсказка', ...props },
  })
}

beforeEach(() => {
  getMock.mockReset()
  getMock.mockResolvedValue({ data: IMAGES })
})

describe('MediaPickerField', () => {
  it('лениво подгружает медиатеку по кнопке', async () => {
    const wrapper = mountField()

    expect(getMock).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('b.jpg')

    await wrapper.find('button[aria-label="Выбрать из медиатеки"], button').trigger('click')
    await flushPromises()

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(getMock).toHaveBeenCalledWith('/media?per_page=48')
    expect(wrapper.text()).toContain('a.png')
  })

  it('клик по превью возвращает URL файла', async () => {
    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    // Второй файл — по его имени в заголовке плитки.
    const tile = wrapper.findAll('button').find((b) => b.text().includes('b.jpg'))
    expect(tile).toBeTruthy()

    await tile!.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['https://cdn.test/b.jpg']])
  })

  it('второе открытие не перезапрашивает список', async () => {
    const wrapper = mountField()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    // Закрыть и открыть снова.
    await wrapper.find('button[aria-label="Закрыть пикер"]').trigger('click')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(getMock).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('a.png')
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

    expect(wrapper.text()).toContain('boom')
  })
})
