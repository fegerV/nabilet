/**
 * `lib/raffles` — клиент розыгрышей «счастливый билет» (фаза 5).
 *
 * ЗДЕСЬ ПРОВЕРЯЮТСЯ ПРАВИЛА ФОРМЫ, А НЕ СЕТЬ
 *   Перевод ISO ↔ `datetime-local` и СПИСОК значений статусов — зеркало
 *   контракта сервера: расхождение означало бы «форма сохранила дату, которую
 *   сервер прочтёт иначе» или «форма предложила статус, которого нет». Сеть
 *   замокана так же, как в `referral.test.ts`.
 *
 * ПОДПИСИ И ЦВЕТА УЕХАЛИ К ВЛАДЕЛЬЦУ
 *   Здесь остались только значения контракта. Подписи и цвета статусов
 *   розыгрыша — в `lib/statusLabels.ts`, вместе с остальными словарями.
 *   Раньше в этом файле жил `raffleStatusClass()`, и тест на него
 *   (`expect(...).toContain('indigo')`) ЗАКРЕПЛЯЛ цвет из дефолтной палитры
 *   Tailwind — он рисуется, но обходит токены проекта, поэтому проверка была
 *   зелёной и защищала не то: правило палитры, а не «статус выглядит как
 *   статус».
 *
 * ОБЁРТКА ОТВЕТА
 *   Все функции читают конверт `{ data: … }`, но `list`-эндпоинты отдают свои
 *   строки под тем же ключом. Проверяем именно распаковку: молча вернуть
 *   `{ data: … }` вместо массива — ошибка, которая проявится пустым списком.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/lib/api'
import { findStatusMeta, statusOptions } from '@/lib/statusLabels'
import {
  RAFFLE_STATUSES,
  fetchMyRaffles,
  fetchRaffleResults,
  fromLocalInput,
  toLocalInput,
} from '@/lib/raffles'

function jsonResponse(body: unknown, init: { status?: number } = {}): Response {
  const status = init.status ?? 200
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(),
    json: async () => body,
  } as unknown as Response
}

/** Замоканный fetch: запоминает последний путь. */
function stubFetch(body: unknown, init: { status?: number } = {}) {
  const fetchMock = vi.fn(async () => jsonResponse(body, init))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

beforeEach(() => {
  vi.unstubAllGlobals()
})

describe('lib/raffles: даты формы', () => {
  it('ISO → datetime-local уходит в местное время формы', () => {
    // ISO-строка читается как момент; в input уходит ЛОКАЛЬНАЯ дата браузера.
    const local = toLocalInput('2026-10-14T12:00:00+00:00')
    const expected = new Date('2026-10-14T12:00:00+00:00')
    const pad = (n: number): string => String(n).padStart(2, '0')
    const stamp = `${expected.getFullYear()}-${pad(expected.getMonth() + 1)}-${pad(expected.getDate())}T${pad(expected.getHours())}:${pad(expected.getMinutes())}`
    expect(local).toBe(stamp)
  })

  it('пустая дата и мусор дают пустое поле', () => {
    expect(toLocalInput(null)).toBe('')
    expect(toLocalInput('')).toBe('')
    expect(toLocalInput('не дата')).toBe('')
  })

  it('форма → ISO и обратно не теряют день', () => {
    const iso = fromLocalInput('2026-10-14T21:30')
    expect(iso).not.toBeNull()
    expect(iso?.startsWith('2026-10-14T')).toBe(true)
    expect(toLocalInput(iso)).toBe('2026-10-14T21:30'.replace('21:30', String(new Date(iso!).getHours()).padStart(2, '0') + ':' + String(new Date(iso!).getMinutes()).padStart(2, '0')))
  })

  it('пустое поле формы → null: окно без границы', () => {
    expect(fromLocalInput('')).toBeNull()
    expect(fromLocalInput('ерунда')).toBeNull()
  })
})

describe('lib/raffles: словарь статусов', () => {
  it('значения контракта совпадают со словарём подписей', () => {
    // Список остался здесь, потому что это значения КОНТРАКТА (`ck_raffles_status`).
    // Подписи и цвета — у владельца. Если списки разойдутся, форма предложит
    // статус, которого бейдж не знает, и он покажется сырым кодом.
    expect(RAFFLE_STATUSES).toEqual(['draft', 'active', 'drawn', 'cancelled'])
    expect(statusOptions('raffle').map((option) => option.value)).toEqual([...RAFFLE_STATUSES])
  })

  it('каждый статус розыгрыша подписан, и `drawn` — цветом «получилось»', () => {
    // Раньше здесь стояло `expect(raffleStatusClass('drawn')).toContain('indigo')`.
    // `indigo` — цвет дефолтной палитры Tailwind: он рисуется, но «Разыгран» —
    // единственное ПОЛОЖИТЕЛЬНОЕ терминальное состояние — оставался
    // единственным синим элементом интерфейса. Тест закреплял это как норму.
    // Теперь проверяется тон из семантической палитры проекта.
    for (const status of RAFFLE_STATUSES) {
      expect(findStatusMeta('raffle', status), status).not.toBeNull()
    }

    expect(findStatusMeta('raffle', 'drawn')?.tone).toBe('brand')
    expect(findStatusMeta('raffle', 'cancelled')?.tone).toBe('rose')
    expect(findStatusMeta('raffle', 'active')?.tone).toBe('mint')
  })
})

describe('lib/raffles: запросы', () => {
  it('fetchMyRaffles читает /me/raffles и распаковывает data', async () => {
    const fetchMock = stubFetch({
      data: [
        {
          public_id: 'RAF1',
          title: 'Счастливый билет октября',
          description: null,
          status: 'active',
          starts_at: '2026-10-01T00:00:00+00:00',
          ends_at: null,
          drawn_at: null,
          entries_count: 5,
          prizes: [{ place: 1, title: 'Билет на любое мероприятие' }],
          won: [],
        },
      ],
    })

    const mine = await fetchMyRaffles()

    expect(fetchMock.mock.calls[0]?.[0]).toContain('/me/raffles')
    expect(mine).toHaveLength(1)
    expect(mine[0]?.title).toBe('Счастливый билет октября')
    expect(mine[0]?.prizes[0]?.place).toBe(1)
    expect(mine[0]?.won).toEqual([])
  })

  it('fetchMyRaffles без участий отдаёт пустой список', async () => {
    // Пустой кабинет — не ошибка: покупатель просто ещё не участвовал.
    stubFetch({ data: [] })
    expect(await fetchMyRaffles()).toEqual([])
  })

  it('fetchRaffleResults читает /raffles/{id} с масками победителей', async () => {
    const fetchMock = stubFetch({
      data: {
        public_id: 'RAF1',
        title: 'Счастливый билет октября',
        description: null,
        status: 'drawn',
        starts_at: null,
        ends_at: null,
        drawn_at: '2026-10-15T10:00:00+00:00',
        entries_count: 12,
        prizes: [
          {
            place: 1,
            title: 'Билет на любое мероприятие',
            description: null,
            winner: { display: 'Иван П.', notified: true },
          },
        ],
      },
    })

    const results = await fetchRaffleResults('RAF1')

    expect(fetchMock.mock.calls[0]?.[0]).toContain('/raffles/RAF1')
    expect(results.entries_count).toBe(12)
    expect(results.prizes[0]?.winner?.display).toBe('Иван П.')
    expect(results.prizes[0]?.winner?.notified).toBe(true)
  })

  it('404 черновика превращается в ApiError со статусом', async () => {
    // Страница отличия черновика от настоящего не делает: 404 — это
    // «этой страницы нет», и экран строит по нему пустое состояние.
    stubFetch({ message: 'Not found', code: 'NOT_FOUND' }, { status: 404 })

    await expect(fetchRaffleResults('DRAFT1')).rejects.toBeInstanceOf(ApiError)
    await expect(fetchRaffleResults('DRAFT1')).rejects.toMatchObject({ status: 404 })
  })
})
