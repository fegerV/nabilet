/**
 * Операции над рядами и местами в редакторе залов.
 *
 * Зал правят рядами: «сдвинуть ряд», «удалить ряд 2», «сделать ряд VIP» —
 * обычные действия при переносе схемы с бумаги. Каждая функция здесь хранит
 * инвариант, который легко сломать незаметно, а стоит он дорого:
 *
 *  - удаление ряда ПЕРЕНУМЕРОВЫВАЕТ последующие. Дыра в нумерации «ряд 1, 3»
 *    уезжает в билеты: на билете напечатано «ряд 3», а на схеме он второй;
 *  - цены рядов переезжают вместе с рядами (иначе после удаления первого ряда
 *    VIP-цена достаётся бывшему второму ряду);
 *  - перенумерация мест идёт слева направо и не создаёт пропусков;
 *  - выравнивание НЕ схлопывает разные места в одну точку незаметно для
 *    вызывающего (распределение сохраняет порядок и равный шаг).
 */
import { describe, expect, it } from 'vitest'
import type { ESector } from '../../../resources/js/pages/hall-editor/editorTypes'
import {
  alignSeats,
  applySeatStep,
  buildGridSeats,
  deleteRow,
  distributeSeats,
  insertRowAfter,
  renumberAllSeats,
  rowNumbers,
  seatsInRow,
} from '../../../resources/js/pages/hall-editor/seatGeometry'

let counter = 0
const nextId = (prefix: string) => `${prefix}-${(counter += 1)}`

function gridSector(rows: number, seatsPerRow: number): ESector {
  const sector: ESector = {
    id: 'sec',
    name: 'Зал',
    priceMinor: 100000,
    x: 0,
    y: 0,
    seats: [],
    rowPrices: {},
    shape: 'grid',
    arcSpread: 160,
    arcBaseR: 0,
    arcRowGap: 0,
    arcOffsetX: 0,
    arcOffsetY: 0,
  }
  sector.seats = buildGridSeats(rows, seatsPerRow, 0, nextId)
  return sector
}

describe('rowNumbers / seatsInRow', () => {
  it('перечисляет ряды по порядку и не зависит от порядка мест в массиве', () => {
    const sector = gridSector(3, 4)
    sector.seats.reverse()

    expect(rowNumbers(sector)).toEqual([1, 2, 3])
    expect(seatsInRow(sector, 2).map((s) => s.number)).toEqual([1, 2, 3, 4])
  })
})

describe('deleteRow', () => {
  it('удаляет ровно места указанного ряда', () => {
    const sector = gridSector(3, 5)
    const removed = deleteRow(sector, 2)

    expect(removed).toBe(5)
    expect(sector.seats).toHaveLength(10)
    expect(rowNumbers(sector)).toEqual([1, 2])
  })

  it('перенумеровывает последующие ряды, чтобы не осталось дыры', () => {
    const sector = gridSector(4, 3)
    deleteRow(sector, 2)

    // Бывший ряд 3 стал рядом 2, бывший 4 — рядом 3.
    expect(rowNumbers(sector)).toEqual([1, 2, 3])
    expect(sector.seats.filter((s) => s.row === 2)).toHaveLength(3)
  })

  it('переносит цены рядов вместе с рядами, не теряя VIP там, где он был', () => {
    const sector = gridSector(3, 2)
    sector.rowPrices = { 1: 500000, 2: 300000, 3: 100000 }

    deleteRow(sector, 1)

    // Цена удалённого ряда уходит, остальные сдвигаются вверх.
    expect(sector.rowPrices).toEqual({ 1: 300000, 2: 100000 })
  })

  it('на несуществующем ряде ничего не меняет и возвращает 0', () => {
    const sector = gridSector(2, 2)
    const before = sector.seats.length

    expect(deleteRow(sector, 99)).toBe(0)
    expect(sector.seats.length).toBe(before)
    expect(rowNumbers(sector)).toEqual([1, 2])
  })
})

describe('insertRowAfter', () => {
  it('вставляет ряд ниже указанного и сдвигает последующие вниз', () => {
    const sector = gridSector(2, 3)
    const created = insertRowAfter(sector, 1, 3, nextId)

    expect(created).toBe(2)
    // Были ряды 1,2 → стало 1,2(новый),3. Мест: 3+3+3.
    expect(rowNumbers(sector)).toEqual([1, 2, 3])
    expect(sector.seats).toHaveLength(9)
  })

  it('новый ряд получает столько мест, сколько запрошено', () => {
    const sector = gridSector(2, 2)
    insertRowAfter(sector, 1, 7, nextId)

    expect(seatsInRow(sector, 2)).toHaveLength(7)
  })

  it('сдвигает цены рядов вниз, сохраняя их привязку к ряду', () => {
    const sector = gridSector(3, 2)
    sector.rowPrices = { 1: 100000, 2: 200000, 3: 300000 }

    insertRowAfter(sector, 1, 2, nextId)

    // Ряд 2 (цена 200000) уехал на 3, ряд 3 — на 4; у нового ряда цены нет.
    expect(sector.rowPrices).toEqual({ 1: 100000, 3: 200000, 4: 300000 })
  })

  it('сохраняет нумерацию мест внутри нового ряда', () => {
    const sector = gridSector(1, 1)
    insertRowAfter(sector, 1, 4, nextId)

    expect(seatsInRow(sector, 2).map((s) => s.number)).toEqual([1, 2, 3, 4])
  })
})

describe('renumberAllSeats', () => {
  it('нумерует места слева направо, закрывая пропуски', () => {
    const sector = gridSector(1, 4)
    // Испорченная нумерация: 7, 3, 99, 5 при правильных позициях.
    const row = seatsInRow(sector, 1)
    row[0].number = 7
    row[1].number = 3
    row[2].number = 99
    row[3].number = 5

    const changed = renumberAllSeats(sector)

    expect(changed).toBe(1)
    expect(seatsInRow(sector, 1).map((s) => s.number)).toEqual([1, 2, 3, 4])
  })

  it('возвращает 0, если нумерация уже верна — вызывающий не плодит лишний шаг истории', () => {
    const sector = gridSector(3, 4)
    expect(renumberAllSeats(sector)).toBe(0)
  })
})

describe('applySeatStep', () => {
  it('пересобирает сетку под новый шаг', () => {
    const sector = gridSector(2, 3)
    const ok = applySeatStep(sector, 30, 40)

    expect(ok).toBe(true)
    const first = seatsInRow(sector, 1)[0]
    const second = seatsInRow(sector, 1)[1]
    expect(second.x - first.x).toBe(30)
    const row2 = seatsInRow(sector, 2)[0]
    expect(row2.y - first.y).toBe(40)
  })

  it('отказывается работать с дугой и столом (шаг задан радиусом)', () => {
    const sector = gridSector(2, 2)
    sector.shape = 'arc'
    expect(applySeatStep(sector, 30, 40)).toBe(false)

    sector.shape = 'table'
    expect(applySeatStep(sector, 30, 40)).toBe(false)
  })
})

describe('alignSeats', () => {
  it('прижимает к левому краю по X', () => {
    const seats = [{ x: 10, y: 0 }, { x: 50, y: 20 }, { x: 30, y: 40 }]
    expect(alignSeats(seats, 'left')).toBe(true)
    expect(seats.map((s) => s.x)).toEqual([10, 10, 10])
  })

  it('выравнивает по центру, сохраняя разброс по другой оси', () => {
    const seats = [{ x: 0, y: 5 }, { x: 100, y: 15 }]
    alignSeats(seats, 'center-x')
    expect(seats.map((s) => s.x)).toEqual([50, 50])
    expect(seats.map((s) => s.y)).toEqual([5, 15])
  })

  it('прижимает к нижнему краю по Y', () => {
    const seats = [{ x: 0, y: 10 }, { x: 0, y: 90 }]
    alignSeats(seats, 'bottom')
    expect(seats.map((s) => s.y)).toEqual([90, 90])
  })

  it('отказывается выравнивать одну точку — выравнивать не с чем', () => {
    expect(alignSeats([{ x: 1, y: 1 }], 'left')).toBe(false)
  })
})

describe('distributeSeats', () => {
  it('расставляет места с равным шагом между крайними', () => {
    const seats = [{ x: 0, y: 0 }, { x: 10, y: 0 }, { x: 100, y: 0 }]
    expect(distributeSeats(seats, 'x')).toBe(true)
    expect(seats.map((s) => s.x)).toEqual([0, 50, 100])
  })

  it('не трогает порядок по другой оси', () => {
    const seats = [{ x: 0, y: 7 }, { x: 50, y: 3 }, { x: 100, y: 9 }]
    distributeSeats(seats, 'x')
    expect(seats.map((s) => s.y)).toEqual([7, 3, 9])
  })

  it('требует минимум три точки: две уже «распределены»', () => {
    expect(distributeSeats([{ x: 0, y: 0 }, { x: 10, y: 0 }], 'x')).toBe(false)
  })
})
