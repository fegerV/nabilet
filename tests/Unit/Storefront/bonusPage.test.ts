/**
 * Экран «Бонусы»: баланс, история, пагинация.
 *
 * ЗАЧЕМ МОНТИРОВАТЬ, А НЕ ТОЛЬКО ТЕСТИРОВАТЬ СТОР
 *   Стор проверяет транспорт, но не проверяет шаблон: экран может обратиться к
 *   полю, которого нет в ответе (`row.orderId` у движения без заказа), и упасть
 *   уже у покупателя. Плюс здесь живёт логика, которой в сторе нет вовсе —
 *   «Показать ещё»: догрузка ДОБАВЛЯЕТ строки, а не заменяет список.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { setCustomerToken } from '@/lib/api'
import { useLoyaltyStore } from '@/stores/loyalty'
import BonusPage from '@/pages/storefront/BonusPage.vue'

/** Ответ сервера в форме, которой пользуется `request()`. */
function jsonResponse(body: unknown, init: { status?: number } = {}): Response {
  const status = init.status ?? 200
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(),
    json: async () => body,
  } as unknown as Response
}

/** Строка журнала в схеме `BonusTransaction`. */
function tx(id: string, delta: number, reason: string, orderId: string | null = null) {
  return {
    id,
    order_id: orderId,
    delta,
    reason,
    // Полдень UTC: любая зона до ±12 оставляет ту же дату, и проверка не
    // зависит от того, где запущен CI.
    created_at: '2026-10-14T12:00:00+00:00',
  }
}

/** Страница + стор с заданным балансом. */
function mountPage(balance: { balance: number; bonuses: number } | null = { balance: 25000, bonuses: 250 }) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const loyalty = useLoyaltyStore()
  loyalty.bonus = balance === null ? null : { ...balance, currency: 'RUB' }

  return {
    loyalty,
    wrapper: mount(BonusPage as never, { global: { plugins: [pinia] } }),
  }
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('BonusPage: баланс', () => {
  it('показывает баланс в бонусах и его рублёвый эквивалент', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [] })))

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('250')
    expect(wrapper.text()).toContain('бонусов')
    expect(wrapper.text()).toContain('250 ₽')
  })

  it('нулевой баланс — «0 бонусов», а не пустая плитка', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [] })))

    const { wrapper } = mountPage({ balance: 0, bonuses: 0 })
    await flushPromises()

    expect(wrapper.text()).toContain('0')
    expect(wrapper.text()).toContain('бонусов')
  })

  it('не обещает списание в корзине — фаза 2b ещё не сделана', async () => {
    // Кнопки «оплатить бонусами» в корзине нет, и текст не должен её обещать.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [] })))

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('появится в следующем обновлении')
  })
})

describe('BonusPage: история', () => {
  it('пустая история объясняет, откуда возьмутся бонусы', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }),
      ),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Движений пока нет')
    expect(wrapper.text()).toContain('после первого оплаченного заказа')
  })

  it('показывает причину, дату и знак движения', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: [tx('01TX1', 25000, 'accrual', '01ORDER'), tx('01TX2', -5000, 'expiry')],
          meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
        }),
      ),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Кэшбэк за заказ')
    expect(wrapper.text()).toContain('+250 ₽')
    // Минус — не оформление, а смысл: сгорание по сроку отличается от кэшбэка
    // именно знаком, и потерять его значит соврать о причине.
    expect(wrapper.text()).toContain('−50 ₽')
    expect(wrapper.text()).toContain('14 октября')
    // Ссылка на заказ есть только там, где заказ есть.
    expect(wrapper.text()).toContain('заказ 01ORDER')
  })

  it('неизвестную причину показывает кодом, а не выдуманной подписью', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ data: [tx('01TX1', 1000, 'family_discount')] })),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('family_discount')
  })

  it('ошибка загрузки — сообщение и «Повторить», а не пустой экран', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ error: { message: 'Сервис недоступен' } }, { status: 500 })),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('Повторить')
    expect(wrapper.text()).not.toContain('Движений пока нет')
  })
})

describe('BonusPage: пагинация', () => {
  it('«Показать ещё» есть только когда есть следующая страница', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ data: [tx('01TX1', 100, 'accrual')], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } })),
    )

    const { wrapper } = mountPage()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Показать ещё')
  })

  it('догрузка ДОБАВЛЯЕТ строки и запрашивает следующую страницу', async () => {
    // Разбор адреса — регуляркой, а не `includes('page=2')`: подстрока
    // «page=2» сидит и внутри `per_page=25`, и первая страница была бы принята
    // за вторую.
    const fetchMock = vi.fn(async (url: string) =>
      /[?&]page=2(&|$)/.test(url)
        ? jsonResponse({
            data: [tx('01TX2', 5000, 'registration')],
            meta: { current_page: 2, per_page: 25, total: 2, last_page: 2 },
          })
        : jsonResponse({
            data: [tx('01TX1', 25000, 'accrual')],
            meta: { current_page: 1, per_page: 25, total: 2, last_page: 2 },
          }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const { wrapper } = mountPage()
    await flushPromises()

    const more = wrapper.findAll('button').find((b) => b.text().includes('Показать ещё'))
    expect(more).toBeDefined()

    await more!.trigger('click')
    await flushPromises()

    const [url] = fetchMock.mock.calls[1] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/me/bonus/transactions?page=2&per_page=25')

    // Обе страницы на месте: догрузка продолжает список, а не заменяет его.
    expect(wrapper.text()).toContain('Кэшбэк за заказ')
    expect(wrapper.text()).toContain('Бонус за регистрацию')
    // Последняя страница — кнопки больше нет.
    expect(wrapper.text()).not.toContain('Показать ещё')
  })

  it('гость не тянет личные данные: токена нет — запроса нет', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    mountPage(null)
    await flushPromises()

    // Единственный возможный запрос — история, и он уходит всегда (экран без
    // неё бессмыслен). Баланс же для гостя не запрашивается вовсе.
    const urls = fetchMock.mock.calls.map((call) => (call as unknown as [string])[0])
    expect(urls.some((url) => url.includes('/me/bonus/transactions'))).toBe(true)
    expect(urls.some((url) => url.endsWith('/me/bonus'))).toBe(false)
  })

  it('покупатель получает баланс из стора, а не из ответа истории', async () => {
    setCustomerToken('customer-token')
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) =>
        url.includes('/me/bonus/transactions')
          ? jsonResponse({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } })
          : url.endsWith('/me/bonus')
            ? jsonResponse({ data: { balance: 9900, bonuses: 99, currency: 'RUB' } })
            : jsonResponse({ data: [] }),
      ),
    )

    const { wrapper, loyalty } = mountPage(null)
    await flushPromises()

    expect(loyalty.bonusesCount).toBe(99)
    expect(wrapper.text()).toContain('99')
  })
})
