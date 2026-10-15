import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import {
  AUTH_TOKEN_KEY,
  CUSTOMER_TOKEN_KEY,
  request,
  setCustomerToken,
  setToken,
} from '@/lib/api'
import {
  addFavorite,
  fetchFavorites,
  fetchFollows,
  removeFavorite,
  followEvent,
  unfollowEvent,
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

/** Строка списка в схеме `EventFavorite` / `EventFollow`. */
function entry(publicId: string, eventId: string, title = 'Концерт') {
  return {
    id: publicId,
    event: {
      id: eventId,
      title,
      slug: 'koncert',
      poster: null,
      age_limit: '12+',
      starts_at: '2027-03-14T19:00:00+00:00',
    },
    created_at: '2026-10-14T10:00:00+00:00',
  }
}

/** Аргументы вызова fetch: адрес и опции. */
type FetchCall = [string, RequestInit]

beforeEach(() => {
  localStorage.clear()
  setActivePinia(createPinia())
  vi.unstubAllGlobals()
})

describe('lib/loyalty: чтение списков', () => {
  it('fetchFavorites разворачивает { data: [...] } и приводит поля к типам', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [entry('01FAV', '01EV')] })))

    const list = await fetchFavorites()

    expect(list).toHaveLength(1)
    expect(list[0]!.id).toBe('01FAV')
    expect(list[0]!.event!.id).toBe('01EV')
    expect(list[0]!.event!.title).toBe('Концерт')
    expect(list[0]!.event!.age_limit).toBe('12+')
  })

  it('на пустом и неожиданном ответе отдаёт пустой список, а не падает', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: null })))
    expect(await fetchFavorites()).toEqual([])

    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({})))
    expect(await fetchFollows()).toEqual([])

    // Голый массив вместо конверта — тоже ответ, а не повод падать.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse([])))
    expect(await fetchFollows()).toEqual([])
  })

  it('строку без мероприятия приводит к event: null, а не бросает', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [{ id: '01FAV', created_at: null }] })))

    const [row] = await fetchFavorites()

    expect(row!.event).toBeNull()
    expect(row!.id).toBe('01FAV')
  })
})

describe('lib/loyalty: адресация по public_id', () => {
  it('addFavorite шлёт POST на /me/events/{public_id}/favorite', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: entry('01FAV', '01EV') }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const created = await addFavorite('01EV')

    const [url, init] = fetchMock.mock.calls[0] as unknown as FetchCall
    // Именно public_id в пути: внутренний BIGINT дал бы 404 на resolveEvent().
    expect(url).toBe('/api/v1/me/events/01EV/favorite')
    expect(init.method).toBe('POST')
    expect(created!.id).toBe('01FAV')
  })

  it('removeFavorite шлёт DELETE на тот же путь', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    await removeFavorite('01EV')

    const [url, init] = fetchMock.mock.calls[0] as unknown as FetchCall
    expect(url).toBe('/api/v1/me/events/01EV/favorite')
    expect(init.method).toBe('DELETE')
  })

  it('followEvent и unfollowEvent ходят на /follow', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: entry('01FOL', '01EV') }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    await followEvent('01EV')

    let call = fetchMock.mock.calls[0] as unknown as FetchCall
    expect(call[0]).toBe('/api/v1/me/events/01EV/follow')
    expect(call[1].method).toBe('POST')

    const deleteMock = vi.fn(async () => jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', deleteMock)
    await unfollowEvent('01EV')

    call = deleteMock.mock.calls[0] as unknown as FetchCall
    expect(call[0]).toBe('/api/v1/me/events/01EV/follow')
    expect(call[1].method).toBe('DELETE')
  })

  it('кодирует public_id, если тот содержит спецсимволы', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: entry('01FAV', '01EV') }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    await addFavorite('01EV/WITH SLASH')

    const [url] = fetchMock.mock.calls[0] as unknown as FetchCall
    expect(url).toBe('/api/v1/me/events/01EV%2FWITH%20SLASH/favorite')
  })
})

describe('stores/loyalty: отметки', () => {
  it('гостю не грузит списки — запрос к /me/* ушёл бы в 401', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(fetchMock).not.toHaveBeenCalled()
    expect(loyalty.isFavorite('01EV')).toBe(false)
  })

  it('токен сотрудника — не сессия покупателя: списки витрины не грузятся', async () => {
    // Ключи токенов разведены намеренно (api.ts): сотрудник, зашедший на
    // витрину, не должен тянуть чужое «избранное» покупателя.
    setToken('staff-token')
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(fetchMock).not.toHaveBeenCalled()
    expect(localStorage.getItem(AUTH_TOKEN_KEY)).toBe('staff-token')
  })

  it('load() читает оба списка, баланс и уровень клуба, наполняет отметки', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url.includes('/me/favorites')) return jsonResponse({ data: [entry('01FAV', '01EV', 'Концерт')] })
      if (url.includes('/me/bonus')) return jsonResponse({ data: { balance: 25000, bonuses: 250, currency: 'RUB' } })
      if (url.includes('/me/loyalty-level'))
        return jsonResponse({
          data: {
            slug: 'start',
            name: 'Старт',
            min_paid_orders: 0,
            paid_orders: 0,
            next_level: { slug: 'friend', name: 'Друг', min_paid_orders: 3 },
            orders_to_next: 3,
          },
        })
      return jsonResponse({ data: [entry('01FOL', '01EV2', 'Спектакль')] })
    })
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.load()

    expect(loyalty.isFavorite('01EV')).toBe(true)
    expect(loyalty.isFollowing('01EV2')).toBe(true)
    expect(loyalty.isFavorite('01EV2')).toBe(false)
    expect(loyalty.favoritesCount).toBe(1)
    expect(loyalty.followsCount).toBe(1)
    // Баланс грузится тем же `load()`: чип в шапке и плитка в кабинете
    // спрашивают одно число, и второй запрос к `/me/bonus` был бы лишним
    // ответом на один вопрос.
    expect(loyalty.bonusesCount).toBe(250)
    expect(loyalty.bonus?.balance).toBe(25000)
    // Уровень клуба — тоже часть `load()`: плитка и прогресс-бар кабинета
    // спрашивают его вместе с отметками.
    expect(loyalty.loyaltyLevel?.slug).toBe('start')

    // Повторный load() без force не ходит в сеть: навигация по витрине не
    // должна перезапрашивать личные данные на каждом переходе.
    fetchMock.mockClear()
    await loyalty.load()
    expect(fetchMock).not.toHaveBeenCalled()

    // force перечитывает — по одному запросу на каждый из четырёх ресурсов
    // (избранное, подписки, баланс, уровень клуба).
    await loyalty.load(true)
    expect(fetchMock).toHaveBeenCalledTimes(4)
  })

  it('ошибка загрузки не пробрасывается и не оставляет мусора', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ error: { message: 'boom' } }, { status: 500 })))
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await expect(loyalty.load()).resolves.toBeUndefined()

    expect(loyalty.favorites).toEqual([])
    expect(loyalty.follows).toEqual([])
    // Баланс тоже не «ноль», а «не загружен»: иначе чип в шапке показал бы
    // «✦ 0» человеку, у которого бонусы есть.
    expect(loyalty.bonus).toBeNull()
    expect(loyalty.loaded).toBe(false)
  })

  it('toggleFavorite ставит отметку только после ответа сервера', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: entry('01FAV', '01EV') }, { status: 201 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await expect(loyalty.toggleFavorite('01EV')).resolves.toBe(true)

    expect(loyalty.isFavorite('01EV')).toBe(true)
    expect(loyalty.favorites[0]!.event!.title).toBe('Концерт')
  })

  it('повторный toggleFavorite снимает отметку и убирает строку', async () => {
    // Первый вызов — POST (ставим), второй — DELETE (снимаем).
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ data: entry('01FAV', '01EV') }, { status: 201 }))
      .mockResolvedValueOnce(jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.toggleFavorite('01EV')
    await expect(loyalty.toggleFavorite('01EV')).resolves.toBe(false)

    expect(fetchMock).toHaveBeenCalledTimes(2)
    const [, second] = fetchMock.mock.calls[1] as unknown as FetchCall
    expect(second.method).toBe('DELETE')

    expect(loyalty.isFavorite('01EV')).toBe(false)
    expect(loyalty.favorites).toHaveLength(0)
  })

  it('отметка не появляется, когда сервер отказал', async () => {
    // 404: мероприятие сняли с публикации между показом карточки и нажатием.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ error: { message: 'not found' } }, { status: 404 })))
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await expect(loyalty.toggleFavorite('01EV')).rejects.toMatchObject({ status: 404 })

    expect(loyalty.isFavorite('01EV')).toBe(false)
    expect(loyalty.favorites).toHaveLength(0)
  })

  it('toggleFollow подписывает и отписывает по тому же правилу', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ data: entry('01FOL', '01EV') }, { status: 201 }))
      .mockResolvedValueOnce(jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await expect(loyalty.toggleFollow('01EV')).resolves.toBe(true)
    expect(loyalty.isFollowing('01EV')).toBe(true)

    await expect(loyalty.toggleFollow('01EV')).resolves.toBe(false)
    expect(loyalty.isFollowing('01EV')).toBe(false)
    expect(loyalty.follows).toHaveLength(0)
  })

  it('пустой идентификатор — не запрос, а текущее состояние', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()

    await expect(loyalty.toggleFavorite('')).resolves.toBe(false)
    await expect(loyalty.toggleFollow('')).resolves.toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('reset() забывает оба списка и баланс — выход и смена аккаунта', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url.includes('/me/favorites')) return jsonResponse({ data: [entry('01FAV', '01EV')] })
      if (url.includes('/me/bonus')) return jsonResponse({ data: { balance: 5000, bonuses: 50, currency: 'RUB' } })
      return jsonResponse({ data: [entry('01FOL', '01EV')] })
    })
    vi.stubGlobal('fetch', fetchMock)
    setCustomerToken('customer-token')

    const loyalty = useLoyaltyStore()
    await loyalty.load()
    expect(loyalty.isFavorite('01EV')).toBe(true)
    expect(loyalty.bonusesCount).toBe(50)

    loyalty.reset()

    expect(loyalty.isFavorite('01EV')).toBe(false)
    expect(loyalty.isFollowing('01EV')).toBe(false)
    expect(loyalty.favorites).toEqual([])
    // Чужой счёт в шапке после смены аккаунта — та же утечка, что и чужой
    // список избранного, поэтому гасится вместе с ним.
    expect(loyalty.bonus).toBeNull()
    expect(loyalty.bonusesCount).toBe(0)
    expect(loyalty.loaded).toBe(false)
  })
})

describe('lib/loyalty: токен покупателя в заголовке', () => {
  it('запрос уходит с покупательским Bearer', async () => {
    setCustomerToken('customer-token')
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    await request('/me/favorites')

    const [, init] = fetchMock.mock.calls[0] as unknown as FetchCall
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer customer-token')
    expect(localStorage.getItem(CUSTOMER_TOKEN_KEY)).toBe('customer-token')
  })
})
