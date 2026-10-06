/**
 * Лимит билетов на заказ приходит С СЕРВЕРА, а не из константы клиента.
 *
 * Сторожится конкретный дефект: лимит был объявлен в трёх местах
 * (`NABILET_MAX_SEATS_PER_ORDER=8` в `.env` — не читался никем;
 * `nabilet.checkout.max_items_per_order` — ключ конфига не читался вовсе;
 * литерал `max:10` в `CartController::addItem` — единственное, что работало).
 * Оператор правил настройку и не получал эффекта: в конфиге 8, система
 * пропускает 10.
 *
 * После правки источник один — `CHECKOUT_MAX_ITEMS`. Сервер отдаёт его витрине
 * в `meta.max_tickets_per_order`, и клиент обязан использовать именно это число:
 * иначе снижение лимита на сервере снова превратится в «кнопка нажимается,
 * а запрос отвергается 422-м».
 *
 * Проверено на живом стенде (`CHECKOUT_MAX_ITEMS=3`):
 *   meta.max_tickets_per_order → 3;  quantity=3 → 201;  quantity=4 → 422.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchInventory, MAX_TICKETS_PER_ORDER } from '@/lib/inventory'

function stubInventory(meta: Record<string, unknown>, items: unknown[] = []): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () =>
      new Response(JSON.stringify({ data: items, meta }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    ),
  )
}

const PAGE = { current_page: 1, per_page: 500, total: 0, last_page: 1 }

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('fetchInventory: лимит билетов на заказ', () => {
  it('берёт лимит из meta.max_tickets_per_order', async () => {
    stubInventory({ ...PAGE, max_tickets_per_order: 3 })

    const result = await fetchInventory(6)

    expect(result.maxTicketsPerOrder).toBe(3)
  })

  it('лимит 1 и 100 принимаются как есть (не зажимаются константой)', async () => {
    stubInventory({ ...PAGE, max_tickets_per_order: 1 })
    expect((await fetchInventory(6)).maxTicketsPerOrder).toBe(1)

    stubInventory({ ...PAGE, max_tickets_per_order: 100 })
    expect((await fetchInventory(6)).maxTicketsPerOrder).toBe(100)
  })

  it('падает на фолбэк-константу, если поля нет (старая сборка API)', async () => {
    stubInventory(PAGE)

    const result = await fetchInventory(6)

    expect(result.maxTicketsPerOrder).toBe(MAX_TICKETS_PER_ORDER)
  })

  it('игнорирует мусор в поле и берёт фолбэк', async () => {
    for (const bad of [0, -5, 'abc', null, undefined, Number.NaN]) {
      stubInventory({ ...PAGE, max_tickets_per_order: bad })
      expect((await fetchInventory(6)).maxTicketsPerOrder).toBe(MAX_TICKETS_PER_ORDER)
    }
  })

  it('дробное значение приводится к целому вниз', async () => {
    stubInventory({ ...PAGE, max_tickets_per_order: 7.9 })

    expect((await fetchInventory(6)).maxTicketsPerOrder).toBe(7)
  })

  it('пагинация не сбрасывает лимит, полученный на первой странице', async () => {
    const pages = [
      { data: [{ id: 1 }], meta: { ...PAGE, total: 2, last_page: 2, max_tickets_per_order: 4 } },
      { data: [{ id: 2 }], meta: { current_page: 2, per_page: 500, total: 2, last_page: 2 } },
    ]
    let call = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        new Response(JSON.stringify(pages[call++]), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    )

    const result = await fetchInventory(6)

    expect(result.items).toHaveLength(2)
    expect(result.maxTicketsPerOrder).toBe(4)
  })
})
