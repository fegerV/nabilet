import catalog from '../../data/club-achievements.json'
import { get } from './api'
import type { LoyaltyLevel } from './loyalty'

export type AchievementCategory = 'visits' | 'music' | 'theatre' | 'discovery' | 'season'
export interface Achievement {
  id: string
  name: string
  category: AchievementCategory
  metric: string
  target: number
  subtitle: string
  description: string
  story: string
  rarity: string
  unit: string
  reward: string
  hidden: boolean
  status: 'earned' | 'progress' | 'hidden' | 'unknown'
  progress?: { current: number; target: number }
  earnedAt?: string | null
}
export interface ClubSnapshot {
  achievements: Pick<Achievement, 'id' | 'status' | 'progress' | 'earnedAt' | 'hidden'>[]
  visits: number
  levels: LoyaltyLevel[]
  rules: {
    accrual_percent: number
    spend_cap_percent: number
    lifetime_months: number
    early_access_enabled: boolean
    early_access_hours: number
    early_access_min_level_slug: string
  }
}
export const CATEGORY_LABEL: Record<AchievementCategory, string> = {
  visits: 'Посещения', music: 'Музыка', theatre: 'Театр', discovery: 'Открытия', season: 'Особые',
}
export const ACHIEVEMENTS: Achievement[] = catalog.map(item => ({
  ...item, category: item.category as AchievementCategory, status: 'unknown',
}))
export function achievementCollection(snapshot: ClubSnapshot | null): Achievement[] {
  return ACHIEVEMENTS.map(item => {
    const state = snapshot?.achievements.find(row => row.id === item.id)
    return state ? { ...item, ...state } : { ...item }
  })
}
export function achievementImage(id: string): string {
  return `/images/club/achievements/${id}.webp`
}
export async function fetchClub(): Promise<ClubSnapshot> {
  const response = await get<ClubSnapshot>('/me/achievements')
  if (!response.data || !Array.isArray(response.data.achievements) || !response.data.rules) {
    throw new Error('Invalid club response')
  }
  return response.data
}
