import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  CUSTOMER_TOKEN_KEY,
  AUTH_TOKEN_KEY,
  activeToken,
  getCustomerToken,
  getToken,
  request,
  setCustomerToken,
  setToken,
} from '@/lib/api'
import {
  clearCustomerSession,
  customerUser,
  fetchMyOrders,
  isCustomerSession,
  saveCustomerUser,
} from '@/lib/customer'

/** Ответ сервера в форме, которой пользуется `request()`. */
function jsonResponse(body: unknown, init: { status?: number; headers?: Record<string, string> } = {}): Response {
  const status = init.status ?? 200
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(init.headers ?? {}),
    json: async () => body,
  } as unknown as Response
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('разделение сессий сотрудника и покупателя', () => {
  it('держит токены в разных ключах и не путает их', () => {
    setToken('staff-token')
    setCustomerToken('customer-token')

    expect(getToken()).toBe('staff-token')
    expect(getCustomerToken()).toBe('customer-token')
    expect(localStorage.getItem(AUTH_TOKEN_KEY)).toBe('staff-token')
    expect(localStorage.getItem(CUSTOMER_TOKEN_KEY)).toBe('customer-token')
  })

  it('считает сессией покупателя ТОЛЬКО покупательский токен', () => {
    // Это и есть причина раскола ключей: токен сотрудника не должен делать
    // покупателя «вошедшим» в кабинет, иначе /admin и /account показывали бы
    // один и тот же чужой профиль.
    setToken('staff-token')
    expect(isCustomerSession()).toBe(false)

    setCustomerToken('customer-token')
    expect(isCustomerSession()).toBe(true)
  })

  it('предпочитает токен сотрудника, но умеет брать покупательский', () => {
    expect(activeToken()).toEqual({ token: null, audience: null })

    setCustomerToken('customer-token')
    expect(activeToken()).toEqual({ token: 'customer-token', audience: 'customer' })

    setToken('staff-token')
    expect(activeToken()).toEqual({ token: 'staff-token', audience: 'staff' })
  })

  it('с явной аудиторией не подменяет токен чужой сессией', () => {
    setToken('staff-token')
    setCustomerToken('customer-token')

    // Выход из кабинета обязан отозвать сессию ПОКУПАТЕЛЯ: с общим выбором
    // («сотрудник важнее») он выкинул бы из админки.
    expect(activeToken('customer')).toEqual({ token: 'customer-token', audience: 'customer' })
    expect(activeToken('staff')).toEqual({ token: 'staff-token', audience: 'staff' })
  })

  it('на 401 гасит только ту сессию, которой отвечал сервер', async () => {
    setToken('staff-token')
    setCustomerToken('customer-token')

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'nope' } }, { status: 401 })),
    )

    await expect(request('/me', { as: 'customer' })).rejects.toMatchObject({ status: 401 })

    expect(getCustomerToken()).toBeNull()
    expect(getToken()).toBe('staff-token')
  })

  it('не гасит сессию на 401 с кодом bad_credentials', async () => {
    setCustomerToken('customer-token')

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ error: { code: 'bad_credentials', message: 'nope' } }, { status: 401 })),
    )

    await expect(request('/auth/login', { method: 'POST', body: {} })).rejects.toMatchObject({ status: 401 })

    // Неудачная попытка входа не должна выкидывать уже открытую сессию.
    expect(getCustomerToken()).toBe('customer-token')
  })

  it('шлёт покупательский токен в Authorization, когда нет сотруднического', async () => {
    setCustomerToken('customer-token')

    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/me/orders')

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer customer-token')
  })
})

describe('сохранённый профиль покупателя', () => {
  it('читается, перезаписывается и стирается вместе с сессией', () => {
    setCustomerToken('customer-token')
    saveCustomerUser({ id: 'U1', email: 'buyer@example.test', first_name: 'Иван', last_name: 'Петров' })

    expect(customerUser()?.first_name).toBe('Иван')

    clearCustomerSession()

    expect(customerUser()).toBeNull()
    expect(isCustomerSession()).toBe(false)
  })

  it('не падает на испорченном значении в localStorage', () => {
    localStorage.setItem('nabilet_customer_user', '{ это не json')

    expect(customerUser()).toBeNull()
  })
})

describe('fetchMyOrders', () => {
  /*
   * ОДНА ФОРМА, А НЕ ДВЕ.
   *
   * Раньше здесь было два теста — «читает колонки таблицы» и «читает схему
   * контракта» — потому что `GET /me/orders`, `GET /orders` и `POST /orders`
   * сериализовали модель `Order` напрямую и отдавали КОЛОНКИ, тогда как схема
   * `Order` контракта описывала `totals`, `id` = public_id и
   * `items[].event_title` (`docs/REVIEW-spec-bundle.md` §3.27.4). Проверять
   * «клиент читает обе формы» значило закреплять дефект в тестах.
   *
   * После правки ответ приводит `OrderResource` ровно к схеме, и запасной
   * нормализации у `normalizeOrder()` нет намеренно: расхождение видно сразу,
   * а не через молчаливо неправильную сумму в кабинете покупателя.
   */
  it('читает схему Order: id, totals, customer_*, items[].event_title', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: [
            {
              id: '01HZZZZZZZZZZZZZZZZZZZZZZZ',
              order_number: 'NB-20261013-ABCD1234',
              status: 'paid',
              payment_status: 'succeeded',
              totals: { subtotal_amount: 250000, discount_amount: 0, fee_amount: 0, total_amount: 250000, currency: 'RUB' },
              customer_email: 'buyer@example.test',
              customer_name: 'Иван Петров',
              items: [
                { id: '1', event_title: 'Концерт', quantity: 2, total_amount: 250000 },
                { id: '2', event_title: 'Концерт', quantity: 2, total_amount: 250000 },
              ],
              created_at: '2026-10-13T10:00:00+00:00',
            },
          ],
        }),
      ),
    )

    const [order] = await fetchMyOrders()

    expect(order).toBeDefined()
    expect(order!.id).toBe('01HZZZZZZZZZZZZZZZZZZZZZZZ')
    expect(order!.number).toBe('NB-20261013-ABCD1234')
    expect(order!.status).toBe('paid')
    expect(order!.totalMinor).toBe(250000)
    expect(order!.currency).toBe('RUB')
    expect(order!.itemsCount).toBe(2)
    expect(order!.eventTitles).toEqual(['Концерт', 'Концерт'])
    expect(order!.email).toBe('buyer@example.test')
  })

  it('на пустом и неожиданном ответе отдаёт пустой список, а не падает', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: null })))
    expect(await fetchMyOrders()).toEqual([])

    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({})))
    expect(await fetchMyOrders()).toEqual([])
  })
})
