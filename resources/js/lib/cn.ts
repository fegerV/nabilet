/** Склейка классов: без библиотек, потому что нужен ровно один приём. */
export function cn(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ')
}

/** Стабильный ключ для :key, когда сущность описывается парой полей. */
export function keyOf(...parts: Array<string | number>): string {
  return parts.join(':')
}
