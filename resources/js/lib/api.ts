/**
 * Единый клиент к Laravel REST API.
 *
 * Контракт: все ресурсные ответы — { data, meta? } (см. контроллеры модулей).
 * Ошибки валидации приходят как { error: { code, message, errors? } }.
 * Токен доступа (админка) кладём в localStorage, шлём как Authorization: Bearer.
 *
 * Базу не хардкодим — берём из текущего origin (сборка живёт в Laravel),
 * но даём переопределить через VITE_API_BASE_URL для dev-режима.
 */

const BASE_URL: string = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1'

export class ApiError extends Error {
  readonly status: number
  readonly code: string | null
  readonly details: Record<string, string[]> | null

  constructor(status: number, message: string, code: string | null = null, details: Record<string, string[]> | null = null) {
    super(message)
    this.status = status
    this.code = code
    this.details = details
  }
}

export const AUTH_TOKEN_KEY = 'nabilet_admin_token'

export function getToken(): string | null {
  return localStorage.getItem(AUTH_TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(AUTH_TOKEN_KEY, token)
  else localStorage.removeItem(AUTH_TOKEN_KEY)
}

/* ── Гостевой токен корзины (контракт D5: X-Cart-Token) ───────────────────
 * Корзина на сервере ключуется парой (cart_token, session_id). Без заголовка
 * сервер считает каждый запрос нового покупателя «чужой» корзиной и либо
 * создаёт новую, либо отвечает 422. Поэтому токен генерируется один раз
 * на браузере, сохраняется в localStorage и шлётся с КАЖДЫМ запросом;
 * ответный заголовок X-Cart-Token обновляет его (например, после checkout,
 * когда сервер выдаёт свежий токен под следующую корзину).
 */
export const CART_TOKEN_KEY = 'nabilet_cart_token'

/** Сгенерировать UUID v4 (crypto.randomUUID с фолбэком на crypto.getRandomValues). */
function uuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  const bytes = new Uint8Array(16)
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes)
  } else {
    for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)
  }
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export function getCartToken(): string | null {
  try {
    return localStorage.getItem(CART_TOKEN_KEY)
  } catch {
    return null // приватный режим — токен живёт до перезагрузки
  }
}

export function ensureCartToken(): string {
  const existing = getCartToken()
  if (existing) return existing
  const fresh = uuid()
  try {
    localStorage.setItem(CART_TOKEN_KEY, fresh)
  } catch {
    /* ignore */
  }
  return fresh
}

export function setCartToken(token: string | null): void {
  try {
    if (token) localStorage.setItem(CART_TOKEN_KEY, token)
    else localStorage.removeItem(CART_TOKEN_KEY)
  } catch {
    /* ignore */
  }
}

interface ApiOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  /** Бросить ApiError вместо возврата — для «expected» 404 (например страница события по slug). */
  silent?: boolean
}

export interface PageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/** Универсальная выборка: возвращает объект { data, meta? } уже развёрнутым. */
export async function request<T = unknown>(path: string, options: ApiOptions = {}): Promise<T> {
  const token = getToken()
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
  if (token) headers.Authorization = `Bearer ${token}`
  // Контракт D5: все обращения к корзине — с гостевым токеном покупателя.
  // Для `/cart` токен ещё и СОЗДАЁТСЯ при необходимости: первый запрос нового
  // покупателя должен получить свою корзину, а не 422.
  //
  // Остальным маршрутам токен отдаём, только если он уже есть. Это нужно
  // `POST /payments`: сервер доказывает право на гостевой заказ именно этим
  // заголовком (`PaymentController::mayPay()` — у гостевого заказа
  // `user_id = NULL`, проверить владельца больше нечем), и без него оплата
  // отвечала 404 «чужой заказ». Плодить токены на запросах админки при этом
  // не нужно — поэтому `getCartToken()`, а не `ensureCartToken()`.
  if (path.startsWith('/cart')) {
    headers['X-Cart-Token'] = ensureCartToken()
  } else {
    const guestToken = getCartToken()
    if (guestToken) headers['X-Cart-Token'] = guestToken
  }

  let res: Response
  try {
    res = await fetch(`${BASE_URL}${path}`, {
      method: options.method ?? 'GET',
      headers: options.body === undefined ? headers : headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    })
  } catch {
    throw new ApiError(0, 'Нет соединения с сервером. Проверьте связь.')
  }

  // Сервер мог выдать новый токен (первый запрос без валидного) — сохраняем.
  const echoed = res.headers.get('X-Cart-Token')
  if (echoed && echoed !== getCartToken()) setCartToken(echoed)

  if (res.status === 204) return undefined as T

  let payload: unknown = null
  try {
    payload = await res.json()
  } catch {
    payload = null
  }

  if (!res.ok) {
    // 401 → протухла сессия админа; даём странице решить (редирект на логин).
    const msg =
      payload && typeof payload === 'object' && 'message' in payload
        ? String((payload as { message: unknown }).message)
        : `HTTP ${res.status}`
    const code =
      payload && typeof payload === 'object' && 'error' in payload
        ? String((payload as { error: { code?: unknown } }).error?.code ?? null)
        : null
    const details =
      payload && typeof payload === 'object' && 'error' in payload
        ? (payload as { error: { errors?: Record<string, string[]> } }).error?.errors ?? null
        : null
    if (res.status === 401 && code !== 'bad_credentials') setToken(null)
    throw new ApiError(res.status, msg, code, details)
  }

  return payload as T
}

/** GET /path → { data, meta? } */
export async function get<D, M extends PageMeta = PageMeta>(path: string, silent = false): Promise<{ data: D; meta?: M }> {
  return request<{ data: D; meta?: M }>(path, { silent })
}

/** POST/PUT/PATCH/DELETE → { data } */
export async function send<D>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown): Promise<{ data: D }> {
  return request<{ data: D }>(path, { method, body })
}

/**
 * Отправка FormData (multipart) — например, загрузка файла афиши на
 * POST /events с полем poster_file. Не сериализует тело как JSON и не ставит
 * Content-Type (браузер сам добавит boundary). Ошибки парсятся так же, как в
 * request(): ApiError с details.fields для привязки к конкретным инпутам.
 */
export async function upload<D>(path: string, formData: FormData, method: 'POST' | 'PATCH' = 'POST'): Promise<{ data: D }> {
  const token = getToken()
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  let res: Response
  try {
    res = await fetch(`${BASE_URL}${path}`, { method, headers, body: formData })
  } catch {
    throw new ApiError(0, 'Нет соединения с сервером. Проверьте связь.')
  }

  let payload: unknown = null
  try {
    payload = await res.json()
  } catch {
    payload = null
  }

  if (!res.ok) {
    const p = payload as Record<string, unknown> | null
    const msg =
      p && typeof p === 'object' && 'error' in p
        ? String((p.error as { message?: unknown }).message ?? `HTTP ${res.status}`)
        : p && typeof p === 'object' && 'message' in p
          ? String(p.message)
          : `HTTP ${res.status}`
    const code =
      p && typeof p === 'object' && 'error' in p
        ? String((p.error as { code?: unknown }).code ?? null)
        : null
    const details =
      p && typeof p === 'object' && 'error' in p
        ? ((p.error as { errors?: Record<string, string[]>; details?: { fields?: Record<string, string[]> } }).errors
            ?? (p.error as { details?: { fields?: Record<string, string[]> } }).details?.fields
            ?? null)
        : null
    if (res.status === 401 && code !== 'bad_credentials') setToken(null)
    throw new ApiError(res.status, msg, code, details)
  }

  return payload as { data: D }
}

export const api = {
  get,
  send,
  upload,
}