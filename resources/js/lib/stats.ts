/**
 * «Выручка по дням» — одно правило бакетирования для графиков админки.
 *
 * ЗАЧЕМ. `AdminAnalyticsPage` и `AdminDashboardPage` считали одно и то же
 * (выручка по суткам за последние N дней) ДВУМЯ копиями, и копии разошлись:
 * дашборд резал сутки по ЛОКАЛЬНЫМ границам (`setHours(0,0,0,0)` + сравнение
 * `getTime()`), а аналитика — по UTC-дате `toISOString().slice(0, 10)`.
 *
 * ПОЧЕМУ ЭТО БЫЛ БАГ, А НЕ СТИЛЬ. Для браузера восточнее UTC (все российские)
 * локальная полночь уезжает на предыдущие сутки в UTC: 28 октября 00:00 +05:00
 * — это 27 октября 19:00 UTC, то есть ключ бакета «2026-10-27». Заказ, сделанный
 * днём 28-го, даёт ключ «2026-10-28» и не находит СВОЕГО бакета: он либо
 * пропадает из графика (если это последние сутки окна), либо встаёт на
 * сегодняшний столбик вчерашним. На машине разработчика в UTC расхождения не
 * видно вообще — поэтому баг и дожил до аудита.
 *
 * Поэтому ключ суток берётся из ЛОКАЛЬНЫХ компонент даты, а не из ISO-строки.
 */

export interface DayBucket {
  /** Локальная дата `YYYY-MM-DD` — ключ бакета и подпись дня на оси. */
  date: string
  /** Подпись для оси (обычно `dateLong`). */
  label: string
  /** Сумма в minor units. */
  total: number
  /** Сколько строк попало в сутки. */
  count: number
}

/**
 * `YYYY-MM-DD` по ЛОКАЛЬНЫМ компонентам.
 *
 * НЕ `toISOString().slice(0, 10)`: ISO всегда в UTC, и для не-UTC браузера ключ
 * уезжает на сутки (см. заголовок). Именно эта замена и чинит график аналитики.
 */
export function localDateKey(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

export interface RevenueByDayOptions<T> {
  /** Сколько суток показать, включая сегодня. */
  days: number
  /**
   * Какую дату строки считать. Вызывающий решает сам:
   * аналитика — `paid_at ?? created_at`, дашборд — `created_at`.
   * Правило бакетирования при этом одно.
   */
  timestamp: (row: T) => string | null | undefined
  /** Сумма строки в minor units. */
  amount: (row: T) => number
  /** Подпись оси; передаём `dateLong`, чтобы не тянуть локализацию сюда. */
  label: (iso: string) => string
}

/**
 * Выручка по суткам за последние `days` дней (включая сегодня).
 *
 * Бакетирование — по ЛОКАЛЬНОМУ дню. Строки вне окна (старше `days` дней или
 * из будущего за пределами окна) отбрасываются: их дата не совпадает ни с одним
 * ключом. Пустые/битые даты — тоже.
 */
export function revenueByDay<T>(rows: readonly T[], opts: RevenueByDayOptions<T>): DayBucket[] {
  const { days, timestamp, amount, label } = opts

  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const buckets: DayBucket[] = []
  for (let i = days - 1; i >= 0; i -= 1) {
    const day = new Date(today)
    day.setDate(day.getDate() - i)
    // `label` принимает ISO-строку (`dateLong`), но читает из неё ЛОКАЛЬНЫЕ
    // компоненты — то есть подпись соответствует локальному дню `day`.
    buckets.push({ date: localDateKey(day), label: label(day.toISOString()), total: 0, count: 0 })
  }

  const byDate = new Map(buckets.map((b) => [b.date, b]))

  for (const row of rows) {
    const raw = timestamp(row)
    if (!raw) continue

    const at = new Date(raw)
    if (Number.isNaN(at.getTime())) continue

    const bucket = byDate.get(localDateKey(at))
    if (!bucket) continue

    bucket.total += amount(row)
    bucket.count += 1
  }

  return buckets
}
