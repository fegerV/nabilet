/**
 * Форматирование — часть интерфейса, а не утилита «на потом».
 *
 * Деньги в проекте хранятся целыми minor units (ТЗ §3), поэтому форматтер
 * принимает копейки и никогда не работает с float. Русские склонения вынесены
 * сюда же: «3 билета», а не «3 билет(ов)» — это первое, что выдаёт машинный UI.
 */

export function money(minor: number, opts: { currency?: string; compact?: boolean } = {}): string {
  const { currency = '₽', compact = false } = opts
  const value = minor / 100

  if (compact && Math.abs(value) >= 1000) {
    const short = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(value / 1000)
    return `${short} тыс. ${currency}`
  }

  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value)} ${currency}`
}

/** Склонение: plural(3, 'билет', 'билета', 'билетов') → 'билета'. */
export function plural(n: number, one: string, few: string, many: string): string {
  const mod10 = n % 10
  const mod100 = n % 100
  if (mod10 === 1 && mod100 !== 11) return one
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few
  return many
}

export function seatsLabel(n: number): string {
  return `${n} ${plural(n, 'место', 'места', 'мест')}`
}

export function ticketsLabel(n: number): string {
  return `${n} ${plural(n, 'билет', 'билета', 'билетов')}`
}

const MONTHS_GEN = [
  'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
  'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
]
const WEEKDAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб']

export function dateLong(iso: string): string {
  const d = new Date(iso)
  return `${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`
}

export function dateFull(iso: string): string {
  const d = new Date(iso)
  return `${WEEKDAYS[d.getDay()]}, ${d.getDate()} ${MONTHS_GEN[d.getMonth()]}`
}

export function time(iso: string): string {
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** «через 9:41» — для таймера удержания места. */
export function countdown(secondsLeft: number): string {
  const s = Math.max(0, secondsLeft)
  const m = Math.floor(s / 60)
  const sec = s % 60
  return `${m}:${String(sec).padStart(2, '0')}`
}

/** Относительное время для админки: «12 минут назад». */
export function relative(iso: string, now: Date = new Date()): string {
  const diff = Math.round((now.getTime() - new Date(iso).getTime()) / 1000)
  if (diff < 60) return 'только что'
  if (diff < 3600) {
    const m = Math.round(diff / 60)
    return `${m} ${plural(m, 'минуту', 'минуты', 'минут')} назад`
  }
  if (diff < 86400) {
    const h = Math.round(diff / 3600)
    return `${h} ${plural(h, 'час', 'часа', 'часов')} назад`
  }
  const d = Math.round(diff / 86400)
  return `${d} ${plural(d, 'день', 'дня', 'дней')} назад`
}
