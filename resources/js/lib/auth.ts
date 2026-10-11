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

/** Полностью очистить сессию админки: и токен, и сохранённого пользователя. */
export function clearSession(): void {
  setToken(null)
  clearCurrentUser()
}

export function isAuthed(): boolean {
  return getToken() !== null
}

/**
 * Является ли текущая сессия админской.
 *
 * D2: раньше guard маршрутов смотрел только на наличие токена. `login()`
 * сохранял токен ДО проверки роли, и при отказе (support/клиент) токен
 * оставался в localStorage — клиентская оболочка открывалась, и пользователь
 * видел всю навигацию админки (данные при этом защищал серверный middleware,
 * но сам факт входа в чужой интерфейс — дефект). Теперь сессия админская,
 * только если токен есть И сохранённый пользователь имеет admin/manager.
 */
export function isAdminSession(): boolean {
  if (!getToken()) return false
  return canAdmin(currentUser()?.roles)
}

/** Сериализует ApiError в человеческое сообщение для формы логина. */
export function loginErrorMessage(e: unknown): string {
  const err = e as ApiError
  if (err?.status === 401) return 'Неверный email или пароль'
  if (err?.status === 403) return 'У вас нет прав на вход в админку'
  return 'Не удалось войти. Попробуйте ещё раз.'
}