/**
 * Авторизация админки.
 *
 * Токен — Sanctum (Bearer), хранится в localStorage (api.ts AUTH_TOKEN_KEY).
 * Роли приходят в ответе login: user.roles = ['admin'|'manager'|'support'].
 */
import { send, getToken, setToken, type ApiError } from './api'

export interface AuthUser {
  id: number
  email: string
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  roles: string[]
}

export interface LoginResponse {
  user: AuthUser
  token: string
  token_type: string
}

/** Может ли пользователь работать в админке (admin/manager). */
export function canAdmin(roles: string[] | undefined): boolean {
  return !!roles && (roles.includes('admin') || roles.includes('manager'))
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const res = await send<LoginResponse>('/auth/login', 'POST', { email, password })
  setToken(res.data.token)
  return res.data
}

export async function logout(): Promise<void> {
  try {
    await send<unknown>('/auth/logout', 'POST')
  } catch {
    /* токен всё равно стираем */
  }
  setToken(null)
}

/** Текущий пользователь из localStorage (без доп. запроса). */
export function currentUser(): AuthUser | null {
  try {
    const raw = localStorage.getItem('nabilet_admin_user')
    return raw ? (JSON.parse(raw) as AuthUser) : null
  } catch {
    return null
  }
}

export function saveCurrentUser(user: AuthUser): void {
  localStorage.setItem('nabilet_admin_user', JSON.stringify(user))
}

export function clearCurrentUser(): void {
  localStorage.removeItem('nabilet_admin_user')
}

export function isAuthed(): boolean {
  return getToken() !== null
}

/** Сериализует ApiError в человеческое сообщение для формы логина. */
export function loginErrorMessage(e: unknown): string {
  const err = e as ApiError
  if (err?.status === 401) return 'Неверный email или пароль'
  if (err?.status === 403) return 'У вас нет прав на вход в админку'
  return 'Не удалось войти. Попробуйте ещё раз.'
}