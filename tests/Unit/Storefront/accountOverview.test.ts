import { beforeEach, describe, expect, it, vi } from 'vitest'
import { upcomingTickets } from '@/lib/accountOverview'
import { fetchMyOrdersPage, updateMe } from '@/lib/customer'
import type { TicketCard } from '@/lib/types'

beforeEach(() => { localStorage.clear(); vi.unstubAllGlobals() })
const ticket = (id: string, sessionAt: string, status = 'issued') => ({ id, sessionAt, status }) as TicketCard
describe('account overview', () => {
  it('finds the nearest future admission without offering refunded, past, or undated tickets', () => {
    const rows = [ticket('later', '2027-12-02'), ticket('past', '2025-01-01'), ticket('refund', '2027-11-01', 'refunded'), ticket('unknown', ''), ticket('nearest', '2027-12-01')]
    expect(upcomingTickets(rows, Date.parse('2027-11-20')).map(t => t.id)).toEqual(['nearest', 'later'])
    expect(rows[0].id).toBe('later')
  })
  it('retains pagination metadata so older orders remain reachable', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, headers: new Headers(), json: async () => ({ data: [{ id: 'older', order_number: 'NB-22', totals: { total_amount: 12000, currency: 'RUB' } }], meta: { current_page: 3, last_page: 4, total: 37 } }) }))
    vi.stubGlobal('fetch', fetchMock)
    const result = await fetchMyOrdersPage(3)
    expect(fetchMock.mock.calls[0][0]).toContain('page=3&per_page=10')
    expect(result).toMatchObject({ page: 3, lastPage: 4, total: 37, orders: [{ id: 'older', totalMinor: 12000 }] })
  })
  it('clears a removed phone instead of silently retaining the old number', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, status: 200, headers: new Headers(), json: async () => ({ data: { id: 'buyer', email: 'buyer@example.test', phone: null } }) }))
    vi.stubGlobal('fetch', fetchMock)
    await updateMe({ phone: '  ' })
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(JSON.parse(String(init.body))).toEqual({ phone: null })
  })
})
