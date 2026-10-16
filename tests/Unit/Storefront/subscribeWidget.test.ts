/**
 * Виджет «Подписка» — форма уходит в `POST /subscribe`.
 *
 * Адрес до этого никуда не уходил (честный заглушке-статус): теперь есть
 * бэкенд (NewsletterController + newsletter_subscribers), и виджет обязан
 * показывать результат — успех, ошибку валидации сервера, сбой сети.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SubscribeWidget from '@/components/storefront/widgets/SubscribeWidget.vue'
import { send } from '@/lib/api'
import type { StorefrontSection } from '@/lib/storefront'

const sendMock = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  send: sendMock,
  ApiError: class ApiError extends Error {
    status: number

    constructor(message: string, status = 500) {
      super(message)
      this.status = status
    }
  },
}))

const SECTION = { type: 'subscribe', title: 'Афиша на почту', settings: {} } as unknown as StorefrontSection

function submitButton(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').find((b) => b.text().includes('Подписаться'))!
}

beforeEach(() => {
  sendMock.mockReset()
})

describe('SubscribeWidget', () => {
  it('локально невалидный адрес не отправляет запрос', async () => {
    const wrapper = mount(SubscribeWidget, { props: { section: SECTION } })

    await wrapper.find('#subscribe-email').setValue('не-адрес')
    await submitButton(wrapper).trigger('submit')
    await flushPromises()

    expect(sendMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Введите корректный e-mail')
  })

  it('успешная подписка показывает «Готово» и очищает поле', async () => {
    sendMock.mockResolvedValue({ data: { email: 'fan@example.test', status: 'subscribed' } })

    const wrapper = mount(SubscribeWidget, { props: { section: SECTION } })

    await wrapper.find('#subscribe-email').setValue('fan@example.test')
    await submitButton(wrapper).trigger('submit')
    await flushPromises()

    expect(sendMock).toHaveBeenCalledWith('/subscribe', 'POST', { email: 'fan@example.test' })
    expect(wrapper.text()).toContain('Готово')
    expect((wrapper.find('#subscribe-email').element as HTMLInputElement).value).toBe('')
  })

  it('сбой сети показывает честный «попробуйте позже»', async () => {
    sendMock.mockRejectedValue(new Error('network down'))

    const wrapper = mount(SubscribeWidget, { props: { section: SECTION } })

    await wrapper.find('#subscribe-email').setValue('fan@example.test')
    await submitButton(wrapper).trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('попробуйте ещё раз чуть позже')
  })
})
