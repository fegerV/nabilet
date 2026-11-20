import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { embedSend } from '@/lib/embed-api'

/** Embed-транспорт: префикс, токен из адреса и capability-заголовок корзины. */
describe('embed API transport', () => {
  beforeEach(() => {
    window.history.replaceState({}, '', '/event/x?embed_token=nbem_secret')
    localStorage.removeItem('nabilet_cart_token')
    sessionStorage.clear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('prefixes the path and appends embed_token', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { id: 'c1' } }), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await embedSend('/carts', 'POST', { session_id: '1' })

    const url = String(fetchMock.mock.calls[0]?.[0])
    expect(url).toContain('/api/v1/embed/carts')
    expect(url).toContain('embed_token=nbem_secret')
    const init = fetchMock.mock.calls[0]?.[1] as RequestInit
    const headers = init.headers as Record<string, string>
    expect(headers['X-Cart-Token']).toBeTruthy()
  })

  it('forwards the idempotency header', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: {} }), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    await embedSend('/orders/01J/payment', 'POST', undefined, { 'Idempotency-Key': 'pay_123' })

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toBe('pay_123')
  })
})
