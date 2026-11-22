import { describe, expect, it } from 'vitest'
import { achievementCollection, type ClubSnapshot } from '@/lib/achievementsCatalog'

describe('club collection', () => {
  it('does not present missing server state as earned or zero progress', () => {
    const rows = achievementCollection(null)
    expect(rows).toHaveLength(15)
    expect(rows.every(a => a.status === 'unknown' && !a.progress && !a.earnedAt)).toBe(true)
  })
  it('reveals the secret only after a server-confirmed award and does not retain another account state', () => {
    const snapshot = { achievements: [{ id: 'nightlife', status: 'earned', hidden: false, progress: { current: 1, target: 1 }, earnedAt: '2026-11-01T18:00:00Z' }] } as ClubSnapshot
    expect(achievementCollection(snapshot).find(a => a.id === 'nightlife')?.hidden).toBe(false)
    expect(achievementCollection(null).find(a => a.id === 'nightlife')?.hidden).toBe(true)
  })
})
