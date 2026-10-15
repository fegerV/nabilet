/**
 * `lib/recommendations` — персональная полка «Рекомендуем для вас» (фаза 5).
 *
 * ЧТО ПРОВЕРЯЕТСЯ
 *   Форма ответа и границы: пустой список — это пустой список (а не ошибка),
 *   голая карточка без опциональных полей не роняет парсер, а `limit` попадает
 *   в строку запроса только когда он положительный. Сеть замокана так же, как в
 *   соседних тестах (`referral.test.ts`, `loyaltyLevel.test.ts`).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RECOMMENDATIONS_DEFAULT_LIMIT, fetchRecommendations } from '@/lib/recommendations'

function jsonResponse(body: unknown, init: { status?: number } = {}): Response {
  const status = init.status ?? 200
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(),
    json: async () => body,
  } as unknown as Response
}

beforeEach(() => {
  vi.unstubAllGlobals()
})

describe('lib/recommendations: разбор ответа', () => {
  it('читает /me/recommendations и нормализует карточки', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse({
        data: [
          {
            id: 'A'.repeat(26),
            title: 'Концерт «Секрет»',
            slug: 'koncert-sekret',
            poster: 'https://cdn.example/poster.jpg',
            age_limit: '12+',
            starts_at: '2026-11-01T19:00:00+05:00',
          },
        ],
      }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const list = await fetchRecommendations(4)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const url = String(fetchMock.mock.calls[0][0])
    expect(url).toContain('/me/recommendations')
    expect(url).toContain('limit=4')

    expect(list).toHaveLength(1)
    expect(list[0].title).toBe('Концерт «Секрет»')
    expect(list[0].id).toHaveLength(26)
    expect(list[0].starts_at).toBe('2026-11-01T19:00:00+05:00')
  })

  it('пустой ответ — пустой список, а не ошибка', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [] })))

    expect(await fetchRecommendations()).toEqual([])
  })

  it('терпит карточку без необязательных полей', async () => {
    // Ответ не по контракту не должен ронять экран: не хватает slug/poster —
    // это null, а не исключение.
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ data: [{ id: 'X', title: 'Y' }] })))

    const list = await fetchRecommendations()

    expect(list).toHaveLength(1)
    expect(list[0].title).toBe('Y')
    expect(list[0].slug).toBeNull()
    expect(list[0].poster).toBeNull()
  })

  it('неположительный limit не уходит в строку запроса', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    await fetchRecommendations(0)

    const url = String(fetchMock.mock.calls[0][0])
    expect(url).not.toContain('limit=')
  })

  it('по умолчанию просит полку разумного размера', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ data: [] }))
    vi.stubGlobal('fetch', fetchMock)

    await fetchRecommendations()

    const url = String(fetchMock.mock.calls[0][0])
    expect(url).toContain(`limit=${RECOMMENDATIONS_DEFAULT_LIMIT}`)
  })
})
