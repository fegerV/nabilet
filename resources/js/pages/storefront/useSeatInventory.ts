/**
 * Загрузка инвентаря сессии и раскладка зала по секторам/рядам.
 *
 * Выделено из `SeatSelectionPage.vue` (P2). Страница совмещала три обязанности:
 * сетевую загрузку, построение схемы зала и работу с холдами. Здесь только
 * первое и второе — всё, что превращает ответ `GET /inventory` в то, что рисуют
 * карты. Логика холдов живёт в `useSeatHolds`.
 *
 * Композабл ничего не знает о разметке: страница решает, какую карту показать
 * (`useCoordMap`), и остаётся тонким оркестратором.
 */
import { computed, ref, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import { get } from '@/lib/api'
import {
  fetchInventory,
  MAX_TICKETS_PER_ORDER,
  type InventoryItem,
} from '@/lib/inventory'
import { seatStateFromStatus } from '@/lib/seatStatus'
import { trackEvent } from '@/lib/metrika'
import { dateFull, time } from '@/lib/format'
import type { Seat, Sector, Row } from '@/lib/hall'

/** Число из API-поля (строки вида "1", "150000"). NaN/пусто → fallback. */
export function toNumber(value: unknown, fallback = 0): number {
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

/** Номер ряда для показа: настоящий `hall_rows.number`, с откатом на `row_id`. */
export function rowNumberOf(item: InventoryItem): number {
  const raw = item.seat?.row_number
  return raw === undefined || raw === null || raw === ''
    ? toNumber(item.seat?.row_id)
    : toNumber(raw)
}

/** Номер места: `seats.number`. */
export function seatNumberOf(item: InventoryItem): number {
  return toNumber(item.seat?.number)
}

/** Имя сектора из схемы зала, с безопасным откатом. */
export function sectorNameOf(item: InventoryItem): string {
  const name = item.seat?.sector_name
  return name !== undefined && name !== null && String(name).trim() !== '' ? String(name) : 'Зал'
}

export function useSeatInventory() {
  const route = useRoute()
  const cart = useCartStore()

  /* Реальные места сессии из БД. */
  const inventory = ref<InventoryItem[]>([])
  const loading = ref(true)
  const loadError = ref<string | null>(null)
  /** Сервер сообщил больше мест, чем удалось загрузить (лимит страниц). */
  const truncated = ref(false)
  /** Всего мест на сервере (meta.total) — честный счётчик в шапке. */
  const totalSeats = ref(0)

  /**
   * Лимит билетов на заказ — С СЕРВЕРА (`meta.max_tickets_per_order`).
   *
   * Константа `MAX_TICKETS_PER_ORDER` здесь только фолбэк для старой сборки API.
   * Держать лимит на клиенте нельзя: пока он был захардкожен, снижение настройки
   * на сервере давало «кнопка нажимается, а запрос отвергается 422-м».
   */
  const maxTickets = ref(MAX_TICKETS_PER_ORDER)

  /**
   * С какой стороны полотна сцена (`meta.stage_side`).
   *
   * Сервер считает это по статичному объекту `kind: "stage"` схемы зала.
   * По местам направление не угадать: у «СурГУ» ряд 1 стоит внизу (у сцены),
   * у других залов — сверху, и «сцена всегда сверху» показывала её за спиной.
   */
  const stageSide = ref<'top' | 'bottom'>('top')

  /* Событие и сессия приходят из API (передаём через query от EventPage). */
  const eventTitle = ref('Мероприятие')
  const sessionStartsAt = ref('')
  const sessionHall = ref('')
  /**
   * Площадка сеанса. Раньше в подписи был только зал («Основной зал»), а это
   * ничего не говорит покупателю: у разных площадок основной зал свой. Название
   * площадки живёт на самом событии (`venue`), зала — на сеансе (`hall`).
   */
  const sessionVenue = ref('')
  const sessionVenueCity = ref('')

  /**
   * Загрузить инвентарь сессии. Метрика `seatmap_open` — цель воронки
   * (config/metrika.php), поэтому отправляется здесь же, а не на странице.
   */
  async function fetchSeats(sid: string): Promise<void> {
    const result = await fetchInventory(sid)

    inventory.value = result.items
    totalSeats.value = result.total || result.items.length
    truncated.value = result.truncated
    maxTickets.value = result.maxTicketsPerOrder
    stageSide.value = result.stageSide

    void trackEvent('seatmap_open').catch(() => {})
  }

  /**
   * Подтянуть заголовок события и данные сеанса по slug из URL. Не критично:
   * при сбое заголовки остаются дефолтными, схема зала уже загружена.
   *
   * ПОЧЕМУ СТАТИЧЕСКИЙ ИМПОРТ. Здесь стоял
   * `await import('@/lib/api').then((m) => m.get(...))`, и сборка ломала его
   * молча: `__vitePreload` поднимал `.then(D => D.aT)` вовнутрь обёртки, а
   * внешний `.then(h => h.get(url))` получал уже не модуль, а `{ data: … }` —
   * отсюда `TypeError: h.get is not a function`. Ошибка глоталась `catch`,
   * поэтому витрина показывала заголовок «Мероприятие» вместо названия
   * концерта и не сообщала никуда. `EventPage.vue` импортирует `get`
   * статически и работает; чанк `@/lib/api` грузится и так, поэтому
   * динамический импорт не экономил ничего.
   */
  async function loadEventContext(sid: string): Promise<void> {
    const slug = String(route.params.slug ?? '')
    if (!slug) return

    try {
      const { data } = await get<{
        title?: string
        venue?: { name?: string; city?: string } | string | null
        sessions?: Array<{ id: string; starts_at?: string; hall?: string }>
      }>(`/events/by-slug/${encodeURIComponent(slug)}`)

      const ev = data.sessions ?? []
      const s = ev.find((x) => String(x.id) === sid)

      eventTitle.value = data.title ?? eventTitle.value
      sessionStartsAt.value = s?.starts_at ?? ''
      sessionHall.value = s?.hall ?? ''

      const venue = data.venue
      sessionVenue.value = (typeof venue === 'string' ? venue : venue?.name) ?? ''
      sessionVenueCity.value = typeof venue === 'object' && venue !== null ? (venue.city ?? '') : ''
    } catch (e) {
      // Логируем, а не только молчим: ровно такая ошибка уже жила здесь
      // незамеченной — страница показывала «Мероприятие» вместо названия.
      console.error('[seats] контекст события не загрузился', e)
    }
  }

  const sessionLabel = computed(() => {
    const where = [sessionVenue.value, sessionHall.value].filter(Boolean).join(', ')
    const when = sessionStartsAt.value
      ? `${dateFull(sessionStartsAt.value)}, ${time(sessionStartsAt.value)}`
      : ''
    return [where, when].filter(Boolean).join(' · ')
  })

  /**
   * Схема зала: из реальных мест, сгруппированных по СЕКТОРУ и ряду.
   *
   * Раньше здесь был один сектор с именем «Зал» и `index: row_id` — покупатель
   * видел «ряд 13» вместо «ряд 1» и не видел секторов, хотя `sector_name` и
   * `hall_rows.number` приходят из API.
   */
  const hall = computed<Sector[]>(() => {
    // Только настоящие места: стоячие зоны рисует CoordSeatMap, а в рядной карте
    // они дали бы «ряд 0, место 0».
    const onlySeats = inventory.value.filter((i) => i.type === 'seat' && i.seat)

    const bySector = new Map<string, Map<number, InventoryItem[]>>()
    for (const item of onlySeats) {
      const sector = sectorNameOf(item)
      const rowId = toNumber(item.seat?.row_id)
      if (!bySector.has(sector)) bySector.set(sector, new Map())
      const rowsOfSector = bySector.get(sector)!
      if (!rowsOfSector.has(rowId)) rowsOfSector.set(rowId, [])
      rowsOfSector.get(rowId)!.push(item)
    }

    return [...bySector.entries()].map(([sectorName, rowsOfSector], sectorIdx) => {
      const rows: Row[] = [...rowsOfSector.entries()]
        .sort((a, b) => a[0] - b[0])
        .map(([, items]) => {
          const sorted = [...items].sort((a, b) => seatNumberOf(a) - seatNumberOf(b))
          // `index` — отображаемый НОМЕР ряда (1, 2, …), а не внутренний row_id.
          const displayRow = rowNumberOf(items[0])
          return {
            index: displayRow,
            seats: sorted.map((item) => {
              // Единый маппинг статусов (seatStatus.ts): available→free,
              // held/sold_out→held, sold→sold, blocked/disabled→unavailable.
              const selected = cart.isSelected(String(item.id))
              const heldByOther = cart.heldExternally.has(String(item.id)) && !selected
              const state = heldByOther
                ? ('held' as const)
                : seatStateFromStatus(item.status, selected, item.available_quantity)
              return {
                id: String(item.id),
                row: displayRow,
                number: seatNumberOf(item),
                state,
                priceMinor: toNumber(item.price_amount),
                kind: 'standard' as const,
              } satisfies Seat
            }),
            offset: 0,
          }
        })

      const prices = rows.flatMap((r) => r.seats.map((s) => s.priceMinor))

      return {
        id: `sector-${sectorIdx}`,
        name: sectorName,
        // «от N ₽» — минимальная цена сектора, а не цена первого места.
        priceMinor: prices.length > 0 ? Math.min(...prices) : 0,
        rows,
      }
    })
  })

  /**
   * Координатная карта — если есть standing-зоны ИЛИ у мест есть настоящие
   * координаты.
   *
   * Раньше условие требовало `x > 0`, поэтому зал, все места которого стоят в
   * колонке 0 (x = 0), ошибочно считался «схемой без координат» и падал в рядную
   * карту. Правильный дискриминатор: схема БЕЗ координат — это когда ВСЕ места в
   * (0,0); достаточно одного места вне начала координат.
   */
  const useCoordMap = computed(() => {
    if (inventory.value.some((i) => i.type === 'standing')) return true
    const seats = inventory.value.filter((i) => i.type === 'seat' && i.seat)
    if (seats.length === 0) return false
    return seats.some((i) => toNumber(i.seat?.x) !== 0 || toNumber(i.seat?.y) !== 0)
  })

  return {
    inventory: inventory as Ref<InventoryItem[]>,
    loading,
    loadError,
    truncated,
    totalSeats,
    maxTickets,
    stageSide,
    eventTitle,
    sessionStartsAt,
    sessionHall,
    sessionVenue,
    sessionVenueCity,
    sessionLabel,
    hall,
    useCoordMap,
    fetchSeats,
    loadEventContext,
  }
}
