/**
 * Афиша: единственный источник событий для виджетов витрины.
 *
 * Виджеты («Афиша», «Подборка», «Категории», «Отсчёт», «Цифры») показывают
 * одни и те же мероприятия. Если каждый тянет /events сам, страница делает
 * пять одинаковых запросов и пять раз мигает скелетоном — поэтому события
 * загружаются один раз на страницу и складываются сюда.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { get } from '@/lib/api'
import type { EventCard } from '@/lib/types'

export interface CatalogEvent extends EventCard {
  category?: { name?: string } | string | null
  venue?: { name?: string; city?: string } | string | null
  organization?: { name?: string } | null
}

export const useCatalogStore = defineStore('catalog', () => {
  const events = ref<CatalogEvent[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const loaded = ref(false)

  async function load(force = false): Promise<void> {
    if (loading.value) return
    if (loaded.value && !force) return

    loading.value = true
    error.value = null
    try {
      const res = await get<{ data: CatalogEvent[] }>('/events?status=published&per_page=100')
      // Ответ приходит и как {data:[...]}, и как вложенный envelope — терпим оба.
      const inner = res.data as unknown as { data?: CatalogEvent[] } | CatalogEvent[]
      events.value = Array.isArray(inner) ? inner : (inner as { data: CatalogEvent[] }).data ?? []
      loaded.value = true
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      events.value = []
    } finally {
      loading.value = false
    }
  }

  /** Категории, реально присутствующие в афише — «Категории» не выдумывает их. */
  const categories = computed(() => {
    const set = new Set<string>()
    for (const event of events.value) {
      const name = typeof event.category === 'string' ? event.category : event.category?.name
      if (name) set.add(name)
    }
    return [...set]
  })

  /** Ближайший сеанс по всем событиям — для виджета отсчёта. */
  const nextSession = computed(() => {
    let best: { event: CatalogEvent; startsAt: string } | null = null
    for (const event of events.value) {
      for (const session of event.sessions ?? []) {
        const startsAt = session.startsAt ?? session.starts_at ?? ''
        if (!startsAt) continue
        const time = new Date(startsAt).getTime()
        if (Number.isNaN(time) || time < Date.now()) continue
        if (!best || time < new Date(best.startsAt).getTime()) best = { event, startsAt }
      }
    }
    return best
  })

  /** Дата первого сеанса события — по ней сортируем афишу. */
  function startsAt(event: CatalogEvent): number {
    const raw = event.sessions?.[0]?.startsAt ?? event.sessions?.[0]?.starts_at ?? ''
    const time = new Date(raw).getTime()
    return Number.isNaN(time) ? Number.POSITIVE_INFINITY : time
  }

  const upcoming = computed(() =>
    [...events.value]
      .filter((e) => startsAt(e) !== Number.POSITIVE_INFINITY && startsAt(e) >= Date.now())
      .sort((a, b) => startsAt(a) - startsAt(b)),
  )

  function priceFrom(event: CatalogEvent): number {
    return event.price_from_minor ?? event.priceFromMinor ?? 0
  }

  function categoryName(event: CatalogEvent): string {
    return typeof event.category === 'string' ? event.category : (event.category?.name ?? '')
  }

  function venueName(event: CatalogEvent): string {
    if (typeof event.venue === 'string') return event.venue
    return event.venue?.name ?? event.city ?? ''
  }

  function venueCity(event: CatalogEvent): string {
    if (typeof event.venue === 'string') return ''
    return event.venue?.city ?? ''
  }

  return {
    events,
    loading,
    error,
    loaded,
    load,
    categories,
    nextSession,
    upcoming,
    startsAt,
    priceFrom,
    categoryName,
    venueName,
    venueCity,
  }
})
