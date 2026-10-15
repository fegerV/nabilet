/**
 * Экраны «Клуба зрителей»: пустые состояния, список и снятие отметки.
 *
 * ЗАЧЕМ МОНТИРОВАТЬ, А НЕ ТОЛЬКО ТЕСТИРОВАТЬ СТОР
 *   Стор проверяет транспорт и состояние, но не проверяет экран: шаблон может
 *   обратиться к полю, которого нет в схеме (`entry.event.title` у строки без
 *   мероприятия), и упасть уже у покупателя. Эти тесты монтируют страницы
 *   целиком — включая ветку «мероприятие снято с публикации», которую обычным
 *   путем не встретить.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import { setCustomerToken } from '@/lib/api'
import { useLoyaltyStore } from '@/stores/loyalty'
import FavoritesPage from '@/pages/storefront/FavoritesPage.vue'
import SubscriptionsPage from '@/pages/storefront/SubscriptionsPage.vue'
import EventCard from '@/components/storefront/EventCard.vue'
import type { LoyaltyEntry } from '@/lib/loyalty'

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

function favoriteRow(eventId: string, title = 'Концерт'): LoyaltyEntry {
  return {
    id: '01FAV',
    event: {
      id: eventId,
      title,
      slug: 'koncert',
      poster: null,
      age_limit: '12+',
      // Полдень UTC: любая зона до ±12 оставляет ту же дату, и проверка не
      // зависит от того, где запущен CI.
      starts_at: '2027-03-14T12:00:00+00:00',
    },
    created_at: '2026-10-14T10:00:00+00:00',
  }
}

/** Страница + стор с заранее заполненными списками. */
function mountPage(component: unknown, rows: { favorites?: LoyaltyEntry[]; follows?: LoyaltyEntry[] }) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const loyalty = useLoyaltyStore()
  loyalty.favorites = rows.favorites ?? []
  loyalty.follows = rows.follows ?? []

  const router = createRouter({ history: createWebHashHistory(), routes: [] })

  return {
    router,
    loyalty,
    wrapper: mount(component as never, {
      global: {
        plugins: [pinia, router],
        stubs: {
          // Страница строит ссылки витрины; в тесте достаточно самой ссылки.
          RouterLink: { template: '<a><slot /></a>' },
        },
      },
    }),
  }
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('FavoritesPage', () => {
  it('пустое состояние объясняет, что делать', () => {
    const { wrapper } = mountPage(FavoritesPage, {})

    expect(wrapper.text()).toContain('В избранном пока пусто')
    expect(wrapper.text()).toContain('К афише')
  })

  it('показывает название, дату и возрастное ограничение', () => {
    const { wrapper } = mountPage(FavoritesPage, { favorites: [favoriteRow('01EV')] })

    expect(wrapper.text()).toContain('Концерт')
    // Дата ближайшего сеанса из `starts_at` — 14 марта 2027 (полдень UTC,
    // чтобы дата не поехала из-за часового пояса запуска).
    expect(wrapper.text()).toContain('14 марта')
    expect(wrapper.text()).toContain('12+')
  })

  it('«Убрать» снимает отметку через API и убирает строку', async () => {
    // Токена покупателя нет намеренно: `onMounted` → `load()` тогда no-op
    // (гостю списки не грузятся), и единственный запрос в mocked fetch — тот,
    // который делает кнопка. В производстве за вход решает guard маршрута.
    const fetchMock = vi.fn(async () => jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    const { wrapper, loyalty } = mountPage(FavoritesPage, { favorites: [favoriteRow('01EV')] })
    expect(wrapper.findAll('button')).toHaveLength(1)

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/me/events/01EV/favorite')
    expect(init.method).toBe('DELETE')
    expect(loyalty.isFavorite('01EV')).toBe(false)
    expect(loyalty.favorites).toHaveLength(0)
  })

  it('строку без мероприятия не роняет: подпись вместоссылки', () => {
    // Сервер грузит `event` вместе со строкой, а FK удаляет отметку вместе с
    // мероприятием — но контракт может измениться, и экран не должен падать.
    const { wrapper } = mountPage(FavoritesPage, { favorites: [{ id: '01FAV', event: null, created_at: null }] })

    expect(wrapper.text()).toContain('Мероприятие снято с публикации')
  })
})

describe('SubscriptionsPage', () => {
  it('пустое состояние отправляет на страницу мероприятия за подпиской', () => {
    const { wrapper } = mountPage(SubscriptionsPage, {})

    expect(wrapper.text()).toContain('Подписок пока нет')
    expect(wrapper.text()).toContain('Следить')
  })

  it('«Отписаться» уводит на DELETE /me/events/{id}/follow', async () => {
    // Токена нет — как и выше: `load()` для гостя no-op, запрос делает кнопка.
    const fetchMock = vi.fn(async () => jsonResponse(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    const { wrapper, loyalty } = mountPage(SubscriptionsPage, { follows: [favoriteRow('01EV')] })

    await wrapper.find('button').trigger('click')
    await flushPromises()

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/me/events/01EV/follow')
    expect(init.method).toBe('DELETE')
    expect(loyalty.isFollowing('01EV')).toBe(false)
  })
})

describe('EventCard: сердце избранного', () => {
  const card = {
    id: 'internal-1',
    public_id: '01EV',
    slug: 'koncert',
    title: 'Концерт',
    status: 'published' as const,
    price_from_minor: 250000,
  }

  it('показывает заполненное сердце, когда мероприятие в избранном', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const loyalty = useLoyaltyStore()
    loyalty.favorites = [favoriteRow('01EV')]

    const router = createRouter({ history: createWebHashHistory(), routes: [] })
    const wrapper = mount(EventCard as never, {
      props: { event: card },
      global: { plugins: [pinia, router] },
    })

    const heart = wrapper.find('button[aria-pressed]')
    expect(heart.exists()).toBe(true)
    expect(heart.attributes('aria-pressed')).toBe('true')
    expect(heart.text()).toContain('♥')
  })

  it('без public_id сердца нет: внутренний id дал бы 404', () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const router = createRouter({ history: createWebHashHistory(), routes: [] })
    const wrapper = mount(EventCard as never, {
      props: { event: { ...card, public_id: undefined } },
      global: { plugins: [pinia, router] },
    })

    expect(wrapper.find('button[aria-pressed]').exists()).toBe(false)
  })

  it('гостя нажатие уводит на вход с возвратом на текущую страницу', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const router = createRouter({ history: createWebHashHistory(), routes: [] })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(EventCard as never, {
      props: { event: card },
      global: { plugins: [pinia, router] },
    })

    const push = vi.spyOn(router, 'push')
    await wrapper.find('button[aria-pressed]').trigger('click')
    await flushPromises()

    expect(push).toHaveBeenCalledWith({ path: '/login', query: { redirect: '/' } })
  })

  it('покупателю нажатие ставит отметку через API', async () => {
    setCustomerToken('customer-token')
    const fetchMock = vi.fn(async () =>
      jsonResponse({
        data: {
          id: '01FAV',
          event: card.public_id
            ? { id: card.public_id, title: card.title, slug: card.slug, poster: null, age_limit: null, starts_at: null }
            : null,
          created_at: '2026-10-14T10:00:00+00:00',
        },
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const pinia = createPinia()
    setActivePinia(pinia)
    const loyalty = useLoyaltyStore()

    const router = createRouter({ history: createWebHashHistory(), routes: [] })
    const wrapper = mount(EventCard as never, {
      props: { event: card },
      global: { plugins: [pinia, router] },
    })

    await wrapper.find('button[aria-pressed]').trigger('click')
    await flushPromises()

    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/me/events/01EV/favorite')
    expect(init.method).toBe('POST')
    expect(loyalty.isFavorite('01EV')).toBe(true)
  })
})
