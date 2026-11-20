/**
 * Транспорт для денечного контура встраиваемого виджета.
 *
 * Публичные витринные ручки работают без токена, а `/api/v1/embed/*` требуют
 * `embed_token` в query и capability-токен корзины. Всё это одинаково для
 * каждого вызова, поэтому добавляется централизованно, а не в каждом сервисе.
 *
 * Обычная витрина эти функции НЕ вызывает: `isEmbedMode()` истинно только
 * когда в адресе есть embed_token.
 */
import { request } from '@/lib/api'
import { getEmbedToken, isEmbedMode } from '@/lib/embed-token'

export { isEmbedMode }

interface EmbedOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  headers?: Record<string, string>
}

/** Запрос к embed API с токеном из адреса рамки. */
export function embedRequest<T>(path: string, options: EmbedOptions = {}): Promise<T> {
  const token = getEmbedToken()
  return request<T>(`/embed${path}`, {
    ...options,
    query: token ? { embed_token: token } : undefined,
  })
}

export function embedGet<T>(path: string): Promise<{ data: T; meta?: unknown }> {
  return embedRequest(path)
}

export function embedSend<T>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown, headers?: Record<string, string>): Promise<{ data: T }> {
  return embedRequest(path, { method, body, headers })
}

/** Заголовок идемпотентности для embed-платежа. */
export function idempotencyHeaders(key: string): Record<string, string> {
  return { 'Idempotency-Key': key }
}
