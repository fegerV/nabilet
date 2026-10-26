/**
 * Действующая цена узла схемы зала на клиенте — зеркало `SchemaPrice`.
 *
 * ЗАЧЕМ ЭТОТ ФАЙЛ. На сервере приоритет цены принадлежит
 * `Venues\Domain\SchemaPrice` (и это стережёт `SchemaPriceSingleOwnerTest`), а
 * в редакторе та же цепочка была скопирована ещё трижды: два раза в
 * `schemaSerialization` и три раза в `HallEditorPage`. Копия на клиенте опаснее
 * серверной: её не видит ни один PHP-гейт, и расходится она молча — редактор
 * показал бы ноль там, где сервер продаёт по легаси-полю.
 *
 * ЧТО ЗДЕСЬ ПРОВЕРЯЕТСЯ
 *
 *   1. `priceMinor` побеждает легаси `price`, но `price` читается — старые
 *      схемы должны открываться с ценой, а не с нулём.
 *   2. Приоритет места: место → ряд (`rowPrices`) → сектор.
 *   3. Объявленный ноль — это ответ, а не «не задано».
 *   4. Индивидуальная цена места с мусором означает «своей цены нет»: место
 *      наследует ряд, а не продаётся за ноль.
 */
import { describe, expect, it } from 'vitest'
import {
  NODE_PRICE_KEYS,
  SEAT_PRICE_KEYS,
  effectiveSeatPrice,
  nodePriceMinor,
  nodePriceOrNull,
  seatPriceOverride,
} from '../../../resources/js/lib/hall'

describe('nodePriceMinor — два имени одного поля', () => {
  it('предпочитает priceMinor легаси-полю price', () => {
    expect(nodePriceMinor({ priceMinor: 500000, price: 100000 })).toBe(500000)
  })

  it('читает легаси price, когда priceMinor нет', () => {
    // Сидеры и импорт Афиши пишут то же число в `price`: это копейки, не рубли.
    expect(nodePriceMinor({ price: 250000 })).toBe(250000)
  })

  it('объявленный ноль не проваливается на легаси-поле', () => {
    expect(nodePriceMinor({ priceMinor: 0, price: 250000 })).toBe(0)
  })

  it('без поля цены отдаёт ноль', () => {
    expect(nodePriceMinor({})).toBe(0)
    expect(nodePriceMinor(null)).toBe(0)
  })

  it('отличает «цены нет» от нулевой цены', () => {
    // Для стоячей зоны `undefined` значит «поле не задано» и в payload не уедет,
    // а 0 уехал бы и был отвергнут сервером как непродаваемая зона.
    expect(nodePriceOrNull({})).toBeNull()
    expect(nodePriceOrNull({ priceMinor: 0 })).toBe(0)
  })

  it('терпит цифровую строку и отвергает мусор', () => {
    expect(nodePriceMinor({ priceMinor: '5000' })).toBe(5000)
    expect(nodePriceMinor({ priceMinor: ' 5000 ' })).toBe(5000)
    expect(nodePriceMinor({ priceMinor: '2300.00' })).toBe(0)
    expect(nodePriceMinor({ priceMinor: 'abc' })).toBe(0)
  })

  it('мусор в priceMinor проваливается на легаси-поле', () => {
    expect(nodePriceMinor({ priceMinor: 'abc', price: 250000 })).toBe(250000)
  })
})

describe('effectiveSeatPrice — место → ряд → сектор', () => {
  const sector = { priceMinor: 100000, rowPrices: { 2: 250000 } }

  it('цена места побеждает цену ряда', () => {
    expect(effectiveSeatPrice({ row: 2, priceMinor: 80000 }, sector)).toBe(80000)
  })

  it('цена ряда побеждает цену сектора', () => {
    expect(effectiveSeatPrice({ row: 2 }, sector)).toBe(250000)
    expect(effectiveSeatPrice({ row: 1 }, sector)).toBe(100000)
  })

  it('null у места означает «наследуй ряд», а не «ноль»', () => {
    expect(effectiveSeatPrice({ row: 2, priceMinor: null }, sector)).toBe(250000)
  })

  it('объявленный ноль у ряда — ответ, а не провал на сектор', () => {
    expect(effectiveSeatPrice({ row: 1 }, { priceMinor: 100000, rowPrices: { 1: 0 } })).toBe(0)
  })

  it('берёт легаси-цену сектора, если современных полей нет', () => {
    expect(effectiveSeatPrice({ row: 1 }, { price: 90000 })).toBe(90000)
  })

  it('без цен вообще отдаёт ноль, а не NaN', () => {
    expect(effectiveSeatPrice({ row: 1 }, {})).toBe(0)
  })
})

describe('seatPriceOverride — индивидуальная цена места', () => {
  it('берёт первое объявленное имя', () => {
    expect(seatPriceOverride({ priceMinor: 80000 })).toBe(80000)
    expect(seatPriceOverride({ price_amount: 70000 })).toBe(70000)
    expect(seatPriceOverride({ price: 60000 })).toBe(60000)
  })

  it('порядок имён одинаков во всех форматах', () => {
    // Раньше два места в schemaSerialization перечисляли имена в РАЗНОМ
    // порядке, и схема с обоими полями читалась по-разному.
    expect(seatPriceOverride({ priceMinor: 80000, price_amount: 70000, price: 60000 })).toBe(80000)
    expect(seatPriceOverride({ price_amount: 70000, price: 60000 })).toBe(70000)
  })

  it('некорректное значение означает «своей цены нет»', () => {
    // undefined, а не 0: место наследует цену ряда, а не продаётся бесплатно.
    expect(seatPriceOverride({ priceMinor: 0 })).toBeUndefined()
    expect(seatPriceOverride({ priceMinor: -5000 })).toBeUndefined()
    expect(seatPriceOverride({ priceMinor: null })).toBeUndefined()
    expect(seatPriceOverride({ priceMinor: 'дорого' })).toBeUndefined()
    expect(seatPriceOverride({})).toBeUndefined()
  })
})

describe('словари имён объявлены один раз', () => {
  it('имена поля цены узла', () => {
    expect([...NODE_PRICE_KEYS]).toEqual(['priceMinor', 'price'])
  })

  it('имена поля цены места — три, потому что два формата', () => {
    expect([...SEAT_PRICE_KEYS]).toEqual(['priceMinor', 'price_amount', 'price'])
  })
})
