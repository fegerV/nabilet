import { describe, expect, it } from 'vitest'
import { purchaseAchievements } from '@/lib/achievements'

describe('достижения по подтверждённым покупкам', () => {
  it('открывает награды только после достижения порога', () => {
    expect(purchaseAchievements(0).every(item => !item.earned)).toBe(true)
    expect(purchaseAchievements(5).map(item => item.earned)).toEqual([true, true, false])
    expect(purchaseAchievements(15).map(item => item.progress)).toEqual([1, 5, 10])
  })
  it('пересчитывает прогресс после возврата и не доверяет нечисловому значению', () => {
    expect(purchaseAchievements(4)[1].earned).toBe(false)
    expect(purchaseAchievements(NaN)[0].progress).toBe(0)
    expect(purchaseAchievements(-1)[0].earned).toBe(false)
  })
})
