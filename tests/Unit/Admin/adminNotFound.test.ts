/**
 * Экран «Раздел не найден» в админке.
 *
 * Сторожит разворот решения: раньше на неизвестном адресе стояла заглушка
 * «раздел спроектирован, но ещё не реализован». Когда реальные экраны появились
 * для всех пунктов меню, заглушку стало возможно увидеть только по опечатке — и
 * тогда её текст прямо врал: раздела не существует, а не «пока нет».
 *
 * Тесты фиксируют три обещания нового экрана:
 *   1. он называет введённый (ошибочный) сегмент, а не молчит о нём;
 *   2. на близкой опечатке — одна угаданная ссылка (`/admin/oders` → «Заказы»);
 *   3. на постороннем мусоре догадки НЕ выдумываются.
 */
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminNotFoundPage from '@/pages/admin/AdminNotFoundPage.vue'

const push = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { section: currentSection } }),
  useRouter: () => ({ push }),
}))

// `vi.mock` поднимается выше объявлений, поэтому значение живёт в `var`-подобной
// переменной, которую фабрика прочитает в момент вызова хука.
let currentSection = ''

function mountAt(section: string) {
  currentSection = section
  push.mockClear()
  return mount(AdminNotFoundPage)
}

describe('AdminNotFoundPage', () => {
  it('называет введённый сегмент, чтобы опечатка была видна', () => {
    const wrapper = mountAt('qwerty-zzz')

    expect(wrapper.get('h1').text()).toBe('Раздел не найден')
    expect(wrapper.text()).toContain('/admin/qwerty-zzz')
  })

  it('не повторяет прежнее обещание «ещё не реализован»', () => {
    const wrapper = mountAt('qwerty-zzz')

    // Именно эта формулировка была ложной для несуществующего раздела.
    expect(wrapper.text()).not.toMatch(/не реализован|следующ(ем|ий) срез/i)
  })

  it('на близкой опечатке предлагает существующий раздел', () => {
    const wrapper = mountAt('oders')

    expect(wrapper.text()).toContain('Заказы')

    const suggestion = wrapper.findAll('button').find((b) => b.text() === 'Заказы')
    expect(suggestion).toBeTruthy()

    return suggestion!.trigger('click').then(() => {
      expect(push).toHaveBeenCalledWith('/admin/orders')
    })
  })

  it('на постороннем мусоре не выдумывает подсказку', () => {
    const wrapper = mountAt('xyz-nothing')

    expect(wrapper.text()).toContain('Выберите раздел в меню слева')
    expect(wrapper.text()).not.toContain('Возможно, вы имели в виду')
  })

  it('всегда даёт выход на обзор', async () => {
    const wrapper = mountAt('xyz-nothing')

    const overview = wrapper.findAll('button').find((b) => b.text() === 'На обзор')
    expect(overview).toBeTruthy()

    await overview!.trigger('click')
    expect(push).toHaveBeenCalledWith('/admin')
  })
})
