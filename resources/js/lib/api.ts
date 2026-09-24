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
export async function get<D>(path: string, silent = false): Promise<{ data: D; meta?: PageMeta }> {
  return request<{ data: D; meta?: PageMeta }>(path, { silent })
}

/** POST/PUT/PATCH/DELETE → { data } */
export async function send<D>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', body?: unknown): Promise<{ data: D }> {
  return request<{ data: D }>(path, { method, body })
}

export const api = {
  get,
  send,
}