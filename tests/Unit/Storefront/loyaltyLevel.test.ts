import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { setCustomerToken } from '@/lib/api'
import { fetchLoyaltyLevel } from '@/lib/loyalty'
import { useLoyaltyStore } from '@/stores/loyalty'

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

/** Снимок уровня по схеме `LoyaltyLevelStatus`. */
function levelStatus(over: Record<string, unknown> = {}) {
  return {
    slug: 'start',
    name: 'Старт',
    min_paid_orders: 0,
    paid_orders: 0,
    next_level: { slug: 'friend', name: 'Друг', min_paid_orders: 3 },
    orders_to_next: 3,
    ...over,
  }
}

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.unstubAllGlobals()
})

describe('lib/loyalty: fetchLoyaltyLevel', () => {
  it('читает /me/loyalty-level и приводит поля к типам', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: levelStatus() })))
    setCustomerToken('customer-token')

    const status = await fetchLoyaltyLevel()

    expect(status.slug).toBe('start')
    expect(status.name).toBe('Старт')
    expect(status.paid_orders).toBe(0)
    expect(status.next_level).not.toBeNull()
    expect(status.next_level!.slug).toBe('friend')
    expect(status.orders_to_next).toBe(3)
  })

  it('на вершине next_level приходит как null', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: levelStatus({ next_level: null, orders_to_next: 0, slug: 'vip', name: 'VIP', paid_orders: 10 }),
        }),
      ),
    )
    setCustomerToken('customer-token')

    const status = await fetchLoyaltyLevel()

    expect(status.slug).toBe('vip')
    expect(status.next_level).toBeNull()
    expect(status.orders_to_next).toBe(0)
  })
})

describe('stores/loyalty: уровень клуба', () => {
  it('load() наполняет loyaltyLevel из ответа /me/loyalty-level', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url.includes('/me/loyalty-level')) {
        return jsonResponse({
          data: levelStatus({
            slug: 'friend',
            name: 'Друг',
            paid_orders: 3,
            orders_to_next: 7,
            next_level: { slug: 'vip', name: 'VIP', min_paid_orders: 10 },
          }),
        })
      }
      if (url.includes('/me/favorites')) return jsonResponse({ data: [] })
      if (url.includes('/me/follows')) return jsonResponse({ data: [] })
      if (url.includes('/me/bonus')) return jsonResponse({ data: { balance: 0, bonuses: 0, currency: 'RUB' } })
      return jsonResponse({ data: [] })
    })
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(loyalty.loyaltyLevel).not.toBeNull()
    expect(loyalty.loyaltyLevel!.slug).toBe('friend')
    expect(loyalty.loyaltyLevel!.paid_orders).toBe(3)
    expect(loyalty.loyaltyLevel!.next_level!.slug).toBe('vip')
  })

  it('ошибка загрузки сбрасывает loyaltyLevel в null', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ error: { message: 'boom' } }, { status: 500 })))
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(loyalty.loyaltyLevel).toBeNull()
    expect(loyalty.loaded).toBe(false)
  })
})
