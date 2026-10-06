/**
 * Инварианты корзины витрины: БИЛЕТЫ и ПОЗИЦИИ — разные числа.
 *
 * Тесты сторожат именно те дефекты, которые уже случались в проде:
 *
 *  1. Сводка считала `seats.length` вместо суммы `quantity`, поэтому танцпол
 *     с qty = 3 показывался как «1 билет», а итог расходился с
 *     `orders.total_amount` на чеке.
 *  2. Восстановление после F5 (`applyServerCart` / `restoreCart`) не переносило
 *     `quantity` из серверной корзины — количество билетов терялось молча.
 *  3. Стоячая зона приходила с `row = 0, number = 0`, и сводка печатала
 *     «Танцпол, место 0» — место, которого не существует.
 *
 * Если эти тесты падают — сломался контракт «одна позиция ≠ один билет»,
 * а вместе с ним и доверие покупателя к сумме на экране.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import {
  seatLineLabel,
  seatLineTotal,
  seatQuantity,
  seatsTicketCount,
  useCartStore,
  type CartSeat,
} from '@/stores/cart'

function seat(overrides: Partial<CartSeat> = {}): CartSeat {
  return {
    id: '1',
    sector: 'Партер',
    row: 3,
    number: 12,
    priceMinor: 150000,
    kind: 'standard',
    ...overrides,
  }
}

describe('Помощники позиции', () => {
  it('обычное место — один билет', () => {
    expect(seatQuantity(seat())).toBe(1)
    expect(seatLineTotal(seat())).toBe(150000)
  })

  it('стоячая зона — столько билетов, сколько выбрано', () => {
    const dance = seat({ id: 'dance', sector: 'Танцпол', row: 0, number: 0, priceMinor: 80000, quantity: 3 })
    expect(seatQuantity(dance)).toBe(3)
    expect(seatLineTotal(dance)).toBe(240000)
  })

  it('битый quantity не ломает сумму (падает на 1)', () => {
    expect(seatQuantity({ quantity: 0 })).toBe(1)
    expect(seatQuantity({ quantity: -5 })).toBe(1)
    expect(seatQuantity({ quantity: Number.NaN })).toBe(1)
    expect(seatQuantity({})).toBe(1)
  })

  it('подпись стоячей зоны говорит о билетах, а не о «месте 0»', () => {
    const dance = seat({ sector: 'Танцпол', row: 0, number: 0, quantity: 3 })
    expect(seatLineLabel(dance)).toBe('Танцпол · 3 билета')
    expect(seatLineLabel(dance)).not.toContain('место 0')
  })

  it('подпись обычного места — сектор, ряд, место', () => {
    expect(seatLineLabel(seat())).toBe('Партер, ряд 3, место 12')
  })

  it('подпись танцпола с одним билетом — без дублирования количества', () => {
    expect(seatLineLabel(seat({ sector: 'Танцпол', row: 0, number: 0, quantity: 1 }))).toBe('Танцпол')
  })

  it('билеты в наборе позиций суммируются по quantity', () => {
    const seats = [seat(), seat({ id: 'dance', row: 0, number: 0, quantity: 4 })]
    expect(seatsTicketCount(seats)).toBe(5)
    expect(seats.length).toBe(2) // позиций — две, билетов — пять
  })
})

describe('Стор корзины: билеты против позиций', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('count считает позиции, ticketsCount — билеты', () => {
    const cart = useCartStore()
    cart.toggle(seat({ id: 'seat-1' }))
    cart.toggle(seat({ id: 'dance', sector: 'Танцпол', row: 0, number: 0, priceMinor: 80000, quantity: 3 }))

    expect(cart.count).toBe(2)
    expect(cart.ticketsCount).toBe(4)
  })

  it('subtotalMinor учитывает количество билетов в позиции', () => {
    const cart = useCartStore()
    cart.toggle(seat({ id: 'seat-1', priceMinor: 150000 }))
    cart.toggle(seat({ id: 'dance', sector: 'Танцпол', row: 0, number: 0, priceMinor: 80000, quantity: 3 }))

    // 150000 + 80000 * 3 = 390000, а не 230000 «за две позиции».
    expect(cart.subtotalMinor).toBe(390000)
    expect(cart.totalMinor).toBe(390000)
  })

  it('hydrate после F5 сохраняет количество билетов', () => {
    const cart = useCartStore()
    cart.hydrate([
      seat({ id: 'seat-1' }),
      seat({ id: 'dance', sector: 'Танцпол', row: 0, number: 0, priceMinor: 80000, quantity: 3 }),
    ])

    expect(cart.count).toBe(2)
    expect(cart.ticketsCount).toBe(4)
    expect(cart.subtotalMinor).toBe(150000 + 80000 * 3)
  })

  it('снятие выбора убирает и билеты, и позиции', () => {
    const cart = useCartStore()
    const dance = seat({ id: 'dance', sector: 'Танцпол', row: 0, number: 0, priceMinor: 80000, quantity: 3 })
    cart.toggle(dance)
    cart.toggle(dance)

    expect(cart.count).toBe(0)
    expect(cart.ticketsCount).toBe(0)
    expect(cart.subtotalMinor).toBe(0)
  })

  it('промокод не может увести итог в минус', () => {
    const cart = useCartStore()
    cart.toggle(seat({ id: 'seat-1', priceMinor: 100000 }))
    cart.applyPromo('FREE', 999999)

    expect(cart.discountMinor).toBe(100000)
    expect(cart.totalMinor).toBe(0)
  })
})
