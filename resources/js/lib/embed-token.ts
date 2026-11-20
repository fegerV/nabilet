/**
 * Чтение embed-токена из адреса рамки.
 *
 * Генератор кода встраивания (`lib/embed-code.ts`) кладёт токен в query:
 * `/event/{slug}?embed_token=…`. Исторические ссылки могли нести его в hash
 * (`#/?embed_token=…`), поэтому разбираются оба источника — тот же подход,
 * что в `lib/metrika.ts` для UTM-меток.
 *
 * Утилита не зависит от vue-router: композаблы покупки намеренно не работают
 * с маршрутизатором, чтобы виджет можно было смонтировать где угодно.
 */
const TOKEN_PARAM = 'embed_token'

function fromSearch(search: string): string {
  const raw = new URLSearchParams(search).get(TOKEN_PARAM)
  return raw ? raw.trim() : ''
}

/** Открытый embed-токен или пустая строка (обычная витрина). */
export function getEmbedToken(): string {
  if (typeof window === 'undefined') return ''

  const query = fromSearch(window.location.search)
  if (query) return query

  // Legacy hash-роутинг: `#/event/x?embed_token=…` или `#/?embed_token=…`.
  const hash = window.location.hash ?? ''
  const mark = hash.indexOf('?')
  return mark >= 0 ? fromSearch(hash.slice(mark + 1)) : ''
}

/** true, когда текущая страница opened as an embed widget. */
export function isEmbedMode(): boolean {
  return getEmbedToken() !== ''
}
