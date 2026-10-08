/**
 * Форма сеанса: превращение формы в тело запроса.
 *
 * Повод — жалоба «на странице мероприятия нет выбора площадки, назначения даты
 * и времени». Площадка и дата живут в сеансах, поэтому появился общий
 * `SessionFormModal`, и всё, что в нём можно сломать незаметно, собрано в
 * `buildSessionPayload()`.
 *
 * Тесты проверяют именно НЕЗАМЕТНЫЕ ошибки, а не «функция вернула объект»:
 * пустая строка вместо отсутствующего ключа, строка вместо числа, «дата без
 * времени» и забытый `venue_id`.
 */
import { describe, expect, it } from 'vitest'
import {
  buildSessionPayload,
  emptySessionForm,
  joinDateTime,
  splitDateTime,
} from '@/lib/sessionForm'

/** Готовая форма: дальше каждый тест ломает ровно одно поле. */
function readyForm(overrides: Record<string, string> = {}) {
  return emptySessionForm({
    event_id: '7',
    venue_id: '3',
    hall_id: '12',
    starts_at: '2026-11-20',
    starts_time: '19:00',
    ...overrides,
  })
}

describe('splitDateTime / joinDateTime', () => {
  it('разбирает дату из MySQL и собирает её обратно', () => {
    const parts = splitDateTime('2026-11-20 19:00:00.000000')

    expect(parts).toEqual({ date: '2026-11-20', time: '19:00' })
    expect(joinDateTime(parts.date, parts.time)).toBe('2026-11-20 19:00')
  })

  it('пустое время — это 00:00, а не «без времени»', () => {
    // Иначе `starts_at` уехал бы как «2026-11-20 » и сервер ответил бы 422.
    expect(joinDateTime('2026-11-20', '')).toBe('2026-11-20 00:00')
  })

  it('без даты времени не существует: null, а не строка', () => {
    expect(joinDateTime('', '19:00')).toBeNull()
    expect(splitDateTime(null)).toEqual({ date: '', time: '' })
  })
})

describe('buildSessionPayload', () => {
  it('собирает тело создания сеанса', () => {
    const payload = buildSessionPayload(readyForm())

    expect(payload).toMatchObject({
      event_id: 7,
      venue_id: 3,
      hall_id: 12,
      starts_at: '2026-11-20 19:00',
      status: 'scheduled',
    })
  })

  it('шлёт venue_id и hall_id ЧИСЛАМИ, а не строками', () => {
    // Правило `integer` пропускает numeric-строку, но сравнение пары
    // «площадка + зал» на сервере идёт по значениям колонок BIGINT, и строка
    // там ведёт себя не как число. Форма обязана отдавать числа.
    const payload = buildSessionPayload(readyForm())

    expect(payload.venue_id).toBe(3)
    expect(payload.hall_id).toBe(12)
    expect(typeof payload.venue_id).toBe('number')
  })

  it('НЕ отправляет schema_version_id, если схема не выбрана', () => {
    // Ключ должен ОТСУТСТВОВАТЬ, а не быть пустой строкой: `''` не проходит
    // правило `integer`, и карточка не сохранялась бы у того, кто оставил «Авто».
    const payload = buildSessionPayload(readyForm())

    expect('schema_version_id' in payload).toBe(false)
  })

  it('отправляет schema_version_id числом, когда схема выбрана', () => {
    const payload = buildSessionPayload(readyForm({ schema_version_id: '5' }))

    expect(payload.schema_version_id).toBe(5)
  })

  it('не отправляет event_id, когда мероприятие зафиксировано', () => {
    // Форма открыта из карточки мероприятия: менять событие нечем и незачем,
    // а лишний `event_id` в PATCH означал бы «перенеси сеанс на другое событие».
    const payload = buildSessionPayload(readyForm(), { includeEvent: false })

    expect('event_id' in payload).toBe(false)
    expect(payload.hall_id).toBe(12)
  })

  it('требует площадку — от неё зависит список залов', () => {
    expect(() => buildSessionPayload(readyForm({ venue_id: '' }))).toThrow(/площадку/i)
  })

  it('требует зал', () => {
    expect(() => buildSessionPayload(readyForm({ hall_id: '' }))).toThrow(/зал/i)
  })

  it('требует дату', () => {
    expect(() => buildSessionPayload(readyForm({ starts_at: '' }))).toThrow(/дату/i)
  })

  it('требует мероприятие только когда его можно выбрать', () => {
    expect(() => buildSessionPayload(readyForm({ event_id: '' }))).toThrow(/мероприятие/i)
    // Зафиксированное мероприятие — не ошибка, даже если в состоянии пусто.
    expect(() =>
      buildSessionPayload(readyForm({ event_id: '' }), { includeEvent: false }),
    ).not.toThrow()
  })

  it('для статуса «В продаже» требует старт продаж', () => {
    // `\w` в JS не матчит кириллицу, поэтому падеж перечисляем явно.
    expect(() => buildSessionPayload(readyForm({ status: 'on_sale' }))).toThrow(/старт(а)? продаж/i)

    const payload = buildSessionPayload(
      readyForm({ status: 'on_sale', sales_start_at: '2026-11-01', sales_start_time: '10:00' }),
    )

    expect(payload.sales_start_at).toBe('2026-11-01 10:00')
  })

  it('окно продаж без дат остаётся null, а не пустой строкой', () => {
    const payload = buildSessionPayload(readyForm())

    expect(payload.sales_start_at).toBeNull()
    expect(payload.sales_end_at).toBeNull()
  })
})
