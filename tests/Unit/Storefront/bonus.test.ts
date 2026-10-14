/**
 * «Бонусный счёт», фаза 2a: транспорт и состояние.
 *
 * ЧТО ЗДЕСЬ ПРОВЕРЯЕТСЯ И ПОЧЕМУ ИМЕННО ЭТО
 *   1. Курс. `balance` приходит в минорных единицах, `bonuses` — в бонусах, и
 *      делит их СЕРВЕР. Тест фиксирует, что клиент показывает оба числа как
 *      есть и не пересчитывает: если однажды кто-то решит «поделить на 100 в
 *      вёрстке», это место покраснеет.
 *   2. Знак `delta`. Плюс и минус — разные события (начислили / списали), и
 *      потеря знака сделала бы историю нечитаемой.
 *   3. Неизвестный `reason` отдаётся КАК ЕСТЬ. Сервер и витрина выпускаются
 *      порознь: словарь может расшириться раньше, чем здесь появится подпись,
 *      и выдуманная строка соврала бы о причине списания.
 *   4. Строка запроса. `?page=1` не отправляется: сервер и так начинает с
 *      первой, а лишний параметр в логах только шумит.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { setCustomerToken } from '@/lib/api'
import {
  bonusReasonLabel,
  fetchBonusBalance,
  fetchBonusTransactions,
} from '@/lib/loyalty'
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

type FetchCall = [string, RequestInit]

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.unstubAllGlobals()
})

describe('lib/loyalty: баланс', () => {
  it('разворачивает { data: … } и НЕ пересчитывает курс', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ data: { balance: 25000, bonuses: 250, currency: 'RUB' } })),
    )
    setCustomerToken('customer-token')

    const balance = await fetchBonusBalance()

    // 25 000 копеек и 250 бонусов — два представления одного значения.
    // Делит их сервер; клиент обязан показать оба числа без арифметики.
    expect(balance).toEqual({ balance: 25000, bonuses: 250, currency: 'RUB' })
  })

  it('пустой счёт — нули, а не 404: счёт заводится лениво', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: { balance: 0, bonuses: 0, currency: 'RUB' } })))
    setCustomerToken('customer-token')

    await expect(fetchBonusBalance()).resolves.toEqual({ balance: 0, bonuses: 0, currency: 'RUB' })
  })

  it('на неожиданном ответе отдаёт нули, а не NaN', async () => {
    // «У вас NaN бонусов» читается как поломка сайта. Ноль честнее: настоящую
    // ошибку покажет код ответа, а не число в шапке.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: null })))
    setCustomerToken('customer-token')

    await expect(fetchBonusBalance()).resolves.toEqual({ balance: 0, bonuses: 0, currency: 'RUB' })

    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: { balance: 'мусор', bonuses: null } })))
    await expect(fetchBonusBalance()).resolves.toMatchObject({ balance: 0, bonuses: 0 })
  })
})

describe('lib/loyalty: история движений', () => {
  it('первая страница запрашивается без ?page=1', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    await fetchBonusTransactions({ perPage: 25 })

    const [url] = fetchMock.mock.calls[0] as unknown as FetchCall
    expect(url).toBe('/api/v1/me/bonus/transactions?per_page=25')
  })

  it('вторая страница несёт page и per_page', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    await fetchBonusTransactions({ page: 2, perPage: 25 })

    const [url] = fetchMock.mock.calls[0] as unknown as FetchCall
    expect(url).toBe('/api/v1/me/bonus/transactions?page=2&per_page=25')
  })

  it('разбирает data и meta и сохраняет знак delta', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse({
          data: [
            { id: '01TX1', order_id: '01ORDER', delta: 25000, reason: 'accrual', created_at: '2026-10-14T10:00:00+00:00' },
            { id: '01TX2', order_id: null, delta: -5000, reason: 'expiry', created_at: null },
          ],
          meta: { current_page: 1, per_page: 25, total: 40, last_page: 2 },
        }),
      ),
    )
    setCustomerToken('customer-token')

    const history = await fetchBonusTransactions()

    expect(history.items).toHaveLength(2)
    expect(history.items[0]).toEqual({
      id: '01TX1',
      orderId: '01ORDER',
      delta: 25000,
      reason: 'accrual',
      createdAt: '2026-10-14T10:00:00+00:00',
    })
    // Знак — часть данных, а не оформление: минус отличает сгорание от кэшбэка.
    expect(history.items[1]!.delta).toBe(-5000)
    expect(history.items[1]!.orderId).toBeNull()
    expect(history).toMatchObject({ page: 1, perPage: 25, total: 40, lastPage: 2 })
  })

  it('без meta считает, что страница одна', async () => {
    // Кнопка «Показать ещё» на неизвестной последней странице ушла бы в
    // пустоту; честнее показать список без неё.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [{ id: '01TX1', delta: 100, reason: 'registration' }] })))
    setCustomerToken('customer-token')

    const history = await fetchBonusTransactions()

    expect(history.lastPage).toBe(1)
    expect(history.page).toBe(1)
    expect(history.items).toHaveLength(1)
  })

  it('пустой ответ — пустая история, а не падение', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: null })))
    setCustomerToken('customer-token')

    const history = await fetchBonusTransactions()

    expect(history.items).toEqual([])
    expect(history.lastPage).toBe(1)
  })
})

describe('lib/loyalty: подписи причин', () => {
  it('знает весь словарь ck_bonus_transactions_reason', () => {
    expect(bonusReasonLabel('accrual')).toBe('Кэшбэк за заказ')
    expect(bonusReasonLabel('registration')).toBe('Бонус за регистрацию')
    expect(bonusReasonLabel('first_purchase')).toBe('Бонус за первую покупку')
    expect(bonusReasonLabel('spend')).toBe('Списание бонусов')
    expect(bonusReasonLabel('refund')).toBe('Возврат бонусов')
    expect(bonusReasonLabel('expiry')).toBe('Сгорели по сроку')
  })

  it('неизвестный код отдаёт как есть, а не выдумывает подпись', () => {
    expect(bonusReasonLabel('family_discount')).toBe('family_discount')
    expect(bonusReasonLabel('')).toBe('')
  })
})

describe('stores/loyalty: баланс', () => {
  it('гостю не грузится: запрос к /me/bonus ушёл бы в 401', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: { balance: 0, bonuses: 0, currency: 'RUB' } }))
    vi.stubGlobal('fetch', fetchMock)

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(fetchMock).not.toHaveBeenCalled()
    expect(loyalty.bonus).toBeNull()
    expect(loyalty.bonusesCount).toBe(0)
  })

  it('покупателю баланс приходит вместе со списками', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) =>
        url.includes('/me/bonus')
          ? jsonResponse({ data: { balance: 12300, bonuses: 123, currency: 'RUB' } })
          : jsonResponse({ data: [] }),
      ),
    )
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(loyalty.bonusesCount).toBe(123)
    expect(loyalty.bonus?.currency).toBe('RUB')
    expect(loyalty.loaded).toBe(true)
  })
})
