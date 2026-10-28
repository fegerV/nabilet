import { describe, it, expect } from 'vitest'
import { localDateKey, revenueByDay } from '@/lib/stats'

/**
 * Выручка по дням — ЛОКАЛЬНЫЕ сутки, а не UTC-дата.
 *
 * ПОЧЕМУ ЭТОТ ТЕСТ ВАЖЕН. До выноса `AdminAnalyticsPage` бакетировала по
 * `toISOString().slice(0, 10)` — UTC-дате. Для браузера восточнее UTC (все
 * российские) локальная полночь уезжает на предыдущие сутки, и заказ «вчера
 * днём» вставал на СЕГОДНЯШНИЙ столбик, а «сегодня днём» вовсе терялся.
 * На машине разработчика в UTC расхождения не видно, поэтому защита — явные
 * локальные компоненты (`localDateKey`) плюс проверка «заказ не съезжает».
 *
 * ОГОВОРКА ПРО ЧАСОВОЙ ПОЯС: тест «не съезжает» ловит прежний баг только на
 * машине не в UTC (здесь — GMT+5, как у пользователя). Поэтому `localDateKey`
 * дополнительно проверяется на независимость от пояса: он строится из локальных
 * компонент и на UTC-машине даёт тот же ответ.
 */

describe('localDateKey', () => {
  it('берёт ЛОКАЛЬНЫЙ день из компонент, а не UTC-дату', () => {
    // Оба времени — один и тот же ЛОКАЛЬНЫЙ день 28 октября.
    expect(localDateKey(new Date(2026, 9, 28, 0, 30))).toBe('2026-10-28')
    expect(localDateKey(new Date(2026, 9, 28, 23, 30))).toBe('2026-10-28')
  })
})

describe('revenueByDay', () => {
  it('заказ прошлого дня не съезжает на сегодняшний столбик', () => {
    const now = new Date()
    const yesterdayNoon = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1, 12, 0, 0)

    const buckets = revenueByDay([{ at: yesterdayNoon.toISOString(), total: 1500 }], {
      days: 3,
      timestamp: (r) => r.at,
      amount: (r) => r.total,
      label: () => '',
    })

    const yKey = localDateKey(yesterdayNoon)
    const todayKey = localDateKey(new Date(now.getFullYear(), now.getMonth(), now.getDate()))

    // Заказ попал в СВОИ сутки…
    expect(buckets.find((b) => b.date === yKey)?.total).toBe(1500)
    // …и НЕ в сегодняшние (на UTC+ это ровно то, что ломалось).
    expect(buckets.find((b) => b.date === todayKey)?.total).toBe(0)
  })

  it('суммирует и считает строки внутри суток', () => {
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 15, 0, 0)

    const buckets = revenueByDay(
      [
        { at: today.toISOString(), total: 100 },
        { at: today.toISOString(), total: 250 },
      ],
      { days: 2, timestamp: (r) => r.at, amount: (r) => r.total, label: () => '' },
    )

    const bucket = buckets.find((b) => b.date === localDateKey(today))
    expect(bucket?.total).toBe(350)
    expect(bucket?.count).toBe(2)
  })

  it('строки вне окна и битые даты отбрасываются', () => {
    const now = new Date()
    const longAgo = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 40, 12, 0, 0)

    const buckets = revenueByDay(
      [
        { at: longAgo.toISOString(), total: 999 },
        { at: '', total: 500 },
        { at: 'not-a-date', total: 500 },
      ],
      { days: 7, timestamp: (r) => r.at, amount: (r) => r.total, label: () => '' },
    )

    expect(buckets.reduce((s, b) => s + b.total, 0)).toBe(0)
    expect(buckets.length).toBe(7)
  })
})
