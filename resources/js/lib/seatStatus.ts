/**
 * Единый маппинг статусов инвентаря → визуальное состояние места.
 *
 * Единственный источник правды для обеих карт (SeatMap и CoordSeatMap):
 * раньше каждая выводила состояние сама, и карты расходились — например,
 * сервер при полном холде ставит inventory_items.status = 'sold_out'
 * (CartService::addItem), а не 'held', и одна карта показывала такое место
 * «недоступным», а другая — вообще свободным и кликабельным.
 *
 * Статусы бэкенда: available | held | sold_out | sold | blocked | disabled
 * (+ любые будущие — по умолчанию «недоступно», безопасная сторона).
 */
import type { SeatState } from './types'

/** Статусы, которые позволяют взять место в корзину (холд). */
const PICKABLE_STATUSES: ReadonlySet<string> = new Set(['available'])

export function isHeldStatus(status: string): boolean {
  return status === 'held' || status === 'sold_out'
}

/** Визуальное состояние места по статусу inventory-элемента. */
export function seatStateFromStatus(
  status: string,
  selected: boolean,
  availableQuantity?: number | null,
): SeatState {
  if (selected) return 'selected'
  switch (status) {
    case 'available':
      // Страховка: quantity 0 при статусе available — всё равно не берём.
      return availableQuantity !== undefined && Number(availableQuantity) < 1 ? 'unavailable' : 'free'
    case 'held':
    case 'sold_out':
      return 'held'
    case 'sold':
      return 'sold'
    default:
      // blocked / disabled / неизвестный статус — недоступно.
      return 'unavailable'
  }
}

/** Можно ли предложить место к выбору (клик отправит холд на сервер). */
export function isSeatPickable(status: string, availableQuantity?: number | null): boolean {
  if (!PICKABLE_STATUSES.has(status)) return false
  return availableQuantity === undefined || Number(availableQuantity) >= 1
}

/** Человекочитаемое состояние для aria-label / подсказок. */
export function seatStatusLabel(state: SeatState): string {
  switch (state) {
    case 'selected':
      return 'выбрано, нажмите чтобы убрать'
    case 'held':
      return 'держит другой покупатель'
    case 'sold':
      return 'продано'
    case 'unavailable':
      return 'недоступно'
    case 'vip':
      return 'VIP, свободно'
    case 'accessible':
      return 'для маломобильных, свободно'
    default:
      return 'свободно'
  }
}
